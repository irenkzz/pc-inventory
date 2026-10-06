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

function Get-CommandExecutableValue {
    param([object]$Command)

    if (-not $Command) {
        return ''
    }

    $candidate = @($Command | Select-Object -First 1)[0]
    foreach ($propertyName in @('Source', 'Path')) {
        if ($candidate.PSObject.Properties.Name -contains $propertyName) {
            $value = [string]$candidate.$propertyName
            if (-not [string]::IsNullOrWhiteSpace($value)) {
                return $value
            }
        }
    }

    $name = [string]$candidate.Name
    if (-not [string]::IsNullOrWhiteSpace($name)) {
        return $name
    }

    return ''
}

function Resolve-PythonExecutable {
    foreach ($commandName in @('pythonw', 'python')) {
        $command = Get-Command $commandName -ErrorAction SilentlyContinue
        $executable = Get-CommandExecutableValue -Command $command
        if (-not [string]::IsNullOrWhiteSpace($executable)) {
            return [pscustomobject]@{
                Executable = $executable
                Arguments = @()
            }
        }
    }

    $pyCommand = Get-Command py -ErrorAction SilentlyContinue
    $pyExecutable = Get-CommandExecutableValue -Command $pyCommand
    if (-not [string]::IsNullOrWhiteSpace($pyExecutable)) {
        return [pscustomobject]@{
            Executable = $pyExecutable
            Arguments = @('-3')
        }
    }

    throw 'Python runtime was not found. Collector-share mode requires Python 3.x on the collector host for this MVP. Install Python 3.x with pythonw/python in PATH, or use Direct HTTPS mode for small/no-IT sites.'
}

if (-not (Test-Path $CollectorRoot)) {
    throw "CollectorRoot not found: $CollectorRoot"
}
if (-not (Test-Path $ConfigPath)) {
    throw "ConfigPath not found: $ConfigPath"
}

# The scheduled task must not run from the kit folder: the launchers stage the kit in a temporary
# folder and delete it afterwards. Install a permanent copy and register the task from there.
$InstalledRoot = Join-Path $env:ProgramData 'InternalInventoryCollector\app'
$sourceRoot = (Resolve-Path -Path $CollectorRoot).Path.TrimEnd('\')
$sourceConfig = (Resolve-Path -Path $ConfigPath).Path
if ($sourceRoot -ine $InstalledRoot.TrimEnd('\')) {
    New-Item -ItemType Directory -Path $InstalledRoot -Force | Out-Null
    foreach ($item in @(Get-ChildItem -Path $sourceRoot -Force | Where-Object { $_.Name -ne 'logs' })) {
        Copy-Item -Path $item.FullName -Destination $InstalledRoot -Recurse -Force
    }
    Copy-Item -Path $sourceConfig -Destination (Join-Path $InstalledRoot 'collector_config.json') -Force
    $CollectorRoot = $InstalledRoot
    $ConfigPath = Join-Path $InstalledRoot 'collector_config.json'
    # collector_config.json holds the site token: Administrators + SYSTEM (+ the task user in current-user mode).
    & icacls.exe $InstalledRoot /inheritance:r /grant:r '*S-1-5-32-544:(OI)(CI)F' '*S-1-5-18:(OI)(CI)F' | Out-Null
    if ($RunAsCurrentUser) {
        & icacls.exe $InstalledRoot /grant:r ('{0}:(OI)(CI)M' -f [Security.Principal.WindowsIdentity]::GetCurrent().Name) | Out-Null
    }
    Write-Host "Collector files installed to: $InstalledRoot"
}

$python = Resolve-PythonExecutable

$relayPath = Join-Path $CollectorRoot 'relay.py'
$hiddenLauncherPath = Join-Path $CollectorRoot 'run_collector_hidden.pyw'
$logDir = Join-Path $CollectorRoot 'logs'
$logPath = Join-Path $logDir 'collector-task.log'

if (-not (Test-Path $hiddenLauncherPath)) {
    throw "Hidden collector launcher not found: $hiddenLauncherPath"
}

New-Item -ItemType Directory -Path $logDir -Force | Out-Null

$pythonArgs = if ($python.Arguments.Count -gt 0) { ' ' + ($python.Arguments -join ' ') } else { '' }
$taskRun = "`"$($python.Executable)`"$pythonArgs `"$hiddenLauncherPath`""

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
