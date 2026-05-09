[CmdletBinding()]
param(
    [string]$ConfigPath = '',
    [string]$SourceRoot = '',
    [string]$InstallRoot = 'C:\ProgramData\InternalInventoryRunner',
    [string]$TaskName = '',
    [string]$RunnerId = '',
    [switch]$UseComputerNameAsRunnerId,
    [int]$HealthTimeoutSeconds = 20,
    [switch]$RunAsCurrentUser = $true
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$script:Result = 'FAIL'
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

            return Join-Path $programDataLogDir "installer-direct-https-$timestamp.log"
        }
    }
    catch {
        # Fall back to TEMP below.
    }

    return Join-Path $env:TEMP "InternalInventoryRunner-install-direct-https-$timestamp.log"
}

function Write-InstallerLine {
    param(
        [string]$Prefix,
        [string]$Message
    )

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
        throw "JSON file not found: $Path"
    }

    try {
        return Get-Content -Path $Path -Raw -Encoding UTF8 | ConvertFrom-Json
    }
    catch {
        throw "Could not read JSON file: $Path"
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

function Set-JsonValue {
    param(
        [object]$Object,
        [string]$Name,
        [object]$Value
    )

    if ($Object.PSObject.Properties.Name -contains $Name) {
        $Object.$Name = $Value
    } else {
        $Object | Add-Member -NotePropertyName $Name -NotePropertyValue $Value
    }
}

function ConvertTo-RedactedGuidSummary {
    param([string]$Guid)

    if ([string]::IsNullOrWhiteSpace($Guid)) {
        return 'missing'
    }

    $trimmed = $Guid.Trim()
    if ($trimmed.Length -le 4) {
        return 'present ending ****'
    }

    return 'present ending ' + $trimmed.Substring($trimmed.Length - 4)
}

function Resolve-SourceRoot {
    param([string]$SourceRoot)

    if (-not [string]::IsNullOrWhiteSpace($SourceRoot)) {
        return (Resolve-Path -Path $SourceRoot).Path
    }

    return (Resolve-Path -Path (Join-Path $PSScriptRoot '..')).Path
}

function Resolve-ConfigPath {
    param(
        [string]$ConfigPath,
        [string]$SourceRoot
    )

    if (-not [string]::IsNullOrWhiteSpace($ConfigPath)) {
        return (Resolve-Path -Path $ConfigPath).Path
    }

    $templatePath = Join-Path $SourceRoot 'config\runner-config.template.json'
    if (Test-Path $templatePath) {
        return (Resolve-Path -Path $templatePath).Path
    }

    $installedStylePath = Join-Path $SourceRoot 'config\runner-config.json'
    if (Test-Path $installedStylePath) {
        return (Resolve-Path -Path $installedStylePath).Path
    }

    throw "Direct HTTPS runner config was not found under $SourceRoot\config"
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

function Test-DirectConfig {
    param([object]$Config)

    $transportMode = Get-JsonValue -Object $Config -Names @('transport_mode')
    if ([string]::IsNullOrWhiteSpace($transportMode)) {
        throw 'transport_mode is missing or blank.'
    }

    $transportMode = $transportMode.Trim().ToLowerInvariant()
    if ($transportMode -eq 'collector_share') {
        throw 'collector_share config refused. Use the collector-share installer path for that package.'
    }

    if ($transportMode -ne 'direct_https') {
        throw "unknown transport_mode refused: $transportMode"
    }

    $serverBaseUrl = Get-JsonValue -Object $Config -Names @('serverBaseUrl', 'server_base_url')
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

    return @{
        TransportMode = $transportMode
        ServerBaseUrl = $serverUri.GetLeftPart([UriPartial]::Authority) + $serverUri.AbsolutePath.TrimEnd('/')
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

function Backup-ExistingConfig {
    param([string]$ExistingConfigPath)

    if (-not (Test-Path $ExistingConfigPath)) {
        return ''
    }

    $backupPath = $ExistingConfigPath + '.bak-' + (Get-Date).ToString('yyyyMMdd-HHmmss')
    Copy-Item -Path $ExistingConfigPath -Destination $backupPath -Force
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

function Resolve-RunnerId {
    param(
        [string]$InputRunnerId,
        [object]$Config,
        [switch]$UseComputerNameAsRunnerId
    )

    if (-not [string]::IsNullOrWhiteSpace($InputRunnerId)) {
        return $InputRunnerId.Trim()
    }

    $configRunnerId = Get-JsonValue -Object $Config -Names @('runnerId', 'runner_id')
    if (-not [string]::IsNullOrWhiteSpace($configRunnerId) -and $configRunnerId -ne '<SET-PER-DEVICE>') {
        return $configRunnerId.Trim()
    }

    if ($UseComputerNameAsRunnerId) {
        return $env:COMPUTERNAME
    }

    $entered = Read-Host 'Runner ID for this PC. Press Enter to use computer name'
    if ([string]::IsNullOrWhiteSpace($entered)) {
        return $env:COMPUTERNAME
    }

    return $entered.Trim()
}

function Invoke-RunnerInstaller {
    param(
        [string]$InstallerPath,
        [string]$SourceRoot,
        [object]$Config,
        [string]$ResolvedRunnerId,
        [string]$InstallRoot,
        [string]$TaskName,
        [switch]$RunAsCurrentUser
    )

    $siteId = Get-JsonValue -Object $Config -Names @('siteId', 'site_id')
    $siteName = Get-JsonValue -Object $Config -Names @('siteName', 'site_name')
    $collectorName = Get-JsonValue -Object $Config -Names @('collectorName', 'collector_name') -Default 'direct-https'
    $location = Get-JsonValue -Object $Config -Names @('location') -Default $siteName
    $room = Get-JsonValue -Object $Config -Names @('room') -Default 'General'
    $runnerVersion = Get-JsonValue -Object $Config -Names @('runnerVersion', 'runner_version') -Default '1.0.0'
    $scanInterval = Get-JsonValue -Object $Config -Names @('scanIntervalMinutes', 'scan_interval_minutes') -Default '240'
    $pollInterval = Get-JsonValue -Object $Config -Names @('runnerPollIntervalMinutes', 'runner_poll_interval_minutes') -Default '5'
    $taskRandomDelay = Get-JsonValue -Object $Config -Names @('taskRandomDelayMinutes', 'task_random_delay_minutes') -Default '15'

    $args = @(
        '-NoProfile',
        '-ExecutionPolicy', 'Bypass',
        '-File', $InstallerPath,
        '-SourceRoot', $SourceRoot,
        '-SharedRoot', (Join-Path $InstallRoot 'direct-share-unused'),
        '-RunnerId', $ResolvedRunnerId,
        '-SiteId', $siteId,
        '-SiteName', $siteName,
        '-CollectorName', $collectorName,
        '-Location', $location,
        '-Room', $room,
        '-RunnerVersion', $runnerVersion,
        '-TaskName', $TaskName,
        '-ScanIntervalMinutes', $scanInterval,
        '-PollIntervalMinutes', $pollInterval,
        '-TaskRandomDelayMinutes', $taskRandomDelay
    )

    if ($RunAsCurrentUser) {
        $args += '-RunAsCurrentUser'
    }

    $output = & powershell @args 2>&1
    foreach ($line in $output) {
        Write-InstallerLine 'INFO' ([string]$line)
    }

    if ($LASTEXITCODE -ne 0) {
        throw "install_runner.ps1 failed with exit code $LASTEXITCODE."
    }
}

try {
    $script:LogPath = New-InstallerLogPath -InstallRoot $InstallRoot
    '' | Set-Content -Path $script:LogPath -Encoding UTF8

    Write-InstallerLine 'INFO' 'Direct HTTPS runner installer started.'
    Write-InstallerLine 'INFO' "Log path: $script:LogPath"

    if (-not (Test-Admin)) {
        throw 'Administrator PowerShell is required for the Direct HTTPS runner installer MVP.'
    }
    Write-InstallerLine 'OK' 'Administrator PowerShell detected.'

    $resolvedSourceRoot = Resolve-SourceRoot -SourceRoot $SourceRoot
    $resolvedConfigPath = Resolve-ConfigPath -ConfigPath $ConfigPath -SourceRoot $resolvedSourceRoot
    $installerPath = Join-Path $resolvedSourceRoot 'scripts\install_runner.ps1'
    if (-not (Test-Path $installerPath)) {
        throw "Existing runner installer not found: $installerPath"
    }

    $config = Read-JsonFile -Path $resolvedConfigPath
    $validation = Test-DirectConfig -Config $config
    Write-InstallerLine 'OK' 'Config transport_mode is direct_https.'
    Write-InstallerLine 'OK' "serverBaseUrl accepted: $($validation.ServerBaseUrl)"

    $healthResult = Invoke-HealthCheck -ServerBaseUrl $validation.ServerBaseUrl -TimeoutSeconds $HealthTimeoutSeconds
    Write-InstallerLine 'OK' "HTTPS health check passed: $healthResult"

    $existingConfigPath = Join-Path $InstallRoot 'config\runner-config.json'
    $existingConfig = if (Test-Path $existingConfigPath) { Read-JsonFile -Path $existingConfigPath } else { $null }
    $existingRunnerGuid = Get-JsonValue -Object $existingConfig -Names @('runnerGuid', 'runner_guid')
    $backupPath = Backup-ExistingConfig -ExistingConfigPath $existingConfigPath
    if ($backupPath) {
        Write-InstallerLine 'OK' "Existing installed config backed up: $backupPath"
        Write-InstallerLine 'INFO' ('Existing runnerGuid: ' + (ConvertTo-RedactedGuidSummary -Guid $existingRunnerGuid))
    } else {
        Write-InstallerLine 'INFO' 'No existing installed config found.'
    }

    $resolvedTaskName = if (-not [string]::IsNullOrWhiteSpace($TaskName)) {
        $TaskName
    } else {
        Get-JsonValue -Object $config -Names @('scheduledTaskName', 'task_name') -Default 'InternalInventoryRunner'
    }
    $resolvedRunnerId = Resolve-RunnerId -InputRunnerId $RunnerId -Config $config -UseComputerNameAsRunnerId:$UseComputerNameAsRunnerId

    Write-InstallerLine 'INFO' "Installing runnerId: $resolvedRunnerId"
    Invoke-RunnerInstaller `
        -InstallerPath $installerPath `
        -SourceRoot $resolvedSourceRoot `
        -Config $config `
        -ResolvedRunnerId $resolvedRunnerId `
        -InstallRoot $InstallRoot `
        -TaskName $resolvedTaskName `
        -RunAsCurrentUser:$RunAsCurrentUser

    if (-not (Test-Path $existingConfigPath)) {
        throw "Installed config not found after install: $existingConfigPath"
    }

    $installedConfig = Read-JsonFile -Path $existingConfigPath
    $installedRunnerGuid = Get-JsonValue -Object $installedConfig -Names @('runnerGuid', 'runner_guid')
    if (-not [string]::IsNullOrWhiteSpace($existingRunnerGuid)) {
        Set-JsonValue -Object $installedConfig -Name 'runnerGuid' -Value $existingRunnerGuid
        $installedRunnerGuid = $existingRunnerGuid
    }

    Set-JsonValue -Object $installedConfig -Name 'transport_mode' -Value 'direct_https'
    Set-JsonValue -Object $installedConfig -Name 'serverBaseUrl' -Value $validation.ServerBaseUrl
    Set-JsonValue -Object $installedConfig -Name 'siteToken' -Value (Get-JsonValue -Object $config -Names @('siteToken', 'site_token'))
    Set-JsonValue -Object $installedConfig -Name 'sharedRoot' -Value ''
    $installedConfig | ConvertTo-Json -Depth 8 | Set-Content -Path $existingConfigPath -Encoding UTF8

    $verifiedConfig = Read-JsonFile -Path $existingConfigPath
    $verifiedTransportMode = (Get-JsonValue -Object $verifiedConfig -Names @('transport_mode')).Trim().ToLowerInvariant()
    if ($verifiedTransportMode -ne 'direct_https') {
        throw 'Installed config validation failed: transport_mode is not direct_https.'
    }
    Write-InstallerLine 'OK' 'Installed config exists and remains direct_https.'

    if (-not (Test-ScheduledTaskExists -TaskName $resolvedTaskName)) {
        throw "Scheduled Task missing after install: $resolvedTaskName"
    }
    Write-InstallerLine 'OK' "Scheduled Task present: $resolvedTaskName"

    $runnerVersion = Get-JsonValue -Object $verifiedConfig -Names @('runnerVersion', 'runner_version')
    Write-InstallerLine 'INFO' 'Redacted support summary:'
    Write-InstallerLine 'INFO' "runnerId: $resolvedRunnerId"
    Write-InstallerLine 'INFO' 'transport_mode: direct_https'
    Write-InstallerLine 'INFO' "runnerVersion: $runnerVersion"
    Write-InstallerLine 'INFO' "serverBaseUrl: $($validation.ServerBaseUrl)"
    Write-InstallerLine 'INFO' ('runnerGuid: ' + (ConvertTo-RedactedGuidSummary -Guid $installedRunnerGuid))
    Write-InstallerLine 'INFO' "scheduledTask: present"
    Write-InstallerLine 'INFO' "httpsHealth: $healthResult"
    Write-InstallerLine 'INFO' "logPath: $script:LogPath"
    Write-InstallerLine 'INFO' 'Portal verification: wait for the normal runner cycle, then check /runners for the Direct HTTPS runner, heartbeat, direct poll time, upload/Last Inventory, and run inventory:direct-runner-triage for the runnerId if needed.'

    $script:Result = 'PASS'
}
catch {
    Write-InstallerLine 'FAIL' $_.Exception.Message
    Write-InstallerLine 'INFO' 'If installation failed after backup, restore the previous config by copying the .bak file back to C:\ProgramData\InternalInventoryRunner\config\runner-config.json.'
    $script:Result = 'FAIL'
}
finally {
    Write-InstallerLine '' "Result: $script:Result"
}

if ($script:Result -eq 'PASS') {
    exit 0
}

exit 1
