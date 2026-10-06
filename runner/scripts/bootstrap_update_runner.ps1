[CmdletBinding()]
param(
    [string]$RunnerRoot = 'C:\ProgramData\InternalInventoryRunner',
    [string]$PackageRoot = '',
    [string]$ExpectedVersion = '',
    [switch]$RunAfterUpdate,
    [int]$DelaySeconds = 0,
    [switch]$NoElevate
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

function Test-IsAdministrator {
    $identity = [Security.Principal.WindowsIdentity]::GetCurrent()
    $principal = [Security.Principal.WindowsPrincipal]::new($identity)
    return $principal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)
}

function Test-IsSystemAccount {
    $identity = [Security.Principal.WindowsIdentity]::GetCurrent()
    return $identity.User -and $identity.User.Value -eq 'S-1-5-18'
}

function Quote-Argument {
    param([string]$Value)
    return '"' + ($Value -replace '"', '\"') + '"'
}

function Ensure-Directory {
    param([string]$Path)
    if (-not (Test-Path $Path)) {
        New-Item -ItemType Directory -Path $Path -Force | Out-Null
    }
}

function Read-JsonFile {
    param([string]$Path)
    if (-not (Test-Path $Path)) { return $null }
    return Get-Content -Path $Path -Raw -Encoding UTF8 | ConvertFrom-Json
}

function Write-JsonFile {
    param([string]$Path, [object]$Value)
    $dir = Split-Path -Parent $Path
    if ($dir) { Ensure-Directory $dir }
    $tmp = $Path + '.tmp'
    $Value | ConvertTo-Json -Depth 8 | Set-Content -Path $tmp -Encoding UTF8
    Move-Item -Path $tmp -Destination $Path -Force
}

function Grant-RunnerInstallPermissions {
    param([string]$Path, [string]$RunAsUser = '')

    if (-not (Test-Path $Path)) { return }

    # ACL matrix (root, scripts\, tools\, update-staging\ inherit from root):
    #   Administrators + SYSTEM: Full everywhere.  Users: no access to root (no write, no config read).
    #   SYSTEM task: nothing more needed.
    #   Current-user task: that one user additionally gets Modify on config\, state\, logs\, data\
    #   only (runner rewrites config and writes state/logs/data); scripts\ stays read-only for them.
    foreach ($sub in 'config', 'state', 'logs', 'data') { Ensure-Directory (Join-Path $Path $sub) }
    $previousErrorActionPreference = $ErrorActionPreference
    $ErrorActionPreference = 'Continue'
    try {
        # Strip legacy Users grants from the whole tree, then lock root to Admins+SYSTEM.
        $output = @(& icacls.exe $Path /remove:g '*S-1-5-32-545' /T /C 2>&1)
        $output += & icacls.exe $Path /inheritance:r /grant:r '*S-1-5-32-544:(OI)(CI)F' '*S-1-5-18:(OI)(CI)F' 2>&1
        $exitCode = $LASTEXITCODE
        if ($exitCode -eq 0 -and $RunAsUser) {
            foreach ($sub in 'config', 'state', 'logs', 'data') {
                $output += & icacls.exe (Join-Path $Path $sub) /grant ($RunAsUser + ':(OI)(CI)M') 2>&1
                if ($LASTEXITCODE -ne 0) { $exitCode = $LASTEXITCODE }
            }
        }
    }
    finally {
        $ErrorActionPreference = $previousErrorActionPreference
    }

    if ($exitCode -ne 0) {
        Write-BootstrapLog "Permission grant warning: $output"
    } else {
        Write-BootstrapLog "Runner install root permissions refreshed."
    }
}

function Sync-StateToBranchShare {
    param(
        [string]$SharedRoot,
        [string]$RunnerId,
        [object]$State
    )

    if (-not $SharedRoot -or -not $RunnerId) { return }

    $heartbeatDir = Join-Path $SharedRoot 'data\runner-heartbeats'
    Ensure-Directory $heartbeatDir
    Write-JsonFile -Path (Join-Path $heartbeatDir ($RunnerId + '.json')) -Value $State
}

function Set-ObjectProperty {
    param([object]$Object, [string]$Name, [object]$Value)
    if ($Object.PSObject.Properties.Name -contains $Name) {
        $Object.$Name = $Value
    } else {
        $Object | Add-Member -NotePropertyName $Name -NotePropertyValue $Value -Force
    }
}

function Get-ObjectValue {
    param(
        [object]$Object,
        [string[]]$Names
    )

    if (-not $Object) { return '' }

    foreach ($name in $Names) {
        if ($Object.PSObject.Properties.Name -contains $name) {
            $value = $Object.$name
            if ($null -ne $value -and [string]$value -ne '') {
                return [string]$value
            }
        }
    }

    return ''
}

function Write-BootstrapLog {
    param([string]$Message)
    $line = '{0} {1}' -f (Get-Date).ToString('s'), $Message
    Write-Host $line
    if ($script:LogPath) {
        Add-Content -Path $script:LogPath -Value $line -Encoding UTF8
    }
}

if (-not (Test-IsAdministrator) -and -not $NoElevate) {
    $argsList = @(
        '-NoProfile',
        '-ExecutionPolicy', 'Bypass',
        '-File', (Quote-Argument $PSCommandPath),
        '-RunnerRoot', (Quote-Argument $RunnerRoot)
    )
    if ($PackageRoot) {
        $argsList += @('-PackageRoot', (Quote-Argument $PackageRoot))
    }
    if ($ExpectedVersion) {
        $argsList += @('-ExpectedVersion', (Quote-Argument $ExpectedVersion))
    }
    if ($RunAfterUpdate) {
        $argsList += '-RunAfterUpdate'
    }
    if ($DelaySeconds -gt 0) {
        $argsList += @('-DelaySeconds', [string]$DelaySeconds)
    }
    $argsList += '-NoElevate'

    Start-Process -FilePath 'powershell.exe' -Verb RunAs -ArgumentList ($argsList -join ' ')
    Write-Host 'Elevated updater started. Approve the Windows prompt, then wait for the updater window to finish.'
    exit 0
}

$scriptRoot = Split-Path -Parent $PSCommandPath
if (-not $PackageRoot) {
    $PackageRoot = Split-Path -Parent $scriptRoot
}

if (-not (Test-Path $PackageRoot)) {
    throw "Package root not found: $PackageRoot"
}

Ensure-Directory $RunnerRoot
$RunnerRoot = (Resolve-Path $RunnerRoot).ProviderPath
$PackageRoot = (Resolve-Path $PackageRoot).ProviderPath
Ensure-Directory (Join-Path $RunnerRoot 'logs')
$script:LogPath = Join-Path $RunnerRoot ('logs\bootstrap-update-{0}.log' -f (Get-Date).ToString('yyyyMMdd-HHmmss'))
Write-BootstrapLog "Bootstrap update started."
Write-BootstrapLog "Runner root: $RunnerRoot"
Write-BootstrapLog "Package root: $PackageRoot"

trap {
    Write-BootstrapLog ("FATAL: " + $_.Exception.Message)
    exit 1
}

if ($DelaySeconds -gt 0) {
    Write-BootstrapLog "Waiting $DelaySeconds seconds for runner process to exit."
    Start-Sleep -Seconds $DelaySeconds
}

$configPath = Join-Path $RunnerRoot 'config\runner-config.json'
$config = Read-JsonFile $configPath
if (-not $config) {
    throw "runner-config.json not found at $configPath"
}

$runnerId = [string]$config.runnerId
$runnerGuid = Get-ObjectValue -Object $config -Names @('runnerGuid', 'runner_guid')
$siteId = [string]$config.siteId
$sharedRoot = [string]$config.sharedRoot
$taskName = [string]$config.scheduledTaskName
if (-not $taskName) { $taskName = 'InternalInventoryRunner' }
$runAsCurrentUser = $false
if ($config.PSObject.Properties.Name -contains 'runAsCurrentUser') {
    $runAsCurrentUser = [bool]$config.runAsCurrentUser
} elseif (-not (Test-IsSystemAccount)) {
    $runAsCurrentUser = $true
}
$runAtStartup = $true
if ($config.PSObject.Properties.Name -contains 'runAtStartup') {
    $runAtStartup = [bool]$config.runAtStartup
}

$manifest = $null
$manifestCandidates = @(
    (Join-Path $PackageRoot 'manifest\runner-manifest.json'),
    (Join-Path (Split-Path -Parent $PackageRoot) 'runner-manifest.json')
)
foreach ($manifestPath in $manifestCandidates) {
    $candidate = Read-JsonFile $manifestPath
    if ($candidate) {
        $manifest = $candidate
        break
    }
}

if (-not $ExpectedVersion -and $manifest -and $manifest.runner_version) {
    $ExpectedVersion = [string]$manifest.runner_version
}
if (-not $ExpectedVersion) {
    throw 'Expected runner version could not be determined from parameters or manifest.'
}

Write-BootstrapLog "Runner ID: $runnerId"
Write-BootstrapLog "Target version: $ExpectedVersion"
$runAsUserName = ''
$skipAclTightening = $false
if ($runAsCurrentUser) {
    if (-not (Test-IsSystemAccount)) {
        $runAsUserName = [Security.Principal.WindowsIdentity]::GetCurrent().Name
    } else {
        # Self-update runs as SYSTEM; the runner task user must be read from the registered task,
        # otherwise tightening the ACL would lock that user out of config\ and state\.
        try {
            $existingTask = Get-ScheduledTask -TaskName $taskName -ErrorAction Stop
            $taskUser = [string]$existingTask.Principal.UserId
            if ($taskUser -and $taskUser -notmatch '^(SYSTEM|NT AUTHORITY\SYSTEM|S-1-5-18)$') { $runAsUserName = $taskUser }
        } catch { }
        if (-not $runAsUserName) { $skipAclTightening = $true }
    }
}
if ($skipAclTightening) {
    Write-BootstrapLog 'Current-user runner task user could not be resolved; leaving install root permissions unchanged.'
} else {
    Grant-RunnerInstallPermissions -Path $RunnerRoot -RunAsUser $runAsUserName
}

$previousErrorActionPreference = $ErrorActionPreference
$ErrorActionPreference = 'Continue'
try {
    $stopOutput = & schtasks.exe /End /TN $taskName 2>&1
    if ($LASTEXITCODE -ne 0) {
        Write-BootstrapLog "Scheduled task was not stopped or was not running: $taskName"
    }
}
catch {
    Write-BootstrapLog "Scheduled task was not stopped or was not running: $taskName"
}
finally {
    $ErrorActionPreference = $previousErrorActionPreference
}

$preserveExact = @(
    'config\runner-config.json',
    'config\inventory-destinations.json',
    'config\runner-config.sample.json',
    'config\runner-config.template.json',
    'scripts\install_runner.cmd',
    'scripts\install_runner.ps1'
)

$preservePrefixes = @(
    'data\',
    'logs\',
    'state\',
    'manifest\'
)

$skipIfLocked = @(
    'scripts\bootstrap_update_runner.ps1'
)

$copied = 0
foreach ($file in Get-ChildItem -Path $PackageRoot -Recurse -File) {
    $relative = $file.FullName.Substring($PackageRoot.Length).TrimStart('\')
    if ($preserveExact -contains $relative) { continue }

    $skip = $false
    foreach ($prefix in $preservePrefixes) {
        if ($relative.StartsWith($prefix, [System.StringComparison]::OrdinalIgnoreCase)) {
            $skip = $true
            break
        }
    }
    if ($skip) { continue }

    $dest = Join-Path $RunnerRoot $relative
    Ensure-Directory (Split-Path -Parent $dest)
    try {
        Copy-Item -Path $file.FullName -Destination $dest -Force -ErrorAction Stop
    }
    catch {
        if ($skipIfLocked -contains $relative) {
            Write-BootstrapLog "Skipped locked running script: $relative"
            continue
        }
        throw
    }
    $copied++
}
Write-BootstrapLog "Copied package files: $copied"

$config.runnerVersion = $ExpectedVersion
if ($manifest -and $manifest.recommended_scan_interval_minutes) {
    Set-ObjectProperty -Object $config -Name 'scanIntervalMinutes' -Value ([int]$manifest.recommended_scan_interval_minutes)
}
if ($manifest -and $manifest.recommended_runner_poll_interval_minutes) {
    Set-ObjectProperty -Object $config -Name 'runnerPollIntervalMinutes' -Value ([int]$manifest.recommended_runner_poll_interval_minutes)
}
Write-JsonFile -Path $configPath -Value $config

$pollIntervalMinutes = 5
if ($config.PSObject.Properties.Name -contains 'runnerPollIntervalMinutes' -and $config.runnerPollIntervalMinutes) {
    $pollIntervalMinutes = [int]$config.runnerPollIntervalMinutes
}
$runnerMain = Join-Path $RunnerRoot 'scripts\runner_main.ps1'
if (Test-Path $runnerMain) {
    $hiddenLauncherPath = Join-Path $RunnerRoot 'scripts\run_runner_hidden.vbs'
    $taskRun = if (Test-Path $hiddenLauncherPath) {
        "wscript.exe `"$hiddenLauncherPath`""
    } else {
        "powershell.exe -NoProfile -ExecutionPolicy Bypass -File `"$runnerMain`""
    }
    $previousErrorActionPreference = $ErrorActionPreference
    $ErrorActionPreference = 'Continue'
    try {
        if ($runAsCurrentUser) {
            $taskOutput = & schtasks.exe /Create /TN $taskName /SC MINUTE /MO $pollIntervalMinutes /TR $taskRun /RL HIGHEST /F 2>&1
        } else {
            $taskOutput = & schtasks.exe /Create /TN $taskName /SC MINUTE /MO $pollIntervalMinutes /TR $taskRun /RU SYSTEM /RL HIGHEST /F 2>&1
        }
        $taskExitCode = $LASTEXITCODE
    }
    finally {
        $ErrorActionPreference = $previousErrorActionPreference
    }

    if ($taskExitCode -eq 0) {
        Write-BootstrapLog "Scheduled task updated: $taskName every $pollIntervalMinutes minutes."
    } else {
        Write-BootstrapLog "Scheduled task update failed: $taskOutput"
    }

    $startupTaskName = "$taskName-Startup"
    $previousErrorActionPreference = $ErrorActionPreference
    $ErrorActionPreference = 'Continue'
    try {
        if ($runAtStartup) {
            if ($runAsCurrentUser) {
                $startupOutput = & schtasks.exe /Create /TN $startupTaskName /SC ONLOGON /TR $taskRun /RL HIGHEST /F 2>&1
            } else {
                $startupOutput = & schtasks.exe /Create /TN $startupTaskName /SC ONSTART /TR $taskRun /RU SYSTEM /RL HIGHEST /F 2>&1
            }
        } else {
            $startupOutput = & schtasks.exe /Delete /TN $startupTaskName /F 2>&1
        }
        $startupExitCode = $LASTEXITCODE
    }
    finally {
        $ErrorActionPreference = $previousErrorActionPreference
    }

    if ($runAtStartup) {
        if ($startupExitCode -eq 0) {
            Write-BootstrapLog "Startup task updated: $startupTaskName"
        } else {
            Write-BootstrapLog "Startup task update failed: $startupOutput"
        }
    } elseif ($startupExitCode -eq 0) {
        Write-BootstrapLog "Startup task removed: $startupTaskName"
    }
}

$statePath = Join-Path $RunnerRoot 'state\runner-state.json'
$state = Read-JsonFile $statePath
if (-not $state) {
    $state = [ordered]@{}
}
if (-not $runnerGuid) {
    $runnerGuid = Get-ObjectValue -Object $state -Names @('runner_guid', 'runnerGuid')
}
if (-not $runnerGuid) {
    $runnerGuid = [guid]::NewGuid().ToString()
}
Set-ObjectProperty -Object $config -Name 'runnerGuid' -Value $runnerGuid
Write-JsonFile -Path $configPath -Value $config

$state.runner_id = $runnerId
Set-ObjectProperty -Object $state -Name 'runner_guid' -Value $runnerGuid
$state.hostname = $env:COMPUTERNAME
$state.site_id = $siteId
$state.site_name = [string]$config.siteName
$state.collector_name = [string]$config.collectorName
$state.runner_version = $ExpectedVersion
$state.shared_root = $sharedRoot
Set-ObjectProperty -Object $state -Name 'scan_interval_minutes' -Value ([int]$config.scanIntervalMinutes)
Set-ObjectProperty -Object $state -Name 'runner_poll_interval_minutes' -Value $pollIntervalMinutes
$state.install_mode = if ($runAsCurrentUser) { 'scheduled_task_current_user' } else { 'scheduled_task_system' }
$state.last_seen_at = (Get-Date).ToString('s')
$state.last_inventory_status = 'bootstrap_update_applied'
$state.last_upload_status = 'bootstrap_update_applied'
$state.last_error = ''
$state.last_command_type = 'bootstrap_update'
Write-JsonFile -Path $statePath -Value $state
Sync-StateToBranchShare -SharedRoot $sharedRoot -RunnerId $runnerId -State $state

if ($sharedRoot -and $runnerId) {
    $commandPath = Join-Path $sharedRoot ('control\commands\{0}.json' -f $runnerId)
    $command = Read-JsonFile $commandPath
    if ($command -and [string]$command.command_type -eq 'repair_update') {
        $ackDir = Join-Path $sharedRoot 'control\command-acks'
        Ensure-Directory $ackDir
        $ack = [ordered]@{
            command_id = $command.id
            runner_id = $runnerId
            site_id = $siteId
            status = 'completed'
            message = "Bootstrap update applied runner $ExpectedVersion."
            runner_state = $state
            acknowledged_at = (Get-Date).ToString('s')
        }
        Write-JsonFile -Path (Join-Path $ackDir ('{0}__{1}.json' -f $runnerId, $command.id)) -Value $ack
        Remove-Item -Path $commandPath -Force -ErrorAction SilentlyContinue
        Write-BootstrapLog "Cleared pending repair_update command: $($command.id)"
    }
}

Write-BootstrapLog "Bootstrap update completed."

if ($RunAfterUpdate) {
    $runnerMain = Join-Path $RunnerRoot 'scripts\runner_main.ps1'
    if (Test-Path $runnerMain) {
        Write-BootstrapLog 'Running runner once after update.'
        & powershell.exe -NoProfile -ExecutionPolicy Bypass -File $runnerMain
    }
}
