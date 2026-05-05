[CmdletBinding()]
param(
    [Parameter(Mandatory=$true)][string]$CollectorRoot,
    [Parameter(Mandatory=$true)][string]$ConfigPath,
    [string]$TaskName = 'InternalInventoryCollectorRelay',
    [int]$PollIntervalMinutes = 5,
    [switch]$RunAsCurrentUser
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

if (-not (Test-Path $CollectorRoot)) {
    throw "CollectorRoot not found: $CollectorRoot"
}
if (-not (Test-Path $ConfigPath)) {
    throw "ConfigPath not found: $ConfigPath"
}

$pythonw = (Get-Command pythonw -ErrorAction SilentlyContinue).Source
if (-not $pythonw) {
    throw "pythonw executable not found in PATH"
}

$relayPath = Join-Path $CollectorRoot 'relay.py'
$hiddenLauncherPath = Join-Path $CollectorRoot 'run_collector_hidden.pyw'
$logDir = Join-Path $CollectorRoot 'logs'
$logPath = Join-Path $logDir 'collector-task.log'

if (-not (Test-Path $hiddenLauncherPath)) {
    throw "Hidden collector launcher not found: $hiddenLauncherPath"
}

New-Item -ItemType Directory -Path $logDir -Force | Out-Null

$taskRun = "`"$pythonw`" `"$hiddenLauncherPath`""

if ($RunAsCurrentUser) {
    & schtasks.exe /Create /TN $TaskName /SC MINUTE /MO $PollIntervalMinutes /TR $taskRun /F | Out-Host
} else {
    & schtasks.exe /Create /TN $TaskName /SC MINUTE /MO $PollIntervalMinutes /TR $taskRun /RU SYSTEM /RL HIGHEST /F | Out-Host
}
if ($LASTEXITCODE -ne 0) {
    throw "Could not register collector relay scheduled task: $TaskName"
}

$startupTaskName = "$TaskName-Startup"
if ($RunAsCurrentUser) {
    & schtasks.exe /Create /TN $startupTaskName /SC ONLOGON /TR $taskRun /F | Out-Host
} else {
    & schtasks.exe /Create /TN $startupTaskName /SC ONSTART /TR $taskRun /RU SYSTEM /RL HIGHEST /F | Out-Host
}
if ($LASTEXITCODE -ne 0) {
    throw "Could not register collector relay startup task: $startupTaskName"
}

Write-Host "Collector relay scheduled task registered: $TaskName"
Write-Host "Collector relay startup task registered: $startupTaskName"
Write-Host "Collector relay task account: $(if ($RunAsCurrentUser) { 'current Windows user' } else { 'SYSTEM' })"
Write-Host "Collector relay runs hidden through: $hiddenLauncherPath"
Write-Host "Collector relay task log: $logPath"
