[CmdletBinding()]
param()

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$RepoRoot = Resolve-Path (Join-Path $PSScriptRoot '..\..')
$Scripts = Join-Path $RepoRoot 'runner\scripts'
. (Join-Path $Scripts 'verify_update_package.ps1')

$script:Failures = New-Object System.Collections.Generic.List[string]
function Assert-True { param([bool]$Condition, [string]$Message) if (-not $Condition) { $script:Failures.Add($Message) } }

$work = Join-Path ([IO.Path]::GetTempPath()) ('signed-update-test-' + [guid]::NewGuid().ToString('N'))
New-Item -ItemType Directory -Path $work | Out-Null

function New-TestRsa { param([int]$Bits = 2048) return New-Object System.Security.Cryptography.RSACryptoServiceProvider($Bits) }

function New-Package {
    param([string]$Name, [System.Security.Cryptography.RSACryptoServiceProvider]$SignWith, [string[]]$ExtraManifestPaths = @())
    $root = Join-Path $work $Name
    New-Item -ItemType Directory -Path (Join-Path $root 'scripts'), (Join-Path $root 'manifest'), (Join-Path $root 'config') -Force | Out-Null
    [IO.File]::WriteAllText((Join-Path $root 'scripts\a.ps1'), 'Write-Host a')
    [IO.File]::WriteAllText((Join-Path $root 'scripts\b.ps1'), 'Write-Host b')
    [IO.File]::WriteAllText((Join-Path $root 'config\c.json'), '{}')
    $files = @()
    foreach ($rel in 'scripts/a.ps1', 'scripts/b.ps1', 'config/c.json') {
        $full = Join-Path $root ($rel -replace '/', '\')
        $sha = [Security.Cryptography.SHA256]::Create()
        $hash = [BitConverter]::ToString($sha.ComputeHash([IO.File]::ReadAllBytes($full))).Replace('-', '').ToLowerInvariant()
        $files += [ordered]@{ path = $rel; sha256 = $hash; size = (Get-Item $full).Length }
    }
    foreach ($p in $ExtraManifestPaths) { $files += [ordered]@{ path = $p; sha256 = ('0' * 64); size = 0 } }
    $json = [ordered]@{ runner_version = '9.9.9'; signed = $true; files = $files } | ConvertTo-Json -Depth 5
    $bytes = (New-Object System.Text.UTF8Encoding($false)).GetBytes($json)
    [IO.File]::WriteAllBytes((Join-Path $root 'manifest\runner-manifest.json'), $bytes)
    $sig = $SignWith.SignData($bytes, 'SHA256')
    [IO.File]::WriteAllText((Join-Path $root 'manifest\runner-manifest.sig'), [Convert]::ToBase64String($sig))
    return $root
}

try {
    $rsa = New-TestRsa
    $other = New-TestRsa
    $keyPath = Join-Path $work 'pub.xml'
    [IO.File]::WriteAllText($keyPath, $rsa.ToXmlString($false))

    $good = New-Package 'good' $rsa
    $r = Test-RunnerUpdatePackage -PackageRoot $good -PublicKeyXmlPath $keyPath
    Assert-True ($r.Ok -eq $true) "good package verifies (reason: $($r.Reason))"

    $t = New-Package 'tampered' $rsa
    [IO.File]::WriteAllText((Join-Path $t 'scripts\a.ps1'), 'Write-Host evil')
    $r = Test-RunnerUpdatePackage -PackageRoot $t -PublicKeyXmlPath $keyPath
    Assert-True (-not $r.Ok -and $r.Reason -like 'hash mismatch*') "tampered file rejected (reason: $($r.Reason))"

    $m = New-Package 'missing' $rsa
    Remove-Item (Join-Path $m 'config\c.json')
    $r = Test-RunnerUpdatePackage -PackageRoot $m -PublicKeyXmlPath $keyPath
    Assert-True (-not $r.Ok -and $r.Reason -like 'listed file missing*') "missing file rejected (reason: $($r.Reason))"

    $e = New-Package 'extra' $rsa
    [IO.File]::WriteAllText((Join-Path $e 'scripts\evil.ps1'), 'Write-Host evil')
    $r = Test-RunnerUpdatePackage -PackageRoot $e -PublicKeyXmlPath $keyPath
    Assert-True (-not $r.Ok -and $r.Reason -like 'unlisted file*') "extra unlisted file rejected (reason: $($r.Reason))"

    $w = New-Package 'wrongsig' $other
    $r = Test-RunnerUpdatePackage -PackageRoot $w -PublicKeyXmlPath $keyPath
    Assert-True (-not $r.Ok -and $r.Reason -like '*signature invalid*') "wrong signature rejected (reason: $($r.Reason))"

    foreach ($bad in '../escape.txt', 'scripts/../../escape.txt', '/abs.txt', 'C:/abs.txt') {
        $p = New-Package ('trav' + [guid]::NewGuid().ToString('N').Substring(0, 6)) $rsa @($bad)
        $r = Test-RunnerUpdatePackage -PackageRoot $p -PublicKeyXmlPath $keyPath
        Assert-True (-not $r.Ok -and $r.Reason -like 'unsafe path*') "path traversal entry '$bad' rejected (reason: $($r.Reason))"
    }

    $n = New-Package 'nosig' $rsa
    Remove-Item (Join-Path $n 'manifest\runner-manifest.sig')
    $r = Test-RunnerUpdatePackage -PackageRoot $n -PublicKeyXmlPath $keyPath
    Assert-True (-not $r.Ok -and $r.Reason -eq 'signature missing') 'missing signature rejected'
}
finally {
    Remove-Item -LiteralPath $work -Recurse -Force -ErrorAction SilentlyContinue
}

# Static assertions
$main = Get-Content -Path (Join-Path $Scripts 'runner_main.ps1') -Raw -Encoding UTF8
$verifyIdx = $main.IndexOf('Test-RunnerUpdatePackage -PackageRoot $stagingRoot')
$launchIdx = $main.IndexOf('schtasks.exe /Create /TN $taskName /SC ONCE')
Assert-True ($verifyIdx -gt 0 -and $launchIdx -gt $verifyIdx) 'runner_main verifies staged package before creating the apply-update task'
Assert-True ($main.Contains("'update_rejected'") -and $main.Contains('update package signature invalid')) 'runner_main records update_rejected and failed ACK'
Assert-True ($main.Contains('requireSignedUpdates')) 'runner_main honours requireSignedUpdates'
$boot = Get-Content -Path (Join-Path $Scripts 'bootstrap_update_runner.ps1') -Raw -Encoding UTF8
Assert-True ($boot.Contains("'trust\update-public-key.xml'")) 'bootstrap never overwrites installed public key'
Assert-True ((Get-Content -Raw (Join-Path $PSScriptRoot '..\..\runner\scripts\runner_main.ps1')).Contains("'trust\update-public-key.xml'")) 'runner reads trust anchor from admin-only trust dir'

foreach ($f in 'runner_main', 'bootstrap_update_runner', 'install_runner', 'install_direct_https_runner', 'verify_update_package') {
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
