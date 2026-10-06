[CmdletBinding()]
param()

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$RepoRoot = Resolve-Path (Join-Path $PSScriptRoot '..\..')
$Uninstall = Join-Path $RepoRoot 'runner\scripts\uninstall_runner.ps1'
$Health = Join-Path $RepoRoot 'runner\scripts\check_runner_health.ps1'
$Failures = New-Object System.Collections.Generic.List[string]

function Assert-True {
    param([bool]$Condition, [string]$Message)
    if (-not $Condition) { $Failures.Add($Message) }
}

foreach ($path in @($Uninstall, $Health)) {
    $name = Split-Path -Leaf $path
    $tokens = $null; $errors = $null
    [void][System.Management.Automation.Language.Parser]::ParseFile($path, [ref]$tokens, [ref]$errors)
    Assert-True ($errors.Count -eq 0) "$name has parse errors: $($errors | ForEach-Object { $_.Message })"

    $text = Get-Content -LiteralPath $path -Raw
    Assert-True ($text -notmatch '\?\?') "$name uses ?? (not PS5.1 safe)"
    Assert-True ($text -notmatch '\?\.\w') "$name uses ?. (not PS5.1 safe)"
    Assert-True ($text -notmatch '-Parallel') "$name uses -Parallel (not PS5.1 safe)"
}

$u = Get-Content -LiteralPath $Uninstall -Raw
Assert-True ($u.Contains('Resolve-SafeInstallRoot')) 'uninstall lacks path guard'
Assert-True ($u.Contains('Refusing drive root')) 'uninstall does not refuse drive roots'
Assert-True ($u.Contains('InternalInventoryRunner') -and $u.Contains('Refusing path outside ProgramData')) 'uninstall guard incomplete'
Assert-True ($u.Contains('[switch]$WhatIf')) 'uninstall lacks -WhatIf'
Assert-True ($u.Contains('[switch]$Force')) 'uninstall lacks -Force'
Assert-True ($u.Contains('[switch]$KeepData')) 'uninstall lacks -KeepData'
Assert-True ($u.Contains('Type YES to uninstall')) 'uninstall lacks confirmation prompt'
Assert-True ($u.Contains('Test-IsAdmin')) 'uninstall lacks admin check'

$h = Get-Content -LiteralPath $Health -Raw
Assert-True ($h -notmatch '(?i)(Write-Host|Write-Output|Write-Warning)[^\r\n]*\$siteToken') 'health prints siteToken variable'
Assert-True ($h -notmatch '(?i)\$siteToken') 'health references a siteToken variable'
Assert-True ($h.Contains('[REDACTED]')) 'health lacks redaction'
Assert-True ($h.Contains('[switch]$Json')) 'health lacks -Json'
Assert-True ($h.Contains('-TimeoutSec 10')) 'health lacks 10 s timeout'

if ($Failures.Count -gt 0) {
    $Failures | ForEach-Object { Write-Host "FAIL: $_" }
    exit 1
}
Write-Host 'PASS: runner uninstall/health static tests'
exit 0
