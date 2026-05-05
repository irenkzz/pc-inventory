<?php

namespace Tests\Feature;

use App\Services\Inventory\InventoryIngestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

class BackupInventoryCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_backup_command_writes_manifest_and_raw_archive_zip(): void
    {
        Config::set('inventory.backups_path', 'framework/testing/backups');
        Config::set('inventory.raw_archive_path', 'framework/testing/raw_archive');

        app(InventoryIngestService::class)->ingestCsvText(
            (string) file_get_contents(base_path('../../inventaris_py/sample_data/sample_scan_1.csv')),
            'sample_scan_1.csv',
            'test_import',
        );

        $this->artisan('inventory:backup', ['--label' => 'test'])
            ->expectsOutputToContain('"manifest"')
            ->assertSuccessful();

        $backupRoot = storage_path('app/framework/testing/backups');
        $dirs = glob($backupRoot . DIRECTORY_SEPARATOR . '*-test', GLOB_ONLYDIR);

        $this->assertNotEmpty($dirs);
        $backupDir = $dirs[0];

        $this->assertFileExists($backupDir . DIRECTORY_SEPARATOR . 'manifest.json');
        $this->assertFileExists($backupDir . DIRECTORY_SEPARATOR . 'raw_archive.zip');

        $manifest = json_decode((string) file_get_contents($backupDir . DIRECTORY_SEPARATOR . 'manifest.json'), true);
        $this->assertArrayHasKey('database_backup', $manifest);
        $this->assertSame(1, $manifest['counts']['devices']);
        $this->assertSame(1, $manifest['counts']['device_scans']);
        $this->assertSame(1, $manifest['counts']['raw_files']);
    }

    public function test_backup_can_include_downloads_site_tokens_and_prune_old_backups(): void
    {
        Config::set('inventory.backups_path', 'framework/testing/backups-extended');
        Config::set('inventory.raw_archive_path', 'framework/testing/raw_archive-extended');
        Config::set('inventory.downloads_path', 'framework/testing/downloads-extended');

        $backupRoot = storage_path('app/framework/testing/backups-extended');
        $downloadsRoot = storage_path('app/framework/testing/downloads-extended/site-kit-SITE-HQ');
        $relativeTokenFile = 'storage/app/framework/testing/site_tokens_backup.json';
        $tokenFile = base_path($relativeTokenFile);

        File::deleteDirectory($backupRoot);
        File::deleteDirectory(storage_path('app/framework/testing/downloads-extended'));
        File::ensureDirectoryExists($downloadsRoot);
        File::ensureDirectoryExists(dirname($tokenFile));
        File::put($downloadsRoot . DIRECTORY_SEPARATOR . 'collector-config.json', '{"site_id":"SITE-HQ"}');
        File::put($tokenFile, json_encode(['SITE-HQ' => 'real-token']));
        Config::set('inventory.site_tokens_file', $relativeTokenFile);

        $oldBackupDir = $backupRoot . DIRECTORY_SEPARATOR . '20000101-000000-old';
        File::ensureDirectoryExists($oldBackupDir);
        $oldManifest = $oldBackupDir . DIRECTORY_SEPARATOR . 'manifest.json';
        File::put($oldManifest, '{}');
        touch($oldManifest, now()->subDays(10)->getTimestamp());

        $this->withoutMockingConsoleOutput();

        $buffer = new BufferedOutput();
        $exitCode = Artisan::call('inventory:backup', [
            '--label' => 'extended',
            '--include-downloads' => true,
            '--prune-days' => 1,
        ], $buffer);
        $output = $buffer->fetch();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('"downloads_backup"', $output);
        $this->assertStringContainsString('"site_tokens_backup"', $output);
        $this->assertStringContainsString('"pruned_backups"', $output);

        $dirs = glob($backupRoot . DIRECTORY_SEPARATOR . '*-extended', GLOB_ONLYDIR);

        $this->assertNotEmpty($dirs);
        $backupDir = $dirs[0];

        $this->assertDirectoryDoesNotExist($oldBackupDir);
        $this->assertFileExists($backupDir . DIRECTORY_SEPARATOR . 'downloads.zip');
        $this->assertFileExists($backupDir . DIRECTORY_SEPARATOR . 'site_tokens.json');

        $manifest = json_decode((string) file_get_contents($backupDir . DIRECTORY_SEPARATOR . 'manifest.json'), true);
        $this->assertSame(2, $manifest['backup_version']);
        $this->assertSame(['SITE-HQ' => 'real-token'], json_decode((string) file_get_contents($manifest['site_tokens_backup']), true));
        $this->assertNotEmpty($manifest['pruned_backups']);
    }
}
