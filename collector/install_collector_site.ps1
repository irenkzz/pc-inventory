[CmdletBinding()]
param(
    [string]$CollectorRoot = '',
    [string]$ConfigPath = '',
    [string]$InstallRoot = 'C:\ProgramData\InternalInventoryCollector',
    [string]$TaskName = 'InternalInventoryCollectorRelay',
    [int]$HealthTimeoutSeconds = 20,
    [switch]$RunAsCurrentUser = $true
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$script:Result = 'FAIL'
$script:WarnCount = 0
$script:LogPath = ''

function New-InstallerLogPath {
    param([string]$InstallRoot)

    $timestamp = (Get-Date).ToString('yyyyMMdd-HHmmss')
    $programDataLogDir = Join-Path $InstallRoot 'logs'

    try {
        if (Test-Path $InstallRoot) {
            if (-not (Test-Path $programDataLogDir)) {
                New-Item -ItemType Directory -Path $programDataLogDir -Force | Out-Null
            }

            return Join-Path $programDataLogDir "installer-collector-site-$timestamp.log"
        }
    }
    catch {
        # Fall back to TEMP below.
    }

    return Join-Path $env:TEMP "InternalInventoryCollector-install-$timestamp.log"
}

function Write-InstallerLine {
    param(
        [string]$Prefix,
        [string]$Message
    )

    if ($Prefix -eq 'WARN') {
        $script:WarnCount++
    }

    $line = if ($Prefix) { "[$Prefix] $Message" } else { $Message }
    Write-Host $line

    if ($script:LogPath) {
        Add-Content -Path $script:LogPath -Value $line -Encoding UTF8
    }
}

function Test-Admin {
    $identity = [Security.Principal.WindowsIdentity]::GetCurrent()
    $principal = New-Object Security.Principal.WindowsPrincipal($identity)
    return $principal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)
}

function Read-JsonFile {
    param([string]$Path)

    if (-not (Test-Path $Path)) {
        throw "Collector config not found: $Path"
    }

    try {
        return Get-Content -Path $Path -Raw -Encoding UTF8 | ConvertFrom-Json
    }
    catch {
        throw "Could not read collector config JSON: $Path"
    }
}

function Get-JsonValue {
    param(
        [object]$Object,
        [string[]]$Names,
        [string]$Default = ''
    )

    if (-not $Object) { return $Default }

    foreach ($name in $Names) {
        if ($Object.PSObject.Properties.Name -contains $name) {
            $value = $Object.$name
            if ($null -ne $value -and -not [string]::IsNullOrWhiteSpace([string]$value)) {
                return [string]$value
            }
        }
    }

    return $Default
}

function Resolve-CollectorRoot {
    param([string]$CollectorRoot)

    if (-not [string]::IsNullOrWhiteSpace($CollectorRoot)) {
        return (Resolve-Path -Path $CollectorRoot).Path
    }

    return (Resolve-Path -Path $PSScriptRoot).Path
}

function Resolve-CollectorConfigPath {
    param(
        [string]$ConfigPath,
        [string]$CollectorRoot
    )

    if (-not [string]::IsNullOrWhiteSpace($ConfigPath)) {
        return (Resolve-Path -Path $ConfigPath).Path
    }

    $candidate = Join-Path $CollectorRoot 'collector_config.json'
    if (Test-Path $candidate) {
        return (Resolve-Path -Path $candidate).Path
    }

    throw "Collector config was not found under $CollectorRoot"
}

function Test-PlaceholderServerUrl {
    param([Uri]$Uri)

    $hostName = $Uri.Host.ToLowerInvariant()
    if ($hostName -eq 'localhost') { return $true }
    if ($hostName -eq '127.0.0.1') { return $true }
    if ($hostName -eq '::1') { return $true }
    if ($hostName -eq 'inventory.example.local') { return $true }
    if ($hostName -like '*example*') { return $true }
    if ($hostName -like '*placeholder*') { return $true }

    return $false
}

function Test-CollectorConfig {
    param([object]$Config)

    $runnerTransportMode = Get-JsonValue -Object $Config -Names @('transport_mode')
    if ($runnerTransportMode.Trim().ToLowerInvariant() -eq 'direct_https') {
        throw 'Direct HTTPS runner config refused. Use the Direct HTTPS runner installer for that package.'
    }

    $siteId = Get-JsonValue -Object $Config -Names @('site_id', 'siteId')
    $collectorName = Get-JsonValue -Object $Config -Names @('collector_name', 'collectorName')
    if ([string]::IsNullOrWhiteSpace($siteId)) {
        throw 'Collector site identity is missing: site_id is required.'
    }
    if ([string]::IsNullOrWhiteSpace($collectorName)) {
        throw 'Collector site identity is missing: collector_name is required.'
    }

    $serverBaseUrl = Get-JsonValue -Object $Config -Names @('server_base_url', 'serverBaseUrl')
    if ([string]::IsNullOrWhiteSpace($serverBaseUrl)) {
        throw 'serverBaseUrl is missing or blank.'
    }

    try {
        $serverUri = [Uri]$serverBaseUrl
    }
    catch {
        throw 'serverBaseUrl is not a valid URL.'
    }

    if ($serverUri.Scheme -ne 'https') {
        throw 'serverBaseUrl must use https://. Plain http:// is refused.'
    }

    if (Test-PlaceholderServerUrl -Uri $serverUri) {
        throw 'serverBaseUrl points to localhost, loopback, example, or placeholder host.'
    }

    $shareRoot = Get-JsonValue -Object $Config -Names @('share_root', 'sharedRoot')
    if ([string]::IsNullOrWhiteSpace($shareRoot)) {
        throw 'sharedRoot/share_root is missing or blank.'
    }

    return @{
        SiteId = $siteId.Trim()
        CollectorName = $collectorName.Trim()
        ServerBaseUrl = $serverUri.GetLeftPart([UriPartial]::Authority) + $serverUri.AbsolutePath.TrimEnd('/')
        ShareRoot = $shareRoot.Trim()
        PollIntervalMinutes = Get-JsonValue -Object $Config -Names @('poll_interval_minutes', 'collectorPollIntervalMinutes') -Default '5'
    }
}

function Invoke-HealthCheck {
    param(
        [string]$ServerBaseUrl,
        [int]$TimeoutSeconds
    )

    $healthUrl = $ServerBaseUrl.TrimEnd('/') + '/health'
    $response = Invoke-WebRequest -Uri $healthUrl -UseBasicParsing -TimeoutSec $TimeoutSeconds
    $statusCode = [int]$response.StatusCode
    if ($statusCode -lt 200 -or $statusCode -ge 300) {
        throw "HTTPS health check returned HTTP $statusCode."
    }

    return "HTTP $statusCode"
}

function Test-ShareReadWrite {
    param([string]$ShareRoot)

    if (-not (Test-Path $ShareRoot)) {
        throw "sharedRoot is missing or unreachable: $ShareRoot"
    }

    $probeName = '.collector-site-install-probe-' + ([guid]::NewGuid().ToString('N')) + '.tmp'
    $probePath = Join-Path $ShareRoot $probeName

    try {
        Set-Content -Path $probePath -Value 'collector-site-install-probe' -Encoding ASCII -NoNewline
        $probeText = Get-Content -Path $probePath -Raw -Encoding ASCII
        if ($probeText -ne 'collector-site-install-probe') {
            throw 'probe readback mismatch'
        }
    }
    finally {
        if (Test-Path $probePath) {
            Remove-Item -Path $probePath -Force
        }
    }
}

function Test-RunnerStaging {
    param(
        [string]$KitRoot,
        [string]$ShareRoot
    )

    $localRunnerPackage = Join-Path $KitRoot 'branch-share\packages\runner\current'
    $shareRunnerPackage = Join-Path $ShareRoot 'packages\runner\current'

    if (Test-Path (Join-Path $localRunnerPackage 'scripts\install_runner.ps1')) {
        return 'present in generated package'
    }

    if (Test-Path (Join-Path $shareRunnerPackage 'scripts\install_runner.ps1')) {
        return 'present on branch share'
    }

    return ''
}

function Backup-ExistingConfig {
    param([string]$ConfigPath)

    if (-not (Test-Path $ConfigPath)) {
        return ''
    }

    $backupPath = $ConfigPath + '.bak-' + (Get-Date).ToString('yyyyMMdd-HHmmss')
    Copy-Item -Path $ConfigPath -Destination $backupPath -Force
    return $backupPath
}

function Test-ScheduledTaskExists {
    param([string]$TaskName)

    $task = Get-ScheduledTask -TaskName $TaskName -ErrorAction SilentlyContinue
    if ($task) {
        return $true
    }

    & schtasks.exe /Query /TN $TaskName | Out-Null
    return $LASTEXITCODE -eq 0
}

function Invoke-CollectorInstaller {
    param(
        [string]$InstallerPath,
        [string]$CollectorRoot,
        [string]$ConfigPath,
        [string]$TaskName,
        [string]$PollIntervalMinutes,
        [switch]$RunAsCurrentUser
    )

    $args = @(
        '-NoProfile',
        '-ExecutionPolicy', 'Bypass',
        '-File', $InstallerPath,
        '-CollectorRoot', $CollectorRoot,
        '-ConfigPath', $ConfigPath,
        '-TaskName', $TaskName,
        '-PollIntervalMinutes', $PollIntervalMinutes
    )

    if ($RunAsCurrentUser) {
        $args += '-RunAsCurrentUser'
    }

    $output = & powershell @args 2>&1
    $outputText = ($output | ForEach-Object { [string]$_ }) -join "`n"
    foreach ($line in $output) {
        Write-InstallerLine 'INFO' ([string]$line)
    }

    if ($LASTEXITCODE -ne 0) {
        if ($outputText -like '*Python runtime was not found*') {
            throw 'Python runtime was not found. Collector-share mode requires Python 3.x on the collector host for this MVP. Install Python 3.x with pythonw/python in PATH, or use Direct HTTPS mode for small/no-IT sites.'
        }

        throw "install_collector.ps1 failed with exit code $LASTEXITCODE."
    }
}

try {
    $script:LogPath = New-InstallerLogPath -InstallRoot $InstallRoot
    '' | Set-Content -Path $script:LogPath -Encoding UTF8

    Write-InstallerLine 'INFO' 'Collector-site installer started.'
    Write-InstallerLine 'INFO' "Log path: $script:LogPath"

    if (-not (Test-Admin)) {
        throw 'Administrator PowerShell is required for the collector-site installer MVP.'
    }
    Write-InstallerLine 'OK' 'Administrator PowerShell detected.'

    $resolvedCollectorRoot = Resolve-CollectorRoot -CollectorRoot $CollectorRoot
    $resolvedConfigPath = Resolve-CollectorConfigPath -ConfigPath $ConfigPath -CollectorRoot $resolvedCollectorRoot
    $installerPath = Join-Path $resolvedCollectorRoot 'install_collector.ps1'
    if (-not (Test-Path $installerPath)) {
        throw "Existing collector installer not found: $installerPath"
    }

    $config = Read-JsonFile -Path $resolvedConfigPath
    $validation = Test-CollectorConfig -Config $config
    Write-InstallerLine 'OK' "Collector config accepted for siteId: $($validation.SiteId)"
    Write-InstallerLine 'OK' "collectorName accepted: $($validation.CollectorName)"
    Write-InstallerLine 'OK' "serverBaseUrl accepted: $($validation.ServerBaseUrl)"

    $healthResult = Invoke-HealthCheck -ServerBaseUrl $validation.ServerBaseUrl -TimeoutSeconds $HealthTimeoutSeconds
    Write-InstallerLine 'OK' "HTTPS health check passed: $healthResult"

    Test-ShareReadWrite -ShareRoot $validation.ShareRoot
    Write-InstallerLine 'OK' 'sharedRoot reachable and read/write probe succeeded.'

    $kitRoot = Resolve-Path -Path (Join-Path $resolvedCollectorRoot '..')
    $runnerStaging = Test-RunnerStaging -KitRoot $kitRoot.Path -ShareRoot $validation.ShareRoot
    if ([string]::IsNullOrWhiteSpace($runnerStaging)) {
        Write-InstallerLine 'WARN' 'Runner staging area was not found in the generated package or branch share.'
    } else {
        Write-InstallerLine 'OK' "Runner staging area present: $runnerStaging"
    }

    $backupPath = Backup-ExistingConfig -ConfigPath $resolvedConfigPath
    if ($backupPath) {
        Write-InstallerLine 'OK' "Existing collector config backed up: $backupPath"
    }

    Invoke-CollectorInstaller `
        -InstallerPath $installerPath `
        -CollectorRoot $resolvedCollectorRoot `
        -ConfigPath $resolvedConfigPath `
        -TaskName $TaskName `
        -PollIntervalMinutes $validation.PollIntervalMinutes `
        -RunAsCurrentUser:$RunAsCurrentUser

    if (-not (Test-Path $resolvedConfigPath)) {
        throw "Installed collector config missing after install: $resolvedConfigPath"
    }
    Write-InstallerLine 'OK' 'Installed collector config exists.'

    if (-not (Test-ScheduledTaskExists -TaskName $TaskName)) {
        throw "Collector Scheduled Task missing after install: $TaskName"
    }
    Write-InstallerLine 'OK' "Collector Scheduled Task present: $TaskName"

    Write-InstallerLine 'INFO' 'Redacted support summary:'
    Write-InstallerLine 'INFO' "siteId: $($validation.SiteId)"
    Write-InstallerLine 'INFO' "collectorName: $($validation.CollectorName)"
    Write-InstallerLine 'INFO' "serverBaseUrl: $($validation.ServerBaseUrl)"
    Write-InstallerLine 'INFO' 'sharedRoot: reachable read/write'
    Write-InstallerLine 'INFO' "collectorTask: present"
    Write-InstallerLine 'INFO' "runnerStaging: $(if ($runnerStaging) { $runnerStaging } else { 'missing' })"
    Write-InstallerLine 'INFO' "httpsHealth: $healthResult"
    Write-InstallerLine 'INFO' "logPath: $script:LogPath"
    Write-InstallerLine 'INFO' 'Portal verification: wait for the normal collector cycle, then check /collectors for collector status and /runners for collector-share runner visibility.'

    $script:Result = if ($script:WarnCount -gt 0) { 'WARN' } else { 'PASS' }
}
catch {
    Write-InstallerLine 'FAIL' $_.Exception.Message
    Write-InstallerLine 'INFO' 'If installation failed after backup, restore the previous config by copying the .bak file back to the collector_config.json path shown in the backup message.'
    $script:Result = 'FAIL'
}
finally {
    Write-InstallerLine '' "Result: $script:Result"
}

if ($script:Result -eq 'FAIL') {
    exit 1
}

exit 0
