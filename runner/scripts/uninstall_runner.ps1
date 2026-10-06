[CmdletBinding()]
param(
    [string]$InstallRoot = 'C:\ProgramData\InternalInventoryRunner',
    [string]$TaskName = 'InternalInventoryRunner',
    [switch]$KeepData,
    [switch]$WhatIf,
    [switch]$Force
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$DefaultRoot = 'C:\ProgramData\InternalInventoryRunner'
$LogPath = Join-Path $env:TEMP ("runner-uninstall-{0}.log" -f (Get-Date -Format 'yyyyMMdd-HHmmss'))
$Summary = New-Object System.Collections.Generic.List[string]

function Write-Step {
    param([string]$Message)
    $line = "[{0}] {1}" -f (Get-Date -Format 's'), $Message
    Write-Host $line
    Add-Content -Path $LogPath -Value $line -Encoding UTF8
    $Summary.Add($Message)
}

function Test-IsAdmin {
    $id = [Security.Principal.WindowsIdentity]::GetCurrent()
    return (New-Object Security.Principal.WindowsPrincipal($id)).IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)
}

# Path guard: returns the normalized path or throws. Never deletes anything itself.
function Resolve-SafeInstallRoot {
    param([string]$Path, [bool]$AllowOutsideProgramData)

    if ([string]::IsNullOrWhiteSpace($Path)) { throw 'InstallRoot is empty.' }
    $full = [IO.Path]::GetFullPath($Path).TrimEnd('\', '/')
    if ($full -match '^[A-Za-z]:$' -or $full -eq '') { throw "Refusing drive root: $Path" }

    $leaf = Split-Path -Leaf $full
    $isDefault = ($full -ieq $DefaultRoot)
    if (-not $isDefault -and $leaf -ine 'InternalInventoryRunner') {
        throw "Refusing path whose folder name is not InternalInventoryRunner: $full"
    }

    $programData = [Environment]::GetFolderPath('CommonApplicationData').TrimEnd('\')
    $insideProgramData = $full.StartsWith($programData + '\', [StringComparison]::OrdinalIgnoreCase)
    if (-not $isDefault -and -not $insideProgramData -and -not $AllowOutsideProgramData) {
        throw "Refusing path outside ProgramData without -Force: $full"
    }
    return $full
}

try {
    if (-not (Test-IsAdmin)) { throw 'Administrator rights are required. Re-run from an elevated PowerShell.' }

    $root = Resolve-SafeInstallRoot -Path $InstallRoot -AllowOutsideProgramData ([bool]$Force)
    $mode = if ($WhatIf) { 'WHATIF (no changes)' } else { 'LIVE' }
    Write-Step "Runner uninstall mode=$mode root=$root task=$TaskName keepData=$([bool]$KeepData)"
    Write-Host "Log file: $LogPath"

    if (-not $WhatIf -and -not $Force) {
        $answer = Read-Host 'Type YES to uninstall'
        if ($answer -cne 'YES') { Write-Step 'Confirmation not given; nothing changed.'; exit 1 }
    }

    # 1. Scheduled tasks (main + startup), tolerate missing.
    foreach ($name in @($TaskName, "$TaskName-Startup")) {
        & schtasks.exe /Query /TN $name *> $null
        if ($LASTEXITCODE -ne 0) { Write-Step "Task not present: $name"; continue }
        if ($WhatIf) { Write-Step "Would stop and delete task: $name"; continue }
        & schtasks.exe /End /TN $name *> $null
        & schtasks.exe /Delete /TN $name /F *> $null
        if ($LASTEXITCODE -eq 0) { Write-Step "Deleted task: $name" } else { Write-Step "WARN could not delete task: $name" }
    }

    # 2. Running runner process for this install only (matched by full script path in the command line).
    $mainScript = Join-Path $root 'scripts\runner_main.ps1'
    $procs = @()
    try {
        $procs = @(Get-CimInstance Win32_Process -Filter "Name='powershell.exe'" |
            Where-Object { $_.CommandLine -and $_.CommandLine.IndexOf($mainScript, [StringComparison]::OrdinalIgnoreCase) -ge 0 -and $_.ProcessId -ne $PID })
    } catch { Write-Step "Process lookup skipped: $($_.Exception.Message)" }
    foreach ($p in $procs) {
        if ($WhatIf) { Write-Step "Would stop runner process pid=$($p.ProcessId)"; continue }
        try { Stop-Process -Id $p.ProcessId -Force; Write-Step "Stopped runner process pid=$($p.ProcessId)" }
        catch { Write-Step "WARN could not stop pid=$($p.ProcessId)" }
    }
    if ($procs.Count -eq 0) { Write-Step 'No identifiable runner process running.' }

    # 3. Remove install root (or everything except data when -KeepData).
    if (-not (Test-Path -LiteralPath $root)) {
        Write-Step "Install root not found, nothing to remove: $root"
    } else {
        $keepNames = @('state', 'logs', 'data', 'outbox')
        $items = @(Get-ChildItem -LiteralPath $root -Force)
        if ($KeepData) {
            $items = @($items | Where-Object { $keepNames -notcontains $_.Name -and $_.Name -notlike '*backup*' })
        }
        foreach ($item in $items) {
            if ($KeepData -and $item.Name -eq 'config') {
                # keep only config backups, drop live config (contains site token)
                foreach ($f in @(Get-ChildItem -LiteralPath $item.FullName -Force -File | Where-Object { $_.Name -notlike '*backup*' -and $_.Name -notlike '*.bak*' })) {
                    if ($WhatIf) { Write-Step "Would remove $($f.FullName)" } else { Remove-Item -LiteralPath $f.FullName -Force }
                }
                continue
            }
            if ($WhatIf) { Write-Step "Would remove $($item.FullName)"; continue }
            Remove-Item -LiteralPath $item.FullName -Recurse -Force
        }
        if (-not $KeepData) {
            if ($WhatIf) { Write-Step "Would remove install root $root" }
            else { Remove-Item -LiteralPath $root -Recurse -Force; Write-Step "Removed install root $root" }
        } elseif (-not $WhatIf) {
            Write-Step "Kept data under $root (state, logs, data, config backups)"
        }
    }

    Write-Host ''
    Write-Host '--- Summary ---'
    $Summary | ForEach-Object { Write-Host " - $_" }
    Write-Host 'The runner will now show as silent in the portal. The portal record is NOT deleted.'
    Write-Host "Log: $LogPath"
    exit 0
}
catch {
    Write-Host "UNINSTALL FAILED: $($_.Exception.Message)"
    try { Add-Content -Path $LogPath -Value "FAILED: $($_.Exception.Message)" -Encoding UTF8 } catch { }
    exit 1
}
