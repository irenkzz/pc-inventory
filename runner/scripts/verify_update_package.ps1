# Verifies a staged runner update package against the offline-signed manifest.
# Windows PowerShell 5.1 compatible. Dot-source, then call Test-RunnerUpdatePackage.
#
# Canonical rules (must match SiteKitBuilder.php):
#   - <PackageRoot>\manifest\runner-manifest.json is signed byte-for-byte (RSA, SHA-256, PKCS#1 v1.5).
#   - runner-manifest.sig holds base64 of that signature.
#   - manifest.files = [{path (relative, forward slashes), sha256, size}] covers every file except the
#     manifest and its signature.

function New-UpdateVerdict {
    param([bool]$Ok, [string]$Reason)
    return [pscustomobject]@{ Ok = $Ok; Reason = $Reason }
}

function Test-RunnerUpdatePackage {
    [CmdletBinding()]
    param(
        [Parameter(Mandatory = $true)][string]$PackageRoot,
        [Parameter(Mandatory = $true)][string]$PublicKeyXmlPath
    )

    try {
        if (-not (Test-Path -LiteralPath $PackageRoot -PathType Container)) { return New-UpdateVerdict $false 'package root not found' }
        if (-not (Test-Path -LiteralPath $PublicKeyXmlPath -PathType Leaf)) { return New-UpdateVerdict $false 'public key file not found' }
        $root = (Resolve-Path -LiteralPath $PackageRoot).ProviderPath.TrimEnd('\')

        $manifestPath = Join-Path $root 'manifest\runner-manifest.json'
        $sigPath = Join-Path $root 'manifest\runner-manifest.sig'
        if (-not (Test-Path -LiteralPath $manifestPath -PathType Leaf)) { return New-UpdateVerdict $false 'manifest missing' }
        if (-not (Test-Path -LiteralPath $sigPath -PathType Leaf)) { return New-UpdateVerdict $false 'signature missing' }

        $manifestBytes = [System.IO.File]::ReadAllBytes($manifestPath)
        try {
            $sigBytes = [Convert]::FromBase64String(([System.IO.File]::ReadAllText($sigPath)).Trim())
        }
        catch {
            return New-UpdateVerdict $false 'signature is not valid base64'
        }

        $rsa = New-Object System.Security.Cryptography.RSACryptoServiceProvider
        try {
            $rsa.FromXmlString([System.IO.File]::ReadAllText($PublicKeyXmlPath))
            $valid = $rsa.VerifyData($manifestBytes, 'SHA256', $sigBytes)
        }
        finally {
            $rsa.Dispose()
        }
        if (-not $valid) { return New-UpdateVerdict $false 'manifest signature invalid' }

        $manifest = [System.Text.Encoding]::UTF8.GetString($manifestBytes).TrimStart([char]0xFEFF) | ConvertFrom-Json
        if (-not ($manifest.PSObject.Properties.Name -contains 'files') -or $null -eq $manifest.files) {
            return New-UpdateVerdict $false 'manifest has no files list'
        }

        $listed = @{}
        foreach ($entry in @($manifest.files)) {
            $rel = [string]$entry.path
            if ([string]::IsNullOrWhiteSpace($rel) -or $rel.StartsWith('/') -or $rel.Contains('\') -or $rel.Contains(':') -or
                ($rel.Split('/') -contains '..') -or ($rel.Split('/') -contains '.') -or ($rel.Split('/') -contains '')) {
                return New-UpdateVerdict $false "unsafe path in manifest: $rel"
            }
            $key = $rel.ToLowerInvariant()
            if ($listed.ContainsKey($key)) { return New-UpdateVerdict $false "duplicate path in manifest: $rel" }
            $full = [System.IO.Path]::GetFullPath((Join-Path $root ($rel -replace '/', '\')))
            if (-not $full.StartsWith($root + '\', [System.StringComparison]::OrdinalIgnoreCase)) {
                return New-UpdateVerdict $false "path escapes package: $rel"
            }
            if (-not (Test-Path -LiteralPath $full -PathType Leaf)) { return New-UpdateVerdict $false "listed file missing: $rel" }

            $sha = [System.Security.Cryptography.SHA256]::Create()
            try {
                $stream = [System.IO.File]::OpenRead($full)
                try { $actual = [BitConverter]::ToString($sha.ComputeHash($stream)).Replace('-', '').ToLowerInvariant() }
                finally { $stream.Dispose() }
            }
            finally { $sha.Dispose() }
            if ($actual -ne ([string]$entry.sha256).ToLowerInvariant()) { return New-UpdateVerdict $false "hash mismatch: $rel" }
            $listed[$key] = $true
        }

        foreach ($file in Get-ChildItem -LiteralPath $root -Recurse -File -Force) {
            $rel = $file.FullName.Substring($root.Length + 1).Replace('\', '/')
            $key = $rel.ToLowerInvariant()
            if ($key -eq 'manifest/runner-manifest.json' -or $key -eq 'manifest/runner-manifest.sig') { continue }
            if (-not $listed.ContainsKey($key)) { return New-UpdateVerdict $false "unlisted file in package: $rel" }
        }

        return New-UpdateVerdict $true 'ok'
    }
    catch {
        return New-UpdateVerdict $false ('verification error: ' + $_.Exception.Message)
    }
}
