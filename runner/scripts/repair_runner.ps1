[CmdletBinding()]
param(
    [Parameter(Mandatory=$true)][string]$RunnerRoot,
    [Parameter(Mandatory=$true)][string]$PackageRoot,
    [string]$ExpectedVersion = ""
)

Set-StrictMode -Version Latest
$ErrorActionPreference = "Stop"

if (-not (Test-Path $PackageRoot)) {
    throw "Package root not found: $PackageRoot"
}

$preserveExact = @(
    'config\runner-config.json',
    'config\inventory-destinations.json'
)

$preservePrefixes = @(
    'data\',
    'logs\',
    'state\'
)

$skipIfLocked = @(
    'scripts\runner_main.ps1',
    'scripts\repair_runner.ps1'
)

Get-ChildItem -Path $PackageRoot -Recurse -File | ForEach-Object {
    $relative = $_.FullName.Substring($PackageRoot.Length).TrimStart('\')
    if ($preserveExact -contains $relative) { return }
    foreach ($prefix in $preservePrefixes) {
        if ($relative.StartsWith($prefix, [System.StringComparison]::OrdinalIgnoreCase)) { return }
    }

    $dest = Join-Path $RunnerRoot $relative
    $destDir = Split-Path -Parent $dest
    if (-not (Test-Path $destDir)) {
        New-Item -ItemType Directory -Path $destDir -Force | Out-Null
    }
    try {
        Copy-Item -Path $_.FullName -Destination $dest -Force -ErrorAction Stop
    }
    catch {
        if ($skipIfLocked -contains $relative) { return }
        throw
    }
}

$configPath = Join-Path $RunnerRoot 'config\runner-config.json'
if ($ExpectedVersion -and (Test-Path $configPath)) {
    $config = Get-Content -Path $configPath -Raw -Encoding UTF8 | ConvertFrom-Json
    $config.runnerVersion = $ExpectedVersion
    $config | ConvertTo-Json -Depth 8 | Set-Content -Path $configPath -Encoding UTF8
}
