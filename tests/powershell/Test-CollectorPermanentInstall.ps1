[CmdletBinding()]
param()

# Static guard: the collector scheduled task must run from a permanent copy, never from the kit
# folder. The launchers stage the kit in %LOCALAPPDATA%\Temp and delete it after the install, so a
# task that points at the stage silently dies (regression found during the SITE-BHX1C rollout).

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$RepoRoot = Resolve-Path (Join-Path $PSScriptRoot '..\..')
$InstallerPath = Join-Path $RepoRoot 'collector\install_collector.ps1'
$script:Failures = New-Object System.Collections.Generic.List[string]

function Assert-True {
    param([bool]$Condition, [string]$Message)
    if (-not $Condition) { $script:Failures.Add($Message) }
}

$parseErrors = $null
[void][System.Management.Automation.Language.Parser]::ParseFile($InstallerPath, [ref]$null, [ref]$parseErrors)
Assert-True ($parseErrors.Count -eq 0) 'install_collector.ps1 parses without errors'

$text = Get-Content -Path $InstallerPath -Raw -Encoding UTF8

Assert-True ($text.Contains("Join-Path `$env:ProgramData 'InternalInventoryCollector\app'")) 'installs the collector under ProgramData\InternalInventoryCollector\app'
Assert-True ($text.Contains('Copy-Item -Path $item.FullName -Destination $InstalledRoot -Recurse -Force')) 'copies the kit collector files to the permanent folder'
Assert-True ($text.Contains("Copy-Item -Path `$sourceConfig -Destination (Join-Path `$InstalledRoot 'collector_config.json') -Force")) 'copies collector_config.json next to run_collector_hidden.pyw'
Assert-True ($text.Contains('$CollectorRoot = $InstalledRoot')) 'switches CollectorRoot to the permanent copy'
Assert-True ($text.Contains('$ConfigPath = Join-Path $InstalledRoot')) 'switches ConfigPath to the permanent copy'
Assert-True ($text.Contains("'*S-1-5-32-544:(OI)(CI)F'")) 'locks the folder (site token) to Administrators and SYSTEM'
Assert-True (-not $text.Contains('/grant ''*S-1-5-32-545')) 'does not grant Users access to the collector folder'

# schtasks quoting: a Python path with spaces must survive Windows PowerShell 5.1 argument passing.
Assert-True ($text.Contains("`$taskRunArg = '`"' + (`$taskRun -replace '`"', '\`"') + '`"'")) 'escapes inner quotes of the task command line'
Assert-True (-not $text.Contains('/TR $taskRun ')) 'schtasks always receives the escaped task command line'
Assert-True ([regex]::Matches($text, '/TR \$taskRunArg ').Count -eq 4) 'all four schtasks calls use the escaped command line'

# Ordering: the copy must happen before the task command line is built from CollectorRoot.
$copyAt = $text.IndexOf('$CollectorRoot = $InstalledRoot')
$launcherAt = $text.IndexOf('$hiddenLauncherPath = Join-Path $CollectorRoot')
$taskRunAt = $text.IndexOf('$taskRun =')
Assert-True ($copyAt -gt 0 -and $launcherAt -gt $copyAt) 'permanent copy happens before the hidden launcher path is resolved'
Assert-True ($copyAt -gt 0 -and $taskRunAt -gt $copyAt) 'permanent copy happens before the task command line is built'

if ($script:Failures.Count -gt 0) {
    foreach ($f in $script:Failures) { Write-Host "[FAIL] $f" }
    Write-Host 'Result: FAIL'
    exit 1
}

Write-Host '[OK] Collector permanent-install static tests passed.'
Write-Host 'Result: PASS'
