[CmdletBinding()]
param()

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$RepoRoot = Resolve-Path (Join-Path $PSScriptRoot '..\..')
$ScriptPath = Join-Path $RepoRoot 'tools\Prepare-MariaDbRehearsalFilesystem.ps1'
$DocsToCheck = @(
    (Join-Path $RepoRoot 'docs\MARIADB_REHEARSAL_ENVIRONMENT_SETUP_CHECKLIST.md'),
    (Join-Path $RepoRoot 'docs\MARIADB_REHEARSAL_ENVIRONMENT_PREP.md')
)

$script:Failures = New-Object System.Collections.Generic.List[string]

function Assert-True {
    param(
        [bool]$Condition,
        [string]$Message
    )

    if (-not $Condition) {
        $script:Failures.Add($Message)
    }
}

function Assert-Contains {
    param(
        [string]$Text,
        [string]$Needle,
        [string]$Message
    )

    Assert-True -Condition ($Text.Contains($Needle)) -Message $Message
}

function Assert-NotContains {
    param(
        [string]$Text,
        [string]$Needle,
        [string]$Message
    )

    Assert-True -Condition (-not $Text.Contains($Needle)) -Message $Message
}

function Assert-Matches {
    param(
        [string]$Text,
        [string]$Pattern,
        [string]$Message
    )

    Assert-True -Condition ([regex]::IsMatch($Text, $Pattern, [Text.RegularExpressions.RegexOptions]::Singleline)) -Message $Message
}

if (-not (Test-Path $ScriptPath)) {
    throw "Helper script not found: $ScriptPath"
}

$scriptText = Get-Content -Path $ScriptPath -Raw -Encoding UTF8

Assert-Contains $scriptText '[switch]$Execute' 'defines explicit -Execute switch'
Assert-Contains $scriptText 'Running in dry-run mode' 'dry-run is the default mode'
Assert-Contains $scriptText 'DRY-RUN:' 'prints planned actions in dry-run mode'
Assert-Contains $scriptText 'Use -Execute on Supermicro after review' 'requires explicit execution on Supermicro'

foreach ($requiredPath in @(
    'D:\inventory-rehearsal\laravel',
    'D:\inventory-rehearsal\backups',
    'D:\inventory-rehearsal\backups\sqlite',
    'D:\inventory-rehearsal\backups\raw_archive',
    'D:\inventory-rehearsal\backups\downloads',
    'D:\inventory-rehearsal\backups\mariadb_dumps',
    'D:\inventory-rehearsal\backups\command_outputs_redacted',
    'D:\inventory-rehearsal\source-copy',
    'D:\inventory-rehearsal\raw_archive',
    'D:\inventory-rehearsal\downloads',
    'D:\inventory-rehearsal\logs',
    'D:\inventory-rehearsal\notes'
)) {
    Assert-Contains $scriptText $requiredPath "contains required directory $requiredPath"
}

Assert-Contains $scriptText 'APP_ENV=local' 'contains placeholder env APP_ENV'
Assert-Contains $scriptText 'APP_DEBUG=true' 'contains placeholder env APP_DEBUG'
Assert-Contains $scriptText 'APP_URL=http://127.0.0.1:8090' 'contains local-only placeholder APP_URL'
Assert-Contains $scriptText 'DB_CONNECTION=mysql' 'contains placeholder DB connection'
Assert-Contains $scriptText 'DB_DATABASE=inventory_rehearsal' 'contains placeholder DB name'
Assert-Contains $scriptText 'DB_USERNAME=inventory_rehearsal_app' 'contains placeholder DB user'
Assert-Contains $scriptText 'DB_PASSWORD=<rehearsal-db-password-not-committed>' 'contains placeholder DB password'
Assert-NotContains $scriptText 'key:generate' 'does not generate APP_KEY'
Assert-NotContains $scriptText 'APP_KEY=' 'does not include an APP_KEY value'

Assert-Contains $scriptText "'.env'" 'excludes .env'
Assert-Contains $scriptText "'.env.*'" 'excludes .env.*'
Assert-Contains $scriptText 'database\database.sqlite' 'refuses/excludes live database file'
Assert-Contains $scriptText 'storage\app\inventory\raw_archive' 'excludes source raw archive from Laravel source copy'
Assert-Contains $scriptText 'storage\app\inventory\downloads' 'excludes source downloads from Laravel source copy'
Assert-Contains $scriptText 'Refusing to copy live SQLite database directly' 'refuses live SQLite database as source backup'
Assert-Contains $scriptText "'.sqlite', '.db'" 'allows only SQLite-like backup extensions'

foreach ($forbiddenPattern in @(
    '(?im)^\s*&?\s*mysql(\.exe)?\b',
    '(?im)^\s*&?\s*mariadb(\.exe)?\b',
    'php\s+artisan\s+migrate\b',
    'php\s+artisan\s+migrate:fresh\b',
    'php\s+artisan\s+key:generate\b',
    'php\s+artisan\s+serve\b',
    'php\s+artisan\s+inventory:build-site-kit\b',
    'php\s+artisan\s+inventory:backup\b',
    'config:clear',
    'config:cache',
    'Invoke-WebRequest',
    'install_direct_https_runner',
    'install_collector',
    'New-ScheduledTask',
    'Register-ScheduledTask',
    'Set-ScheduledTask',
    'Unregister-ScheduledTask'
)) {
    Assert-True -Condition (-not [regex]::IsMatch($scriptText, $forbiddenPattern)) -Message "does not contain forbidden pattern $forbiddenPattern"
}

Assert-Contains $scriptText 'Do not install database software' 'contains database installation prohibition'
Assert-Contains $scriptText 'does not install database software' 'documents helper boundary'
Assert-Contains $scriptText 'does not print file contents' 'contains no-content-dump intent'
Assert-Contains $scriptText 'Do not print raw CSV contents' 'contains raw CSV prohibition'
Assert-Contains $scriptText 'DB credentials are never committed' 'contains credential prohibition'
Assert-Contains $scriptText 'Execution from the IT-ADMIN development path is refused' 'refuses execute mode from IT-ADMIN path'
Assert-Contains $scriptText 'The live pilot hostname is refused as rehearsal APP_URL' 'refuses live pilot hostname'

$resultLineMatches = [regex]::Matches($scriptText, 'Write-Host "Result: \$script:Result"')
Assert-True -Condition ($resultLineMatches.Count -eq 1) -Message 'emits exactly one final Result line in the script'

foreach ($docPath in $DocsToCheck) {
    if (-not (Test-Path $docPath)) {
        $script:Failures.Add("Doc not found: $docPath")
        continue
    }

    $docText = Get-Content -Path $docPath -Raw -Encoding UTF8
    Assert-Contains $docText 'Prepare-MariaDbRehearsalFilesystem.ps1' "docs reference helper script in $docPath"
    Assert-Contains $docText 'manual' "docs mention manual copy/run in $docPath"
    Assert-Contains $docText 'Supermicro' "docs mention Supermicro in $docPath"
}

if ($script:Failures.Count -gt 0) {
    foreach ($failure in $script:Failures) {
        Write-Host "[FAIL] $failure"
    }

    Write-Host "Result: FAIL"
    exit 1
}

Write-Host '[OK] MariaDB rehearsal filesystem helper static tests passed.'
Write-Host 'Result: PASS'
