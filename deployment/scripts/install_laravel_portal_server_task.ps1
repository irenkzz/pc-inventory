[CmdletBinding()]
param(
    [string]$PortalRoot = 'D:\xampp\htdocs\inventaris\laravel',
    [string]$ScriptPath = 'D:\xampp\htdocs\inventaris\deployment\scripts\run_laravel_portal_server.ps1',
    [string]$TaskName = 'InternalInventoryPortalServer',
    [string]$HostAddress = '0.0.0.0',
    [int]$Port = 8000
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

if (-not (Test-Path $ScriptPath)) {
    throw "Portal server script not found: $ScriptPath"
}

if (-not (Test-Path (Join-Path $PortalRoot 'artisan'))) {
    throw "Laravel artisan file not found under: $PortalRoot"
}

$taskRun = 'powershell.exe -NoProfile -ExecutionPolicy Bypass -WindowStyle Hidden -File "' + $ScriptPath + '" -PortalRoot "' + $PortalRoot + '" -HostAddress "' + $HostAddress + '" -Port ' + $Port

& schtasks.exe /Create /TN $TaskName /SC ONLOGON /TR $taskRun /F | Out-Host
if ($LASTEXITCODE -ne 0) {
    throw "Could not register portal server scheduled task: $TaskName"
}

& schtasks.exe /Run /TN $TaskName | Out-Host
if ($LASTEXITCODE -ne 0) {
    throw "Could not start portal server scheduled task: $TaskName"
}

Write-Host "Portal server scheduled task registered: $TaskName"
Write-Host "Portal server URL: http://$HostAddress`:$Port"
Write-Host "Portal server log: $PortalRoot\storage\logs\portal-server\portal-server-supervisor.log"
