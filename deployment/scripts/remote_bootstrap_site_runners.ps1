[CmdletBinding()]
param(
    [string[]]$ComputerName = @(),
    [string]$ComputerListPath = '',
    [string]$ShareRoot = '\\srv-server02\Shares\PUBLIC\Inventory\sites\SITE-HQ',
    [string]$RemoteRunnerRoot = 'C:\ProgramData\InternalInventoryRunner',
    [string]$TaskName = 'InternalInventoryRunner-BootstrapUpdate',
    [switch]$RunAfterUpdate,
    [switch]$WhatIfOnly
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

function Normalize-Name {
    param([string]$Name)
    return ([string]$Name).Trim()
}

$targets = @()
foreach ($name in $ComputerName) {
    foreach ($part in ([string]$name -split ',')) {
        $clean = Normalize-Name $part
        if ($clean) { $targets += $clean }
    }
}

if ($ComputerListPath) {
    if (-not (Test-Path $ComputerListPath)) {
        throw "Computer list not found: $ComputerListPath"
    }
    foreach ($line in Get-Content -Path $ComputerListPath) {
        $clean = Normalize-Name $line
        if ($clean -and -not $clean.StartsWith('#')) { $targets += $clean }
    }
}

$targets = @($targets | Sort-Object -Unique)
if ($targets.Count -eq 0) {
    throw 'No target computers were provided.'
}

$bootstrapScript = Join-Path $ShareRoot 'packages\runner\current\scripts\bootstrap_update_runner.ps1'
if (-not (Test-Path $bootstrapScript)) {
    throw "Bootstrap updater not found: $bootstrapScript"
}
$packageRoot = Join-Path $ShareRoot 'packages\runner\current'
if (-not (Test-Path $packageRoot)) {
    throw "Runner package root not found: $packageRoot"
}

$manifestPath = Join-Path $ShareRoot 'packages\runner\runner-manifest.json'
$manifest = if (Test-Path $manifestPath) {
    Get-Content -Path $manifestPath -Raw -Encoding UTF8 | ConvertFrom-Json
} else {
    $null
}
$expectedVersion = if ($manifest -and $manifest.runner_version) { [string]$manifest.runner_version } else { '' }

$startTime = (Get-Date).AddMinutes(5).ToString('HH:mm')

foreach ($target in $targets) {
    $remoteRootRelative = $RemoteRunnerRoot -replace '^[A-Za-z]:\\', ''
    $remoteDrive = if ($RemoteRunnerRoot -match '^([A-Za-z]):') { $matches[1] } else { 'C' }
    $remoteStagingShare = "\\$target\$remoteDrive`$\$remoteRootRelative\update-staging\current"
    $remoteStagingLocal = Join-Path $RemoteRunnerRoot 'update-staging\current'
    $remoteBootstrapLocal = Join-Path $remoteStagingLocal 'scripts\bootstrap_update_runner.ps1'

    $runArgs = @(
        '-NoProfile',
        '-ExecutionPolicy', 'Bypass',
        '-File', ('"{0}"' -f $remoteBootstrapLocal),
        '-RunnerRoot', ('"{0}"' -f $RemoteRunnerRoot),
        '-PackageRoot', ('"{0}"' -f $remoteStagingLocal),
        '-NoElevate'
    )
    if ($expectedVersion) {
        $runArgs += @('-ExpectedVersion', ('"{0}"' -f $expectedVersion))
    }
    if ($RunAfterUpdate) {
        $runArgs += '-RunAfterUpdate'
    }
    $taskRun = 'powershell.exe ' + ($runArgs -join ' ')

    Write-Host "[$target] scheduling bootstrap update to $expectedVersion"
    Write-Host "[$target] staging package to $remoteStagingShare"
    Write-Host "[$target] $taskRun"

    if ($WhatIfOnly) { continue }

    try {
        if (Test-Path $remoteStagingShare) {
            Remove-Item -Path $remoteStagingShare -Recurse -Force
        }
        New-Item -ItemType Directory -Path $remoteStagingShare -Force | Out-Null
        Copy-Item -Path (Join-Path $packageRoot '*') -Destination $remoteStagingShare -Recurse -Force
    }
    catch {
        Write-Warning "[$target] could not stage package: $($_.Exception.Message)"
        continue
    }

    & schtasks.exe /Create /S $target /TN $TaskName /SC ONCE /ST $startTime /TR $taskRun /RU SYSTEM /RL HIGHEST /F | Out-Host
    if ($LASTEXITCODE -ne 0) {
        Write-Warning "[$target] could not create remote task."
        continue
    }

    & schtasks.exe /Run /S $target /TN $TaskName | Out-Host
    if ($LASTEXITCODE -ne 0) {
        Write-Warning "[$target] task was created but could not be started."
        continue
    }

    Write-Host "[$target] remote bootstrap started."
}
