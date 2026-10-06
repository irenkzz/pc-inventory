$ErrorActionPreference = 'Stop'
$scanner = Join-Path $PSScriptRoot '..\..\runner\scripts\scanner_core_v4.ps1'
$scanner = (Resolve-Path $scanner).Path
$fail = 0
function Check($ok, $msg) { if ($ok) { Write-Host "PASS $msg" } else { Write-Host "FAIL $msg"; $script:fail++ } }

$errors = $null; $tokens = $null
[void][System.Management.Automation.Language.Parser]::ParseFile($scanner, [ref]$tokens, [ref]$errors)
Check ($errors.Count -eq 0) 'scanner parses with zero errors'

$text = [IO.File]::ReadAllText($scanner)
$fields = 'Battery_Present','Battery_Health_Percent','Hotfix_Count','Last_Hotfix_Date','BitLocker_System_Drive','TPM_Enabled','TPM_Activated','TPM_Spec_Version','Installed_Software_Count'
foreach ($f in $fields) {
    Check ($text -match ('(?m)^\s+' + $f + '\s*=')) "output field $f present"
}

# Each collection source must sit inside a try block.
$sources = 'Win32_Battery','BatteryFullChargedCapacity','Win32_QuickFix','Win32_EncryptableVolume','Win32_Tpm','CurrentVersion\\Uninstall'
$tryBlocks = $tokens | Where-Object { $_.Kind -eq 'Try' -or $_.Text -eq 'try' }
$ast = [System.Management.Automation.Language.Parser]::ParseFile($scanner, [ref]$null, [ref]$null)
$tries = $ast.FindAll({ param($n) $n -is [System.Management.Automation.Language.TryStatementAst] }, $true)
foreach ($s in $sources) {
    $wrapped = $false
    foreach ($t in $tries) {
        if ($t.Body.Extent.Text -match $s -and $t.CatchClauses.Count -gt 0) { $wrapped = $true; break }
    }
    Check $wrapped "$s collection wrapped in try/catch"
}

Check ($text -notmatch 'Win32_Product') 'no Win32_Product (slow, triggers MSI repair)'
$added = $text.Substring($text.IndexOf('# Extra security/health fields'))
Check ($added -notmatch '\?\?|\?\.') 'no PS7-only operators in new block'

if ($fail -gt 0) { Write-Host "$fail failure(s)"; exit 1 }
Write-Host 'All scanner new-field checks passed'
