[CmdletBinding()]
param()

# Behavioral test: extracts the cleanup functions from runner_main.ps1 (AST, no execution of the
# script's main body) and runs them against a throwaway folder tree with back-dated files.

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$RepoRoot = Resolve-Path (Join-Path $PSScriptRoot '..\..')
$RunnerMain = Join-Path $RepoRoot 'runner\scripts\runner_main.ps1'
$script:Failures = New-Object System.Collections.Generic.List[string]

function Assert-True {
    param([bool]$Condition, [string]$Message)
    if (-not $Condition) { $script:Failures.Add($Message) }
}

$parseErrors = $null
$ast = [System.Management.Automation.Language.Parser]::ParseFile($RunnerMain, [ref]$null, [ref]$parseErrors)
Assert-True ($parseErrors.Count -eq 0) 'runner_main.ps1 parses without errors'

$wanted = 'Ensure-Directory', 'Write-RunnerLog', 'Test-PathUnderRoot', 'Invoke-DirectOutboxFolderCleanup', 'Invoke-RunnerLogRotation', 'Invoke-RunnerLocalCleanup'
foreach ($name in $wanted) {
    $fn = $ast.Find({ param($n) $n -is [System.Management.Automation.Language.FunctionDefinitionAst] -and $n.Name -eq $name }, $true)
    Assert-True ($null -ne $fn) "function $name exists in runner_main.ps1"
    if ($fn) { . ([scriptblock]::Create($fn.Extent.Text)) }
}

$root = Join-Path ([IO.Path]::GetTempPath()) ('runner-cleanup-test-' + [guid]::NewGuid().ToString('N').Substring(0, 8))
$script:RunnerLogPath = Join-Path $root 'logs\runner-main.log'

function New-AgedFile {
    param([string]$Path, [int]$AgeDays, [int]$Bytes = 10)
    $dir = Split-Path -Parent $Path
    if (-not (Test-Path -LiteralPath $dir)) { New-Item -ItemType Directory -Path $dir -Force | Out-Null }
    [IO.File]::WriteAllBytes($Path, (New-Object byte[] $Bytes))
    (Get-Item -LiteralPath $Path).LastWriteTime = (Get-Date).AddDays(-1 * $AgeDays)
}

try {
    New-Item -ItemType Directory -Path $root -Force | Out-Null

    # inventory-results: 10 recent + 100 old csv, plus a non-csv that must never be touched
    for ($i = 0; $i -lt 10; $i++) { New-AgedFile (Join-Path $root "data\inventory-results\new-$i.csv") 1 }
    for ($i = 0; $i -lt 100; $i++) { New-AgedFile (Join-Path $root "data\inventory-results\old-$i.csv") (60 + $i) }
    New-AgedFile (Join-Path $root 'data\inventory-results\keep.txt') 400

    # never-touched areas
    New-AgedFile (Join-Path $root 'data\pending-upload\stuck-1.csv') 400
    New-AgedFile (Join-Path $root 'outbox\pending\p-1.csv') 400

    # data\logs: 40 old logs; logs\: active log + old bootstrap logs
    for ($i = 0; $i -lt 40; $i++) { New-AgedFile (Join-Path $root "data\logs\inventory-$i.log") (40 + $i) }
    New-AgedFile $script:RunnerLogPath 0 100
    for ($i = 0; $i -lt 15; $i++) { New-AgedFile (Join-Path $root "logs\bootstrap-update-$i.log") (100 + $i) }

    Invoke-RunnerLocalCleanup -RunnerRoot $root

    $csv = @(Get-ChildItem (Join-Path $root 'data\inventory-results') -Filter *.csv).Count
    Assert-True ($csv -eq 50) "inventory-results keeps newest 50 csv (found $csv)"
    $recentKept = @(Get-ChildItem (Join-Path $root 'data\inventory-results') -Filter 'new-*.csv').Count
    Assert-True ($recentKept -eq 10) 'all recent csv are kept'
    Assert-True (Test-Path (Join-Path $root 'data\inventory-results\keep.txt')) 'non-csv files are never deleted'
    Assert-True (Test-Path (Join-Path $root 'data\pending-upload\stuck-1.csv')) 'pending-upload is never touched'
    Assert-True (Test-Path (Join-Path $root 'outbox\pending\p-1.csv')) 'outbox\pending is never touched'

    $dl = @(Get-ChildItem (Join-Path $root 'data\logs') -Filter *.log).Count
    Assert-True ($dl -eq 30) "data\logs keeps newest 30 (found $dl)"

    Assert-True (Test-Path $script:RunnerLogPath) 'active runner-main.log is kept'
    # The newest-10 rule counts every .log in the folder, including the active runner-main.log.
    $allLogs = @(Get-ChildItem (Join-Path $root 'logs') -Filter '*.log').Count
    $boot = @(Get-ChildItem (Join-Path $root 'logs') -Filter 'bootstrap-update-*.log').Count
    Assert-True ($allLogs -eq 10) "logs\ keeps newest 10 .log files in total (found $allLogs)"
    Assert-True ($boot -eq 9) "logs\ keeps 9 old bootstrap logs next to the active log (found $boot)"

    # Cleanup is a no-op when folders are missing (must not throw)
    $empty = Join-Path ([IO.Path]::GetTempPath()) ('runner-cleanup-empty-' + [guid]::NewGuid().ToString('N').Substring(0, 8))
    New-Item -ItemType Directory -Path $empty -Force | Out-Null
    $threw = $false
    try { Invoke-RunnerLocalCleanup -RunnerRoot $empty } catch { $threw = $true }
    Assert-True (-not $threw) 'cleanup does not throw when folders are missing'
    [IO.Directory]::Delete($empty, $true)

    # Log rotation: below the limit nothing happens, above it rotates and keeps two generations
    Invoke-RunnerLogRotation -LogPath $script:RunnerLogPath -MaxBytes 1000
    Assert-True (Test-Path $script:RunnerLogPath) 'small log is not rotated'
    [IO.File]::WriteAllBytes($script:RunnerLogPath, (New-Object byte[] 2000))
    Invoke-RunnerLogRotation -LogPath $script:RunnerLogPath -MaxBytes 1000
    Assert-True ((-not (Test-Path $script:RunnerLogPath)) -and (Test-Path ($script:RunnerLogPath + '.1'))) 'large log is rotated to .1'
    [IO.File]::WriteAllBytes($script:RunnerLogPath, (New-Object byte[] 3000))
    Invoke-RunnerLogRotation -LogPath $script:RunnerLogPath -MaxBytes 1000
    Assert-True ((Get-Item ($script:RunnerLogPath + '.2')).Length -eq 2000 -and (Get-Item ($script:RunnerLogPath + '.1')).Length -eq 3000) 'rotation shifts .1 to .2'

    # Wiring: main body calls both functions once, after the mutex is held
    $text = Get-Content -Path $RunnerMain -Raw -Encoding UTF8
    $mutexAt = $text.IndexOf('$runMutex.WaitOne(0)')
    $callAt = $text.IndexOf('Invoke-RunnerLocalCleanup -RunnerRoot $runnerRoot')
    Assert-True ($mutexAt -gt 0 -and $callAt -gt $mutexAt) 'local cleanup runs after the overlap mutex is acquired'
    Assert-True ($text.Contains('Invoke-RunnerLogRotation -LogPath $script:RunnerLogPath')) 'log rotation is wired into the main run'
}
finally {
    if (Test-Path -LiteralPath $root) { [IO.Directory]::Delete($root, $true) }
}

if ($script:Failures.Count -gt 0) {
    foreach ($f in $script:Failures) { Write-Host "[FAIL] $f" }
    Write-Host 'Result: FAIL'
    exit 1
}

Write-Host '[OK] Runner local cleanup tests passed.'
Write-Host 'Result: PASS'
