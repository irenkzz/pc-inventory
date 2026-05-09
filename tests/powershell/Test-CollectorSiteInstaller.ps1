[CmdletBinding()]
param()

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$RepoRoot = Resolve-Path (Join-Path $PSScriptRoot '..\..')
$InstallerPath = Join-Path $RepoRoot 'collector\install_collector_site.ps1'
$DirectInstallerPath = Join-Path $RepoRoot 'runner\scripts\install_direct_https_runner.ps1'

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

if (-not (Test-Path $InstallerPath)) {
    throw "Installer not found: $InstallerPath"
}

$scriptText = Get-Content -Path $InstallerPath -Raw -Encoding UTF8

Assert-Contains $scriptText 'Collector config accepted for siteId' 'accepts valid collector-share config'
Assert-Contains $scriptText 'Direct HTTPS runner config refused' 'refuses Direct HTTPS runner config'
Assert-Contains $scriptText 'Collector config not found' 'refuses missing collector config'
Assert-Contains $scriptText 'site_id is required' 'refuses missing site identity'
Assert-Contains $scriptText 'collector_name is required' 'refuses missing collector identity'
Assert-Contains $scriptText "serverUri.Scheme -ne 'https'" 'accepts only HTTPS serverBaseUrl'
Assert-Contains $scriptText 'Plain http:// is refused' 'refuses HTTP serverBaseUrl'
Assert-Contains $scriptText "hostName -eq 'localhost'" 'refuses localhost serverBaseUrl'
Assert-Contains $scriptText "hostName -eq '127.0.0.1'" 'refuses 127.0.0.1 serverBaseUrl'
Assert-Contains $scriptText 'inventory.example.local' 'refuses inventory.example.local serverBaseUrl'
Assert-Contains $scriptText "*example*" 'refuses example serverBaseUrl'
Assert-Contains $scriptText "*placeholder*" 'refuses placeholder serverBaseUrl'
Assert-NotContains $scriptText 'SkipCertificateCheck' 'does not contain SkipCertificateCheck'
Assert-Contains $scriptText 'sharedRoot/share_root is missing or blank' 'validates sharedRoot presence'
Assert-Contains $scriptText 'sharedRoot is missing or unreachable' 'fails safely when sharedRoot is missing'
Assert-Contains $scriptText '.collector-site-install-probe-' 'uses a clearly temporary probe file'
Assert-Contains $scriptText 'Set-Content -Path $probePath' 'performs only a safe temporary write probe'
Assert-Contains $scriptText 'Remove-Item -Path $probePath -Force' 'removes temporary probe file'
Assert-NotContains $scriptText 'inventory-results' 'does not write operational CSV files'
Assert-NotContains $scriptText 'command-acks' 'does not write operational ACK files'
Assert-NotContains $scriptText 'runner-heartbeats' 'does not write operational heartbeat files'
Assert-NotContains $scriptText '/api/collector' 'does not call collector APIs'
Assert-NotContains $scriptText '/commands' 'does not call Laravel command APIs'
Assert-NotContains $scriptText 'repair_update' 'does not trigger repair/update'
Assert-Matches $scriptText 'Test-CollectorConfig -Config \$config.*?Invoke-HealthCheck.*?Test-ShareReadWrite.*?Invoke-CollectorInstaller' 'delegates to install_collector only after validations pass'
Assert-Contains $scriptText 'install_collector.ps1' 'delegates to existing install_collector.ps1'
Assert-Contains $scriptText 'Test-ScheduledTaskExists' 'verifies scheduled task after install'
Assert-Contains $scriptText 'Runner staging area was not found' 'warns clearly when runner staging is missing'
Assert-Contains $scriptText 'Installed collector config exists' 'verifies installed collector config after install'
Assert-NotContains $scriptText 'Write-InstallerLine ''INFO'' "siteToken' 'does not print siteToken'
Assert-NotContains $scriptText 'Write-InstallerLine ''INFO'' "token' 'does not print token fields'
Assert-NotContains $scriptText 'ConvertTo-Json' 'does not dump raw config'
Assert-NotContains $scriptText 'APP_KEY' 'does not print APP_KEY values'
Assert-NotContains $scriptText '.env' 'does not print .env values'

if (Test-Path $DirectInstallerPath) {
    $directInstallerText = Get-Content -Path $DirectInstallerPath -Raw -Encoding UTF8
    Assert-NotContains $directInstallerText 'install_collector_site.ps1' 'does not modify Direct HTTPS installer script'
}

$resultLineMatches = [regex]::Matches($scriptText, 'Result: \$script:Result')
Assert-True -Condition ($resultLineMatches.Count -eq 1) -Message 'emits exactly one final Result line in the script'

if ($script:Failures.Count -gt 0) {
    foreach ($failure in $script:Failures) {
        Write-Host "[FAIL] $failure"
    }

    Write-Host "Result: FAIL"
    exit 1
}

Write-Host '[OK] Collector-site installer static tests passed.'
Write-Host 'Result: PASS'
