<?php

namespace App\Console\Commands;

use App\Services\Security\SiteTokenStore;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use ZipArchive;

class BackupInventory extends Command
{
    protected $signature = 'inventory:backup
        {--label= : Optional backup label}
        {--include-downloads : Include generated site kits and downloadable artifacts}
        {--prune-days= : Delete backup directories older than this many days after a successful backup}';

    protected $description = 'Create a local backup of the inventory database and raw evidence archive';

    public function handle(SiteTokenStore $siteTokens): int
    {
        $timestamp = now()->format('Ymd-His');
        $label = trim((string) $this->option('label'));
        $safeLabel = $label !== '' ? '-' . Str::slug($label) : '';
        $backupRoot = storage_path('app/' . trim((string) config('inventory.backups_path'), '/'));
        $backupDir = $backupRoot . DIRECTORY_SEPARATOR . "{$timestamp}{$safeLabel}";

        File::ensureDirectoryExists($backupDir);

        $databaseBackup = $this->backupDatabase($backupDir);
        $rawArchiveBackup = $this->backupRawArchive($backupDir);
        $downloadsBackup = $this->option('include-downloads') ? $this->backupDownloads($backupDir) : null;
        $siteTokensBackup = $this->backupSiteTokens($backupDir, $siteTokens);
        $prunedBackups = $this->pruneOldBackups($backupRoot, $backupDir);

        $manifest = [
            'backup_version' => 2,
            'created_at' => now()->toIso8601String(),
            'app' => config('app.name'),
            'database_connection' => config('database.default'),
            'database_backup' => $databaseBackup,
            'raw_archive_backup' => $rawArchiveBackup,
            'downloads_backup' => $downloadsBackup,
            'site_tokens_backup' => $siteTokensBackup,
            'pruned_backups' => $prunedBackups,
            'counts' => [
                'devices' => DB::table('devices')->count(),
                'device_scans' => DB::table('device_scans')->count(),
                'change_log' => DB::table('change_log')->count(),
                'raw_files' => DB::table('raw_files')->count(),
                'runners' => DB::table('runners')->count(),
                'collectors' => DB::table('collectors')->count(),
                'runner_commands' => DB::table('runner_commands')->count(),
            ],
        ];

        $manifestPath = $backupDir . DIRECTORY_SEPARATOR . 'manifest.json';
        File::put($manifestPath, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $this->line(json_encode([
            'backup_dir' => $backupDir,
            'manifest' => $manifestPath,
            'database_backup' => $databaseBackup,
            'raw_archive_backup' => $rawArchiveBackup,
            'downloads_backup' => $downloadsBackup,
            'site_tokens_backup' => $siteTokensBackup,
            'pruned_backups' => $prunedBackups,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }

    private function backupDatabase(string $backupDir): ?string
    {
        if (config('database.default') !== 'sqlite') {
            $notePath = $backupDir . DIRECTORY_SEPARATOR . 'database-backup-note.txt';
            File::put($notePath, 'Automated DB file copy is currently implemented for SQLite only. Use native database dump tooling for this connection.');

            return $notePath;
        }

        $database = (string) config('database.connections.sqlite.database');
        if ($database === ':memory:' || ! is_file($database)) {
            return null;
        }

        $target = $backupDir . DIRECTORY_SEPARATOR . 'database.sqlite';
        File::copy($database, $target);

        return $target;
    }

    private function backupRawArchive(string $backupDir): ?string
    {
        $archiveRoot = storage_path('app/' . trim((string) config('inventory.raw_archive_path'), '/'));
        if (! is_dir($archiveRoot)) {
            return null;
        }

        return $this->zipDirectory($archiveRoot, $backupDir . DIRECTORY_SEPARATOR . 'raw_archive.zip');
    }

    private function backupDownloads(string $backupDir): ?string
    {
        $downloadsRoot = storage_path('app/' . trim((string) config('inventory.downloads_path'), '/'));
        if (! is_dir($downloadsRoot)) {
            return null;
        }

        return $this->zipDirectory($downloadsRoot, $backupDir . DIRECTORY_SEPARATOR . 'downloads.zip');
    }

    private function backupSiteTokens(string $backupDir, SiteTokenStore $siteTokens): ?string
    {
        $tokenFile = $siteTokens->filePath();
        if ($tokenFile !== '' && is_file($tokenFile)) {
            $target = $backupDir . DIRECTORY_SEPARATOR . 'site_tokens.json';
            File::copy($tokenFile, $target);

            return $target;
        }

        if (trim((string) env('INVENTORY_SITE_TOKENS_JSON', '')) !== '') {
            $notePath = $backupDir . DIRECTORY_SEPARATOR . 'site-tokens-note.txt';
            File::put($notePath, 'Site tokens are configured through INVENTORY_SITE_TOKENS_JSON. Back up that environment secret through the server secret-management process.');

            return $notePath;
        }

        return null;
    }

    private function zipDirectory(string $sourceDir, string $target): string
    {
        $zip = new ZipArchive();
        if ($zip->open($target, ZipArchive::CREATE) !== true) {
            throw new \RuntimeException("Could not create ZIP backup: {$target}");
        }

        foreach (File::allFiles($sourceDir) as $file) {
            $relative = Str::replaceFirst($sourceDir . DIRECTORY_SEPARATOR, '', $file->getPathname());
            $zip->addFile($file->getPathname(), str_replace('\\', '/', $relative));
        }

        $zip->close();

        return $target;
    }

    private function pruneOldBackups(string $backupRoot, string $currentBackupDir): array
    {
        $days = $this->option('prune-days');
        if ($days === null || $days === '') {
            return [];
        }

        $days = (int) $days;
        if ($days <= 0 || ! is_dir($backupRoot)) {
            return [];
        }

        $cutoff = now()->subDays($days)->getTimestamp();
        $pruned = [];

        foreach (File::directories($backupRoot) as $directory) {
            $manifestPath = $directory . DIRECTORY_SEPARATOR . 'manifest.json';
            if ($directory === $currentBackupDir || ! is_file($manifestPath)) {
                continue;
            }

            if (File::lastModified($manifestPath) >= $cutoff) {
                continue;
            }

            File::deleteDirectory($directory);
            $pruned[] = $directory;
        }

        return $pruned;
    }
}
