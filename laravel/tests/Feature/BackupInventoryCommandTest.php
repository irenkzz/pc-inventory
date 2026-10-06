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
            (string) $this->fixtureContents('sample_data/sample_scan_1.csv'),
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

    private function useFakeMysql(): void
    {
        Config::set('database.connections.mysqlfake', [
            'driver' => 'mariadb', 'host' => 'h', 'port' => '3306', 'database' => 'inv', 'username' => 'u', 'password' => 'S3cretPw!',
        ]);
        Config::set('database.default', 'mysqlfake');
        Config::set('inventory.backups_path', 'framework/testing/backups-mysql');
    }

    public function test_mysqldump_success_keeps_password_out_of_argv(): void
    {
        $this->useFakeMysql();
        $seen = new \ArrayObject();
        app()->bind('inventory.mysqldump_runner', fn () => function (array $cmd, array $env) use ($seen): array {
            $seen->exchangeArray([$cmd, $env]);
            foreach ($cmd as $arg) {
                if (str_starts_with($arg, '--result-file=')) {
                    file_put_contents(substr($arg, 14), '-- dump');
                }
            }

            return [0, ''];
        });

        $dir = storage_path('app/framework/testing/backups-mysql/x');
        File::ensureDirectoryExists($dir);
        $m = new \ReflectionMethod(\App\Console\Commands\BackupInventory::class, 'backupDatabase');
        $path = $m->invoke(app(\App\Console\Commands\BackupInventory::class), $dir);

        $this->assertFileExists($path);
        $this->assertStringNotContainsString('S3cretPw!', implode(' ', $seen[0]));
        $this->assertSame('S3cretPw!', $seen[1]['MYSQL_PWD']);
        $this->assertContains('--single-transaction', $seen[0]);
        $this->assertContains('--no-tablespaces', $seen[0]);
    }

    public function test_mysqldump_failure_reports_fail_without_secret(): void
    {
        $this->useFakeMysql();
        app()->bind('inventory.mysqldump_runner', fn () => fn (array $cmd, array $env): array => [2, 'Access denied S3cretPw!']);
        $this->withoutMockingConsoleOutput();

        $buffer = new BufferedOutput();
        $exit = Artisan::call('inventory:backup', ['--label' => 'f'], $buffer);
        $output = $buffer->fetch();

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('FAIL', $output);
        $this->assertStringNotContainsString('S3cretPw!', $output);
        $this->assertStringNotContainsString('manifest', $output);
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
