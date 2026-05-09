<#
.SYNOPSIS
Prepare the Phase 19C-approved MariaDB/MySQL rehearsal filesystem layout.

.DESCRIPTION
Dry-run by default. Use -Execute only on Supermicro after review.
This helper performs filesystem-only preparation:
- creates D:\inventory-rehearsal folders
- copies reviewed Laravel source without secret/runtime/evidence folders
- creates .env.rehearsal.example with placeholders only
- optionally copies a verified SQLite backup
- optionally copies raw archive and downloads/site kits
- writes a non-secret notes file

It does not install database software, create databases/users, run Laravel
commands, expose web endpoints, change proxies/DNS, touch runner/collector
traffic, or generate packages.

Safety rules: DB credentials are never committed, live env files are never
copied, APP_KEY is never copied or printed, and the helper does not print file
contents, raw CSV contents, database contents, token values, bearer values,
command payload JSON, full configs, or full runner GUIDs.

Do not install database software. The helper does not print file contents.
Do not print raw CSV contents.

.EXAMPLE
powershell -NoProfile -ExecutionPolicy Bypass -File .\tools\Prepare-MariaDbRehearsalFilesystem.ps1

.EXAMPLE
powershell -NoProfile -ExecutionPolicy Bypass -File .\tools\Prepare-MariaDbRehearsalFilesystem.ps1 -Execute

.EXAMPLE
powershell -NoProfile -ExecutionPolicy Bypass -File .\tools\Prepare-MariaDbRehearsalFilesystem.ps1 -Execute -SqliteBackupPath "D:\path\to\verified-backup.sqlite"

.EXAMPLE
powershell -NoProfile -ExecutionPolicy Bypass -File .\tools\Prepare-MariaDbRehearsalFilesystem.ps1 -Execute -SqliteBackupPath "D:\path\to\verified-backup.sqlite" -CopyRawArchive -CopyDownloads
#>

[CmdletBinding()]
param(
    [switch]$Execute,
    [switch]$AllowSourceOverride,
    [string]$SourceRoot = 'D:\inventory',
    [string]$SourceLaravelPath = 'D:\inventory\laravel',
    [string]$RehearsalRoot = 'D:\inventory-rehearsal',
    [string]$RehearsalLaravelPath = 'D:\inventory-rehearsal\laravel',
    [string]$RehearsalSqlitePath = 'D:\inventory-rehearsal\source-copy\database.sqlite',
    [string]$RehearsalRawArchivePath = 'D:\inventory-rehearsal\raw_archive',
    [string]$RehearsalDownloadsPath = 'D:\inventory-rehearsal\downloads',
    [string]$NotesPath = 'D:\inventory-rehearsal\notes',
    [string]$SqliteBackupPath,
    [switch]$CopyRawArchive,
    [switch]$CopyDownloads,
    [string]$RehearsalAppUrl = 'http://127.0.0.1:8090'
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$script:Result = 'PASS'
$script:Warnings = New-Object System.Collections.Generic.List[string]
$script:Failures = New-Object System.Collections.Generic.List[string]

$RequiredDirectories = @(
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
)

$EnvRehearsalExample = @'
APP_ENV=local
APP_DEBUG=true
APP_URL=http://127.0.0.1:8090

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=inventory_rehearsal
DB_USERNAME=inventory_rehearsal_app
DB_PASSWORD=<rehearsal-db-password-not-committed>
'@

function Write-Line {
    param(
        [ValidateSet('OK', 'WARN', 'FAIL', 'INFO')]
        [string]$Level,
        [string]$Message
    )

    Write-Host "[$Level] $Message"
}

function Add-Warning {
    param([string]$Message)
    $script:Warnings.Add($Message)
    if ($script:Result -eq 'PASS') {
        $script:Result = 'WARN'
    }
    Write-Line -Level 'WARN' -Message $Message
}

function Add-Failure {
    param([string]$Message)
    $script:Failures.Add($Message)
    $script:Result = 'FAIL'
    Write-Line -Level 'FAIL' -Message $Message
}

function Get-FullPathSafe {
    param([string]$Path)

    if ([string]::IsNullOrWhiteSpace($Path)) {
        return ''
    }

    return [System.IO.Path]::GetFullPath($Path).TrimEnd('\')
}

function Test-SuspiciousPath {
    param(
        [string]$Path,
        [string]$Name
    )

    if ([string]::IsNullOrWhiteSpace($Path)) {
        Add-Failure "$Name is empty."
        return
    }

    $full = Get-FullPathSafe -Path $Path
    if ($full -match '^[A-Za-z]:$') {
        Add-Failure "$Name resolves to a drive root: $full"
        return
    }

    if ($full -in @('\', '/', '.', '..')) {
        Add-Failure "$Name is root-only or relative-only: $Path"
        return
    }

    if ($full.Length -lt 6) {
        Add-Failure "$Name is suspiciously short: $full"
    }
}

function Test-PathBoundaries {
    $sourceRootFull = Get-FullPathSafe -Path $SourceRoot
    $sourceLaravelFull = Get-FullPathSafe -Path $SourceLaravelPath
    $rehearsalRootFull = Get-FullPathSafe -Path $RehearsalRoot
    $rehearsalLaravelFull = Get-FullPathSafe -Path $RehearsalLaravelPath
    $sqliteTargetFull = Get-FullPathSafe -Path $RehearsalSqlitePath
    $rawTargetFull = Get-FullPathSafe -Path $RehearsalRawArchivePath
    $downloadsTargetFull = Get-FullPathSafe -Path $RehearsalDownloadsPath
    $notesFull = Get-FullPathSafe -Path $NotesPath

    Test-SuspiciousPath -Path $SourceRoot -Name 'SourceRoot'
    Test-SuspiciousPath -Path $SourceLaravelPath -Name 'SourceLaravelPath'
    Test-SuspiciousPath -Path $RehearsalRoot -Name 'RehearsalRoot'
    Test-SuspiciousPath -Path $RehearsalLaravelPath -Name 'RehearsalLaravelPath'
    Test-SuspiciousPath -Path $RehearsalSqlitePath -Name 'RehearsalSqlitePath'
    Test-SuspiciousPath -Path $RehearsalRawArchivePath -Name 'RehearsalRawArchivePath'
    Test-SuspiciousPath -Path $RehearsalDownloadsPath -Name 'RehearsalDownloadsPath'
    Test-SuspiciousPath -Path $NotesPath -Name 'NotesPath'

    if (-not $AllowSourceOverride -and ($sourceLaravelFull -ne 'D:\inventory\laravel')) {
        Add-Failure 'SourceLaravelPath must be D:\inventory\laravel unless -AllowSourceOverride is supplied.'
    }

    if ($rehearsalRootFull -eq $sourceRootFull -or $rehearsalRootFull -eq $sourceLaravelFull) {
        Add-Failure 'RehearsalRoot must not equal SourceRoot or SourceLaravelPath.'
    }

    if ($rehearsalLaravelFull -eq $sourceLaravelFull) {
        Add-Failure 'RehearsalLaravelPath must not equal SourceLaravelPath.'
    }

    if ($rehearsalRootFull.StartsWith($sourceLaravelFull + '\', [System.StringComparison]::OrdinalIgnoreCase)) {
        Add-Failure 'RehearsalRoot must not be inside D:\inventory\laravel.'
    }

    if ($sqliteTargetFull.StartsWith($sourceLaravelFull + '\', [System.StringComparison]::OrdinalIgnoreCase) -or
        $rawTargetFull.StartsWith($sourceLaravelFull + '\', [System.StringComparison]::OrdinalIgnoreCase) -or
        $downloadsTargetFull.StartsWith($sourceLaravelFull + '\', [System.StringComparison]::OrdinalIgnoreCase) -or
        $notesFull.StartsWith($sourceLaravelFull + '\', [System.StringComparison]::OrdinalIgnoreCase)) {
        Add-Failure 'One or more target paths are inside the live Laravel path.'
    }

    $current = Get-FullPathSafe -Path (Get-Location).Path
    if ($Execute -and $current.StartsWith('D:\xampp\htdocs\inventaris', [System.StringComparison]::OrdinalIgnoreCase)) {
        Add-Failure 'Execution from the IT-ADMIN development path is refused. Copy this helper manually to Supermicro and run it there.'
    }

    if ($RehearsalAppUrl -eq 'https://inventory-pilot.internal.lan') {
        Add-Failure 'The live pilot hostname is refused as rehearsal APP_URL.'
    }
}

function Invoke-PlannedAction {
    param(
        [string]$Description,
        [scriptblock]$Action
    )

    if ($Execute) {
        Write-Line -Level 'INFO' -Message $Description
        & $Action
    } else {
        Write-Line -Level 'INFO' -Message "DRY-RUN: $Description"
    }
}

function New-DirectoryIfNeeded {
    param([string]$Path)

    Invoke-PlannedAction -Description "Ensure directory exists: $Path" -Action {
        New-Item -ItemType Directory -Path $Path -Force | Out-Null
    }
}

function Copy-TreeFallback {
    param(
        [string]$Source,
        [string]$Destination
    )

    $sourceFull = Get-FullPathSafe -Path $Source
    $destinationFull = Get-FullPathSafe -Path $Destination
    $excludedDirectorySuffixes = @(
        '\.git',
        '\storage\logs',
        '\storage\framework\cache',
        '\storage\framework\sessions',
        '\storage\framework\views',
        '\storage\app\inventory\raw_archive',
        '\storage\app\inventory\downloads'
    )

    Get-ChildItem -Path $Source -Recurse -Force | ForEach-Object {
        $fullName = Get-FullPathSafe -Path $_.FullName
        $relative = $fullName.Substring($sourceFull.Length).TrimStart('\')

        foreach ($suffix in $excludedDirectorySuffixes) {
            $excludedRoot = $sourceFull + $suffix
            if ($fullName -eq $excludedRoot -or $fullName.StartsWith($excludedRoot + '\', [System.StringComparison]::OrdinalIgnoreCase)) {
                return
            }
        }

        if (-not $_.PSIsContainer) {
            if ($_.Name -eq '.env' -or $_.Name -like '.env.*' -or ($relative -eq 'database\database.sqlite')) {
                return
            }
        }

        $target = Join-Path $destinationFull $relative
        if ($_.PSIsContainer) {
            New-Item -ItemType Directory -Path $target -Force | Out-Null
        } else {
            $targetParent = Split-Path -Parent $target
            New-Item -ItemType Directory -Path $targetParent -Force | Out-Null
            Copy-Item -Path $_.FullName -Destination $target -Force
        }
    }
}

function Copy-LaravelSource {
    if (-not (Test-Path $SourceLaravelPath -PathType Container)) {
        Add-Warning "Source Laravel path not found: $SourceLaravelPath"
        return
    }

    Invoke-PlannedAction -Description "Copy reviewed Laravel source to $RehearsalLaravelPath without secret/runtime/evidence folders" -Action {
        $robocopy = Get-Command robocopy.exe -ErrorAction SilentlyContinue
        if ($robocopy) {
            # /E copies subdirectories, /COPY:DAT and /DCOPY:DAT preserve data, attributes, and timestamps,
            # /R:1 /W:1 avoid long retries, and exclusions prevent secret/runtime/evidence files from moving.
            $args = @(
                $SourceLaravelPath,
                $RehearsalLaravelPath,
                '/E',
                '/COPY:DAT',
                '/DCOPY:DAT',
                '/R:1',
                '/W:1',
                '/NFL',
                '/NDL',
                '/NP',
                '/XF',
                '.env',
                '.env.*',
                'database.sqlite',
                '/XD',
                (Join-Path $SourceLaravelPath '.git'),
                (Join-Path $SourceLaravelPath 'storage\logs'),
                (Join-Path $SourceLaravelPath 'storage\framework\cache'),
                (Join-Path $SourceLaravelPath 'storage\framework\sessions'),
                (Join-Path $SourceLaravelPath 'storage\framework\views'),
                (Join-Path $SourceLaravelPath 'storage\app\inventory\raw_archive'),
                (Join-Path $SourceLaravelPath 'storage\app\inventory\downloads')
            )
            & $robocopy.Source @args | Out-Null
            $code = $LASTEXITCODE
            if ($code -ge 8) {
                throw "Robocopy failed with exit code $code"
            }
            if ($code -gt 0) {
                Add-Warning "Robocopy completed with non-fatal exit code $code."
            } else {
                Write-Line -Level 'OK' -Message 'Laravel source copy completed.'
            }
        } else {
            Add-Warning 'robocopy.exe was not found; using PowerShell fallback copy.'
            Copy-TreeFallback -Source $SourceLaravelPath -Destination $RehearsalLaravelPath
        }
    }
}

function Write-EnvExample {
    $target = Join-Path $RehearsalLaravelPath '.env.rehearsal.example'
    Invoke-PlannedAction -Description "Create placeholder-only file: $target" -Action {
        New-Item -ItemType Directory -Path $RehearsalLaravelPath -Force | Out-Null
        Set-Content -Path $target -Value $EnvRehearsalExample -Encoding ASCII
        Write-Line -Level 'OK' -Message 'Placeholder rehearsal env example written.'
    }
}

function Copy-SqliteBackup {
    if ([string]::IsNullOrWhiteSpace($SqliteBackupPath)) {
        Add-Warning 'SQLite backup copy skipped. Supply -SqliteBackupPath with a verified backup later.'
        return
    }

    $backupFull = Get-FullPathSafe -Path $SqliteBackupPath
    if ($backupFull -eq 'D:\inventory\laravel\database\database.sqlite') {
        Add-Failure 'Refusing to copy live SQLite database directly. Use a verified backup or controlled snapshot.'
        return
    }

    if (-not (Test-Path $SqliteBackupPath -PathType Leaf)) {
        Add-Failure "SQLite backup source file does not exist: $SqliteBackupPath"
        return
    }

    $extension = [System.IO.Path]::GetExtension($SqliteBackupPath)
    if ($extension -notin @('.sqlite', '.db')) {
        Add-Failure 'SQLite backup source must have .sqlite or .db extension.'
        return
    }

    Invoke-PlannedAction -Description "Copy verified SQLite backup to $RehearsalSqlitePath" -Action {
        New-Item -ItemType Directory -Path (Split-Path -Parent $RehearsalSqlitePath) -Force | Out-Null
        Copy-Item -Path $SqliteBackupPath -Destination $RehearsalSqlitePath -Force
        try {
            Set-ItemProperty -Path $RehearsalSqlitePath -Name IsReadOnly -Value $true
            Write-Line -Level 'OK' -Message 'SQLite backup copied and marked read-only.'
        } catch {
            Add-Warning 'SQLite backup copied, but marking it read-only failed.'
        }
    }
}

function Copy-OptionalEvidenceFolder {
    param(
        [string]$Source,
        [string]$Destination,
        [string]$Label
    )

    if (-not (Test-Path $Source -PathType Container)) {
        Add-Warning "$Label source path not found; no data fabricated: $Source"
        return
    }

    Invoke-PlannedAction -Description "Copy $Label to $Destination" -Action {
        $robocopy = Get-Command robocopy.exe -ErrorAction SilentlyContinue
        if ($robocopy) {
            $args = @($Source, $Destination, '/E', '/COPY:DAT', '/DCOPY:DAT', '/R:1', '/W:1', '/NFL', '/NDL', '/NP')
            & $robocopy.Source @args | Out-Null
            $code = $LASTEXITCODE
            if ($code -ge 8) {
                throw "Robocopy failed while copying $Label with exit code $code"
            }
            if ($code -gt 0) {
                Add-Warning "$Label copy completed with non-fatal Robocopy exit code $code."
            } else {
                Write-Line -Level 'OK' -Message "$Label copy completed."
            }
        } else {
            Add-Warning "robocopy.exe was not found; using PowerShell fallback copy for $Label."
            Copy-Item -Path $Source -Destination $Destination -Recurse -Force
        }
    }
}

function Get-GitValue {
    param(
        [string]$WorkingDirectory,
        [string[]]$Arguments
    )

    $git = Get-Command git.exe -ErrorAction SilentlyContinue
    if (-not $git -or -not (Test-Path $WorkingDirectory -PathType Container)) {
        return ''
    }

    try {
        $value = & $git.Source -C $WorkingDirectory @Arguments 2>$null
        if ($LASTEXITCODE -eq 0) {
            return (($value | Select-Object -First 1) -as [string])
        }
    } catch {
        return ''
    }

    return ''
}

function Write-SetupNotes {
    if (-not $Execute) {
        Write-Line -Level 'INFO' -Message "DRY-RUN: Would write non-secret setup notes under $NotesPath"
        return
    }

    New-Item -ItemType Directory -Path $NotesPath -Force | Out-Null
    $stamp = Get-Date -Format 'yyyyMMdd-HHmmss'
    $notesFile = Join-Path $NotesPath "setup-notes-$stamp.txt"
    $gitCommit = Get-GitValue -WorkingDirectory $SourceLaravelPath -Arguments @('rev-parse', 'HEAD')
    $gitBranch = Get-GitValue -WorkingDirectory $SourceLaravelPath -Arguments @('rev-parse', '--abbrev-ref', 'HEAD')

    $lines = New-Object System.Collections.Generic.List[string]
    $lines.Add("timestamp=$(Get-Date -Format o)")
    $lines.Add("source_root=$SourceRoot")
    $lines.Add("source_laravel_path=$SourceLaravelPath")
    $lines.Add("rehearsal_root=$RehearsalRoot")
    if ($gitCommit) { $lines.Add("git_commit=$gitCommit") }
    if ($gitBranch) { $lines.Add("git_branch=$gitBranch") }
    $lines.Add("sqlite_backup_copied=$([bool](-not [string]::IsNullOrWhiteSpace($SqliteBackupPath) -and (Test-Path $RehearsalSqlitePath -PathType Leaf)))")
    $lines.Add("raw_archive_copy_requested=$([bool]$CopyRawArchive)")
    $lines.Add("downloads_copy_requested=$([bool]$CopyDownloads)")
    $lines.Add('warnings=')
    foreach ($warning in $script:Warnings) {
        $lines.Add("- $warning")
    }

    Set-Content -Path $notesFile -Value $lines -Encoding ASCII
    Write-Line -Level 'OK' -Message "Non-secret setup notes written: $notesFile"
}

try {
    Write-Line -Level 'INFO' -Message 'MariaDB/MySQL rehearsal filesystem helper started.'
    if (-not $Execute) {
        Write-Line -Level 'INFO' -Message 'Running in dry-run mode. Use -Execute on Supermicro after review to make filesystem changes.'
    }

    Test-PathBoundaries

    if ($script:Failures.Count -eq 0) {
        foreach ($directory in $RequiredDirectories) {
            New-DirectoryIfNeeded -Path $directory
        }

        Copy-LaravelSource
        Write-EnvExample
        Copy-SqliteBackup

        if ($CopyRawArchive) {
            Copy-OptionalEvidenceFolder -Source (Join-Path $SourceLaravelPath 'storage\app\inventory\raw_archive') -Destination $RehearsalRawArchivePath -Label 'raw archive'
        } else {
            Add-Warning 'Raw archive copy skipped. Use -CopyRawArchive when a reviewed evidence copy is required.'
        }

        if ($CopyDownloads) {
            Copy-OptionalEvidenceFolder -Source (Join-Path $SourceLaravelPath 'storage\app\inventory\downloads') -Destination $RehearsalDownloadsPath -Label 'downloads/site kits'
        } else {
            Add-Warning 'Downloads/site kits copy skipped. Use -CopyDownloads when a reviewed evidence copy is required.'
        }

        Write-SetupNotes
    }
} catch {
    Add-Failure $_.Exception.Message
}

Write-Host "Result: $script:Result"
