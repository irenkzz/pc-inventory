[CmdletBinding()]
param()

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$RepoRoot = Resolve-Path (Join-Path $PSScriptRoot '..\..')
$InstallerPath = Join-Path $RepoRoot 'runner\scripts\install_direct_https_runner.ps1'
$CollectorFiles = @(
    (Join-Path $RepoRoot 'collector\install_collector.ps1'),
    (Join-Path $RepoRoot 'collector\relay.py'),
    (Join-Path $RepoRoot 'collector\collector_config.sample.json')
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

if (-not (Test-Path $InstallerPath)) {
    throw "Installer not found: $InstallerPath"
}

$scriptText = Get-Content -Path $InstallerPath -Raw -Encoding UTF8

Assert-Contains $scriptText "transportMode -ne 'direct_https'" 'accepts direct_https transport by making it the only allowed mode'
Assert-Contains $scriptText "collector_share config refused" 'refuses collector_share transport mode'
Assert-Contains $scriptText 'transport_mode is missing or blank' 'refuses missing transport_mode'
Assert-Contains $scriptText "serverUri.Scheme -ne 'https'" 'accepts only HTTPS serverBaseUrl'
Assert-Contains $scriptText 'Plain http:// is refused' 'refuses HTTP serverBaseUrl'
Assert-Contains $scriptText "hostName -eq 'localhost'" 'refuses localhost serverBaseUrl'
Assert-Contains $scriptText "hostName -eq '127.0.0.1'" 'refuses 127.0.0.1 serverBaseUrl'
Assert-Contains $scriptText "inventory.example.local" 'refuses inventory.example.local serverBaseUrl'
Assert-Contains $scriptText "*example*" 'refuses example serverBaseUrl'
Assert-Contains $scriptText "*placeholder*" 'refuses placeholder serverBaseUrl'
Assert-NotContains $scriptText 'SkipCertificateCheck' 'does not contain SkipCertificateCheck'
Assert-NotContains $scriptText 'scan_now' 'does not trigger scan_now'
Assert-NotContains $scriptText 'manual_scan' 'does not trigger manual_scan'
Assert-NotContains $scriptText '/api/direct-runner' 'does not call Direct HTTPS command APIs'
Assert-NotContains $scriptText '/commands' 'does not call command API paths'
Assert-NotContains $scriptText 'payload_json' 'does not print command payload JSON'
Assert-NotContains $scriptText 'APP_KEY' 'does not print APP_KEY values'
Assert-NotContains $scriptText '.env' 'does not print .env values'
Assert-Contains $scriptText 'ConvertTo-RedactedGuidSummary' 'redacts runnerGuid in output'
Assert-Contains $scriptText 'present ending ' 'prints only runnerGuid suffix'
Assert-NotContains $scriptText 'Write-InstallerLine ''INFO'' "siteToken' 'does not print siteToken'
Assert-NotContains $scriptText 'Write-InstallerLine ''INFO'' "token' 'does not print token fields'
Assert-Contains $scriptText 'Set-JsonValue -Object $installedConfig -Name ''runnerGuid'' -Value $existingRunnerGuid' 'preserves existing runnerGuid'
Assert-Matches $scriptText 'if \(-not \(Test-Admin\)\).*?throw ''Administrator PowerShell is required' 'fails before install when not elevated'
Assert-Matches $scriptText 'Test-DirectConfig -Config \$config.*?Invoke-HealthCheck.*?Invoke-RunnerInstaller' 'calls install_runner only after validation and health checks'
Assert-Contains $scriptText 'scripts\install_runner.ps1' 'delegates to existing install_runner.ps1'
Assert-Contains $scriptText 'Test-ScheduledTaskExists' 'verifies scheduled task after install'
Assert-Contains $scriptText 'Installed config exists and remains direct_https' 'verifies installed config after install'
Assert-Contains $scriptText 'Invoke-WebRequest -Uri $healthUrl -UseBasicParsing' 'checks HTTPS /health with normal TLS validation'

Assert-Contains $scriptText 'Windows does not trust the server certificate for' 'gives a clear TLS trust failure message'
Assert-Contains $scriptText 'Import the internal CA certificate on this PC' 'tells the user how to fix TLS trust'
Assert-Contains $scriptText 'Invoke-InitialRunnerScan' 'runs the installed runner once after install'
Assert-Contains $scriptText 'runner_main.ps1' 'uses the installed runner_main.ps1 for the first scan'
Assert-Contains $scriptText "'last_inventory_status'" 'checks last_inventory_status after first scan'
Assert-Contains $scriptText 'ConvertTo-RedactedText' 'redacts last_error before reporting'
Assert-Matches $scriptText 'Invoke-InitialRunnerScan -InstallRoot.*?\$script:Result = ''PASS''' 'reports PASS only after the initial scan check'
Assert-Contains $scriptText '$ResultPath' 'writes a result file for the launcher'
Assert-Contains $scriptText 'PauseOnFail' 'keeps the elevated window open on failure'

$resultLineMatches =[regex]::Matches($scriptText, 'Result: \$script:Result')
Assert-True -Condition ($resultLineMatches.Count -eq 1) -Message 'emits exactly one final Result line in the script'

foreach ($collectorFile in $CollectorFiles) {
    if (Test-Path $collectorFile) {
        $collectorText = Get-Content -Path $collectorFile -Raw -Encoding UTF8
        Assert-NotContains $collectorText 'install_direct_https_runner.ps1' "does not modify collector script path references in $collectorFile"
    }
}

if ($script:Failures.Count -gt 0) {
    foreach ($failure in $script:Failures) {
        Write-Host "[FAIL] $failure"
    }

    Write-Host "Result: FAIL"
    exit 1
}

Write-Host '[OK] Direct HTTPS installer static tests passed.'
Write-Host 'Result: PASS'
