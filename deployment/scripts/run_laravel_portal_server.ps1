[CmdletBinding()]
param(
    [string]$PortalRoot = 'D:\xampp\htdocs\inventaris\laravel',
    [string]$HostAddress = '0.0.0.0',
    [int]$Port = 8000,
    [string]$LogRoot = ''
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

if ([string]::IsNullOrWhiteSpace($LogRoot)) {
    $LogRoot = Join-Path $PortalRoot 'storage\logs\portal-server'
}

New-Item -ItemType Directory -Path $LogRoot -Force | Out-Null
$SupervisorLog = Join-Path $LogRoot 'portal-server-supervisor.log'
$ServerOut = Join-Path $LogRoot 'portal-server.out.log'
$ServerErr = Join-Path $LogRoot 'portal-server.err.log'

function Write-PortalLog {
    param([string]$Message)

    $line = '[' + (Get-Date).ToString('yyyy-MM-dd HH:mm:ss') + '] ' + $Message
    Add-Content -Path $SupervisorLog -Value $line -Encoding UTF8
}

function Test-PortListening {
    try {
        $connection = Get-NetTCPConnection -LocalPort $Port -State Listen -ErrorAction SilentlyContinue | Select-Object -First 1

        return $null -ne $connection
    } catch {
        return $false
    }
}

if (-not (Test-Path (Join-Path $PortalRoot 'artisan'))) {
    Write-PortalLog "ERROR: Laravel artisan file not found under $PortalRoot"
    exit 1
}

Write-PortalLog "Portal server supervisor started for ${HostAddress}:$Port"

while ($true) {
    if (Test-PortListening) {
        Start-Sleep -Seconds 30
        continue
    }

    Write-PortalLog 'Starting Laravel portal server.'
    $process = Start-Process -FilePath 'php' `
        -ArgumentList @('artisan', 'serve', "--host=$HostAddress", "--port=$Port") `
        -WorkingDirectory $PortalRoot `
        -WindowStyle Hidden `
        -RedirectStandardOutput $ServerOut `
        -RedirectStandardError $ServerErr `
        -PassThru

    Write-PortalLog "Laravel portal server process started. PID=$($process.Id)"
    $process.WaitForExit()
    Write-PortalLog "Laravel portal server exited. ExitCode=$($process.ExitCode). Restarting after delay."
    Start-Sleep -Seconds 5
}
