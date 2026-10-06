[CmdletBinding()]
param(
    [Parameter(Mandatory=$true)][string]$SourceRoot,
    [Parameter(Mandatory=$true)][string]$SharedRoot,
    [Parameter(Mandatory=$true)][string]$RunnerId,
    [Parameter(Mandatory=$true)][string]$SiteId,
    [string]$SiteName = '',
    [string]$CollectorName = 'collector-01',
    [string]$Location = '',
    [string]$Room = '',
    [string]$InstallRoot = 'C:\ProgramData\InternalInventoryRunner',
    [string]$RunnerVersion = '1.0.0',
    [string]$TaskName = 'InternalInventoryRunner',
    [int]$ScanIntervalMinutes = 240,
    [int]$PollIntervalMinutes = 5,
    [switch]$RunAtStartup = $true,
    [int]$TaskRandomDelayMinutes = 15,
    [switch]$RunAsCurrentUser = $true
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

function Ensure-Directory {
    param([string]$Path)
    if (-not (Test-Path $Path)) {
        New-Item -ItemType Directory -Path $Path -Force | Out-Null
    }
}

function Grant-RunnerInstallPermissions {
    param([string]$Path, [string]$RunAsUser = '')

    if (-not (Test-Path $Path)) { return }

    # Root (and scripts\, tools\) = Administrators + SYSTEM only; Users get nothing.
    # Current-user task: that user gets Modify on config\, state\, logs\, data\ only.
    foreach ($sub in 'config', 'state', 'logs', 'data') { Ensure-Directory (Join-Path $Path $sub) }
    & icacls.exe $Path /remove:g '*S-1-5-32-545' /T /C | Out-Host
    & icacls.exe $Path /inheritance:r /grant:r '*S-1-5-32-544:(OI)(CI)F' '*S-1-5-18:(OI)(CI)F' | Out-Host
    if ($LASTEXITCODE -ne 0) {
        throw "Could not set permissions on runner install root: $Path"
    }
    if ($RunAsUser) {
        foreach ($sub in 'config', 'state', 'logs', 'data') {
            & icacls.exe (Join-Path $Path $sub) /grant ($RunAsUser + ':(OI)(CI)M') | Out-Host
            if ($LASTEXITCODE -ne 0) {
                throw "Could not grant modify permission on $sub for $RunAsUser"
            }
        }
    }
}

function Read-JsonFile {
    param([string]$Path)

    if (-not (Test-Path $Path)) {
        return $null
    }

    try {
        return Get-Content -Raw -Path $Path -Encoding UTF8 | ConvertFrom-Json
    }
    catch {
        return $null
    }
}

function Get-JsonValue {
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

if (-not (Test-Path $SourceRoot)) {
    throw "SourceRoot not found: $SourceRoot"
}

Ensure-Directory $InstallRoot
Copy-Item -Path (Join-Path $SourceRoot '*') -Destination $InstallRoot -Recurse -Force
$runAsUserName = ''
if ($RunAsCurrentUser) { $runAsUserName = [Security.Principal.WindowsIdentity]::GetCurrent().Name }
Grant-RunnerInstallPermissions -Path $InstallRoot -RunAsUser $runAsUserName

$configDir = Join-Path $InstallRoot 'config'
Ensure-Directory $configDir

$existingConfigPath = Join-Path $configDir 'runner-config.json'
$existingStatePath = Join-Path $InstallRoot 'state\runner-state.json'
$existingConfig = Read-JsonFile -Path $existingConfigPath
$existingState = Read-JsonFile -Path $existingStatePath
$runnerGuid = Get-JsonValue -Object $existingConfig -Names @('runnerGuid', 'runner_guid')
if (-not $runnerGuid) {
    $runnerGuid = Get-JsonValue -Object $existingState -Names @('runner_guid', 'runnerGuid')
}
if (-not $runnerGuid) {
    $runnerGuid = [guid]::NewGuid().ToString()
}

$config = [ordered]@{
    runnerId = $RunnerId
    runnerGuid = $runnerGuid
    siteId = $SiteId
    siteName = $SiteName
    collectorName = $CollectorName
    sharedRoot = $SharedRoot
    location = $Location
    room = $Room
    runnerVersion = $RunnerVersion
    scheduledTaskName = $TaskName
    installRoot = $InstallRoot
    scanIntervalMinutes = $ScanIntervalMinutes
    runnerPollIntervalMinutes = $PollIntervalMinutes
    runAtStartup = [bool]$RunAtStartup
    taskRandomDelayMinutes = $TaskRandomDelayMinutes
    runAsCurrentUser = [bool]$RunAsCurrentUser
}
$config | ConvertTo-Json -Depth 8 | Set-Content -Path (Join-Path $configDir 'runner-config.json') -Encoding UTF8

$scriptPath = Join-Path $InstallRoot 'scripts\runner_main.ps1'
$hiddenLauncherPath = Join-Path $InstallRoot 'scripts\run_runner_hidden.vbs'
$taskRun = if (Test-Path $hiddenLauncherPath) {
    "wscript.exe `"$hiddenLauncherPath`""
} else {
    "powershell.exe -NoProfile -ExecutionPolicy Bypass -File `"$scriptPath`""
}

if ($RunAsCurrentUser) {
    & schtasks.exe /Create /TN $TaskName /SC MINUTE /MO $PollIntervalMinutes /TR $taskRun /RL HIGHEST /F | Out-Host
} else {
    & schtasks.exe /Create /TN $TaskName /SC MINUTE /MO $PollIntervalMinutes /TR $taskRun /RU SYSTEM /RL HIGHEST /F | Out-Host
}
if ($LASTEXITCODE -ne 0) {
    throw "Could not register runner scheduled task: $TaskName"
}

if ([bool]$RunAtStartup) {
    $startupTaskName = "$TaskName-Startup"
    if ($RunAsCurrentUser) {
        & schtasks.exe /Create /TN $startupTaskName /SC ONLOGON /TR $taskRun /RL HIGHEST /F | Out-Host
    } else {
        & schtasks.exe /Create /TN $startupTaskName /SC ONSTART /TR $taskRun /RU SYSTEM /RL HIGHEST /F | Out-Host
    }
    if ($LASTEXITCODE -ne 0) {
        throw "Could not register runner startup task: $startupTaskName"
    }
    Write-Host "Startup task registered: $startupTaskName"
}

Write-Host "Runner installed to $InstallRoot"
Write-Host "Task registered: $TaskName"
Write-Host "Scan interval minutes: $ScanIntervalMinutes"
Write-Host "Command poll interval minutes: $PollIntervalMinutes"
Write-Host "Run at startup: $([bool]$RunAtStartup)"
Write-Host "Task account: $(if ($RunAsCurrentUser) { 'current Windows user' } else { 'SYSTEM' })"
