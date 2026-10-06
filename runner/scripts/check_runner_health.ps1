[CmdletBinding()]
param(
    [string]$InstallRoot = 'C:\ProgramData\InternalInventoryRunner',
    [string]$TaskName = 'InternalInventoryRunner',
    [string]$ManifestPath = '',
    [int]$ClockSkewWarnSeconds = 120,
    [int]$MinFreeGb = 2,
    [int]$PendingWarn = 20,
    [switch]$Json
)

# Read-only health check. Exit 0 OK, 1 WARN, 2 FAIL. Secrets are never read into output.
Set-StrictMode -Version Latest
$ErrorActionPreference = 'Continue'

$checks = New-Object System.Collections.Generic.List[object]
function Add-Check {
    param([string]$Name, [string]$Status, [string]$Detail)
    $checks.Add([pscustomobject]@{ name = $Name; status = $Status; detail = $Detail })
}

function Read-Json {
    param([string]$Path)
    if (-not (Test-Path -LiteralPath $Path)) { return $null }
    try { return (Get-Content -LiteralPath $Path -Raw -Encoding UTF8 | ConvertFrom-Json) } catch { return $null }
}
function Get-Prop {
    param($Obj, [string]$Name)
    if ($null -eq $Obj) { return '' }
    $p = $Obj.PSObject.Properties[$Name]
    if ($null -eq $p -or $null -eq $p.Value) { return '' }
    return [string]$p.Value
}

# Scheduled task
try {
    $task = Get-ScheduledTask -TaskName $TaskName -ErrorAction Stop
    $info = Get-ScheduledTaskInfo -TaskName $TaskName -ErrorAction Stop
    $st = 'OK'
    if ($task.State -eq 'Disabled') { $st = 'FAIL' }
    elseif ($info.LastTaskResult -notin @(0, 267009, 267011)) { $st = 'WARN' }
    Add-Check 'scheduled_task' $st ("state={0} last_result={1} last_run={2} next_run={3}" -f $task.State, $info.LastTaskResult, $info.LastRunTime, $info.NextRunTime)
} catch {
    Add-Check 'scheduled_task' 'FAIL' "Task not found or unreadable: $TaskName"
}

# State
$state = Read-Json (Join-Path $InstallRoot 'state\runner-state.json')
if ($null -eq $state) {
    Add-Check 'state' 'FAIL' 'runner-state.json missing or unreadable'
} else {
    $inv = Get-Prop $state 'last_inventory_status'
    $upl = Get-Prop $state 'last_upload_status'
    $seen = Get-Prop $state 'last_seen_at'
    $st = 'OK'
    if ($inv -match 'fail' -or $upl -match 'fail') { $st = 'WARN' }
    if ($seen) {
        $dt = [datetime]::MinValue
        if ([datetime]::TryParse($seen, [ref]$dt) -and ((Get-Date) - $dt).TotalHours -gt 2) { $st = 'WARN' }
    } else { $st = 'WARN' }
    Add-Check 'state' $st "last_inventory_status=$inv last_upload_status=$upl last_seen=$seen"
}

# Outbox
$pendingDir = Join-Path $InstallRoot 'data\outbox\pending'
if (-not (Test-Path -LiteralPath $pendingDir)) { $pendingDir = Join-Path $InstallRoot 'outbox\pending' }
if (Test-Path -LiteralPath $pendingDir) {
    $files = @(Get-ChildItem -LiteralPath $pendingDir -File -ErrorAction SilentlyContinue)
    $oldest = ''
    $st = 'OK'
    if ($files.Count -gt 0) {
        $age = [int]((Get-Date) - ($files | Sort-Object LastWriteTime | Select-Object -First 1).LastWriteTime).TotalMinutes
        $oldest = "${age}m"
        if ($files.Count -ge $PendingWarn -or $age -gt 1440) { $st = 'WARN' }
    }
    Add-Check 'outbox' $st "pending_files=$($files.Count) oldest_age=$oldest"
} else {
    Add-Check 'outbox' 'OK' 'no pending directory (nothing queued)'
}

# Disk
try {
    $drive = (Split-Path -Qualifier $InstallRoot).TrimEnd(':')
    $free = [math]::Round((Get-PSDrive -Name $drive -ErrorAction Stop).Free / 1GB, 1)
    Add-Check 'disk' $(if ($free -lt $MinFreeGb) { 'WARN' } else { 'OK' }) "free_gb=$free drive=$drive"
} catch { Add-Check 'disk' 'WARN' 'could not read free space' }

# Config (redacted: only transport mode and host are surfaced)
$config = Read-Json (Join-Path $InstallRoot 'config\runner-config.json')
$serverHost = ''
$baseUrl = ''
if ($null -eq $config) {
    Add-Check 'config' 'FAIL' 'runner-config.json missing or unreadable'
} else {
    $mode = Get-Prop $config 'transport_mode'
    $baseUrl = Get-Prop $config 'serverBaseUrl'
    try { if ($baseUrl) { $serverHost = ([Uri]$baseUrl).Host } } catch { }
    $tokenState = if ((Get-Prop $config 'siteToken')) { 'present=[REDACTED]' } else { 'absent' }
    Add-Check 'config' 'OK' "transport_mode=$mode server_host=$serverHost token=$tokenState"
}

# Clock skew via HTTP Date header
if ($baseUrl) {
    try {
        $resp = Invoke-WebRequest -Uri ($baseUrl.TrimEnd('/') + '/health') -Method Get -UseBasicParsing -TimeoutSec 10
        $dateHeader = [string]$resp.Headers['Date']
        $server = [datetimeoffset]::Parse($dateHeader)
        $skew = [int][math]::Abs(([datetimeoffset]::UtcNow - $server).TotalSeconds)
        Add-Check 'clock_skew' $(if ($skew -gt $ClockSkewWarnSeconds) { 'WARN' } else { 'OK' }) "skew_seconds=$skew"
    } catch {
        Add-Check 'clock_skew' 'WARN' 'server not reachable or no Date header'
    }
} else {
    Add-Check 'clock_skew' 'WARN' 'no serverBaseUrl configured'
}

# Version vs manifest
if (-not $ManifestPath) { $ManifestPath = Join-Path $PSScriptRoot '..\manifest\runner-manifest.json' }
$manifest = Read-Json $ManifestPath
if ($null -eq $manifest) {
    Add-Check 'version' 'OK' 'manifest not present; comparison skipped'
} else {
    $installed = Get-Prop $config 'runnerVersion'
    $expected = Get-Prop $manifest 'runner_version'
    Add-Check 'version' $(if ($installed -and $expected -and $installed -ne $expected) { 'WARN' } else { 'OK' }) "installed=$installed manifest=$expected"
}

$overall = 'OK'
if (@($checks | Where-Object { $_.status -eq 'WARN' }).Count -gt 0) { $overall = 'WARN' }
if (@($checks | Where-Object { $_.status -eq 'FAIL' }).Count -gt 0) { $overall = 'FAIL' }

if ($Json) {
    [pscustomobject]@{ overall = $overall; computer = $env:COMPUTERNAME; checked_at = (Get-Date).ToString('s'); checks = $checks } | ConvertTo-Json -Depth 4
} else {
    foreach ($c in $checks) { Write-Host ("[{0}] {1}: {2}" -f $c.status, $c.name, $c.detail) }
    Write-Host "Overall: $overall"
}
switch ($overall) { 'OK' { exit 0 } 'WARN' { exit 1 } default { exit 2 } }
