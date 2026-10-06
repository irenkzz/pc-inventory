[CmdletBinding()]
param()

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$RepoRoot = Resolve-Path (Join-Path $PSScriptRoot '..\..')
$Scripts = Join-Path $RepoRoot 'runner\scripts'
$main = Get-Content -Path (Join-Path $Scripts 'runner_main.ps1') -Raw -Encoding UTF8
$boot = Get-Content -Path (Join-Path $Scripts 'bootstrap_update_runner.ps1') -Raw -Encoding UTF8
$inst = Get-Content -Path (Join-Path $Scripts 'install_runner.ps1') -Raw -Encoding UTF8
$scan = Get-Content -Path (Join-Path $Scripts 'scanner_core_v4.ps1') -Raw -Encoding UTF8

$script:Failures = New-Object System.Collections.Generic.List[string]
function Assert-True { param([bool]$Condition, [string]$Message) if (-not $Condition) { $script:Failures.Add($Message) } }

Assert-True ($main.Contains("Global\InternalInventoryRunner")) 'runner_main uses named Global mutex'
Assert-True ($main.Contains('AbandonedMutexException') -and $main.Contains('ReleaseMutex')) 'mutex handles abandoned and is released'
Assert-True ($main.Contains('Tls12')) 'runner_main enables Tls12'
Assert-True (-not $main.Contains('$env:PUBLIC')) 'update staging is not under PUBLIC'
Assert-True ($main.Contains("'update-staging'")) 'update staging is under runner root'
Assert-True ($main.Contains('$qBootstrap')) 'launcher paths are single-quote escaped'
foreach ($pair in @(@('runner_main', $main), @('bootstrap', $boot))) {
    Assert-True ($pair[1].Contains("+ '.tmp'") -and $pair[1].Contains('Move-Item -Path $tmp')) "$($pair[0]) writes JSON via .tmp + Move-Item"
}
foreach ($pair in @(@('bootstrap', $boot), @('install_runner', $inst))) {
    Assert-True (-not ($pair[1] -match "grant\s+'\*S-1-5-32-545")) "$($pair[0]) does not grant Users on install root"
    Assert-True ($pair[1].Contains('/inheritance:r') -and $pair[1].Contains('S-1-5-32-544')) "$($pair[0]) locks root to Administrators+SYSTEM"
}
Assert-True ($main.Contains('next_attempt_at') -and $main.Contains('Get-DirectBackoffDelayMinutes') -and $main.Contains('DirectBackoffCapMinutes = 360')) 'direct outbox has exponential backoff with cap'
Assert-True ($main.Contains('401, 403') -and $main.Contains('-Longest')) 'direct outbox 401/403 use longest backoff'
Assert-True ($main.Contains('DirectOutboxMaxPending = 200') -and $main.Contains('Remove-DirectPendingOverflow')) 'direct pending outbox is count-capped'
Assert-True ($scan.Contains('WaitForExit(30000)')) 'smartctl calls are time-bounded'

foreach ($f in 'runner_main', 'bootstrap_update_runner', 'install_runner', 'scanner_core_v4') {
    $errs = $null; $tokens = $null
    [void][System.Management.Automation.Language.Parser]::ParseFile((Join-Path $Scripts "$f.ps1"), [ref]$tokens, [ref]$errs)
    Assert-True ($errs.Count -eq 0) "$f.ps1 parses without errors"
}

if ($script:Failures.Count -gt 0) {
    $script:Failures | ForEach-Object { Write-Host "[FAIL] $_" }
    Write-Host 'Result: FAIL'
    exit 1
}
Write-Host 'Result: PASS'
