<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

class InstallPreflightCommandTest extends TestCase
{
    private string $testStorageRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->testStorageRoot = storage_path('app/framework/testing/install-preflight/' . str_replace('.', '_', uniqid('', true)));
        File::ensureDirectoryExists($this->testStorageRoot . '/raw');
        File::ensureDirectoryExists($this->testStorageRoot . '/downloads');
        File::ensureDirectoryExists($this->testStorageRoot . '/backups');

        Config::set('app.env', 'testing');
        Config::set('app.debug', false);
        Config::set('app.key', 'base64:install-preflight-secret-app-key');
        Config::set('app.url', 'https://inventory.company.test');
        Config::set('inventory.raw_archive_path', $this->relativeStoragePath('raw'));
        Config::set('inventory.downloads_path', $this->relativeStoragePath('downloads'));
        Config::set('inventory.backups_path', $this->relativeStoragePath('backups'));
        Config::set('inventory.install_preflight_base_path_override', 'D:\\inventory\\laravel');
        Config::set('inventory.install_preflight_hostname_override', 'SUPERMICRO');
    }

    protected function tearDown(): void
    {
        if (isset($this->testStorageRoot) && is_dir($this->testStorageRoot)) {
            File::deleteDirectory($this->testStorageRoot);
        }

        parent::tearDown();
    }

    public function test_command_is_registered(): void
    {
        $this->assertArrayHasKey('inventory:install-preflight', Artisan::all());
    }

    public function test_output_ends_with_exactly_one_result_line(): void
    {
        [, $output] = $this->runCommand();

        $this->assertSame(1, preg_match_all('/^Result: (PASS|WARN|FAIL)\r?$/m', $output));
        $this->assertMatchesRegularExpression('/Result: (PASS|WARN|FAIL)\s*$/', $output);
    }

    public function test_https_app_url_produces_ok_or_non_fail_status(): void
    {
        Config::set('app.url', 'https://inventory.company.test');

        [$exitCode, $output] = $this->runCommand();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('[OK] APP_URL uses HTTPS', $output);
        $this->assertStringNotContainsString('[FAIL] APP_URL uses plain HTTP', $output);
    }

    public function test_plain_http_app_url_produces_fail(): void
    {
        Config::set('app.url', 'http://inventory.company.test');

        [$exitCode, $output] = $this->runCommand();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('[FAIL] APP_URL uses plain HTTP or is not HTTPS for package-generation use', $output);
        $this->assertStringContainsString('Result: FAIL', $output);
    }

    public function test_placeholder_example_app_url_produces_fail(): void
    {
        Config::set('app.url', 'https://inventory.example.local');

        [$exitCode, $output] = $this->runCommand();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('[FAIL] APP_URL is placeholder/example', $output);
    }

    public function test_production_with_debug_true_produces_fail(): void
    {
        Config::set('app.env', 'production');
        Config::set('app.debug', true);

        [$exitCode, $output] = $this->runCommand();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('[FAIL] APP_ENV=production with APP_DEBUG=true', $output);
    }

    public function test_local_with_debug_true_produces_warn(): void
    {
        Config::set('app.env', 'local');
        Config::set('app.debug', true);

        [$exitCode, $output] = $this->runCommand();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('[WARN] APP_ENV is local/testing/pilot-like: local', $output);
        $this->assertStringContainsString('[WARN] APP_DEBUG=true outside production', $output);
        $this->assertStringContainsString('Result: WARN', $output);
    }

    public function test_supermicro_like_host_path_marks_official_generation_allowed(): void
    {
        Config::set('inventory.install_preflight_base_path_override', 'D:\\inventory\\laravel');

        [, $output] = $this->runCommand();

        $this->assertStringContainsString('[OK] Official package/site-kit generation allowed on approved Supermicro active Laravel host', $output);
    }

    public function test_it_admin_like_host_path_marks_official_generation_blocked(): void
    {
        Config::set('inventory.install_preflight_base_path_override', 'D:\\xampp\\htdocs\\inventaris\\pc-inventory\\laravel');

        [$exitCode, $output] = $this->runCommand();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('[WARN] Host/path appears to be the IT-ADMIN development repo', $output);
        $this->assertStringContainsString('[FAIL] Official package/site-kit generation blocked on IT-ADMIN development repo', $output);
    }

    public function test_null_path_override_falls_back_to_real_base_path(): void
    {
        Config::set('inventory.install_preflight_base_path_override', null);

        [, $output] = $this->runCommand();

        $this->assertStringContainsString('Laravel base path: ' . base_path(), $output);
    }

    public function test_unknown_host_path_produces_warn(): void
    {
        Config::set('inventory.install_preflight_base_path_override', 'E:\\unknown\\laravel');

        [$exitCode, $output] = $this->runCommand();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('[WARN] Host/path is unknown for productized package/site-kit generation', $output);
        $this->assertStringContainsString('[WARN] Official package/site-kit generation blocked until host/path is approved', $output);
    }

    public function test_required_operational_commands_are_checked_by_availability_only(): void
    {
        [, $output] = $this->runCommand();

        foreach ([
            'inventory:doctor',
            'inventory:direct-pilot-status',
            'inventory:direct-site-kit-audit',
            'inventory:direct-runner-triage',
            'inventory:production-readiness',
        ] as $command) {
            $this->assertStringContainsString("[OK] Command registered: {$command}", $output);
        }

        $this->assertStringContainsString('Command availability only is checked; nested Artisan commands are not run.', $output);
    }

    public function test_setup_wizard_not_implemented_is_reported(): void
    {
        [, $output] = $this->runCommand();

        $this->assertStringContainsString('Setup Wizard Readiness', $output);
        $this->assertStringContainsString('[WARN] Portal Setup Wizard MVP is not implemented yet.', $output);
        $this->assertStringContainsString('[INFO] First admin/company/site setup remains manual until Phase 18C.', $output);
    }

    public function test_mvp_manual_boundaries_are_printed(): void
    {
        [, $output] = $this->runCommand();

        foreach ([
            'MariaDB/MySQL installation remains manual in MVP.',
            'DB migration execution remains manual in MVP.',
            'IIS + PHP FastCGI setup remains manual in MVP.',
            'Direct repair_update remains manual in MVP.',
        ] as $line) {
            $this->assertStringContainsString($line, $output);
        }
    }

    public function test_output_does_not_include_app_key_value(): void
    {
        Config::set('app.key', 'base64:do-not-print-this-app-key-value');

        [, $output] = $this->runCommand();

        $this->assertStringNotContainsString('do-not-print-this-app-key-value', $output);
        $this->assertStringNotContainsString('base64:do-not-print-this-app-key-value', $output);
    }

    public function test_output_does_not_include_token_like_secret_values(): void
    {
        Config::set('inventory.site_tokens_json', json_encode([
            'SITE-HQ' => [
                'collector' => 'collector-token-super-secret-value',
                'direct_runner' => 'direct-runner-token-super-secret-value',
            ],
        ]));

        [, $output] = $this->runCommand();

        $this->assertStringNotContainsString('collector-token-super-secret-value', $output);
        $this->assertStringNotContainsString('direct-runner-token-super-secret-value', $output);
    }

    public function test_command_does_not_create_generated_package_or_site_kit_files(): void
    {
        $downloadsRoot = $this->testStorageRoot . '/downloads';
        $before = File::allFiles($downloadsRoot);

        $this->runCommand();

        $this->assertSame(count($before), count(File::allFiles($downloadsRoot)));
        $this->assertFalse(is_dir($downloadsRoot . '/site-kit-SITE-HQ'));
        $this->assertFalse(is_file($downloadsRoot . '/package.zip'));
    }

    public function test_command_does_not_run_migrations(): void
    {
        $before = $this->migrationRows();

        $this->runCommand();

        $this->assertSame($before, $this->migrationRows());
    }

    public function test_command_does_not_run_nested_artisan_commands(): void
    {
        [, $output] = $this->runCommand();

        $this->assertStringContainsString('nested Artisan commands are not run', $output);
        $this->assertStringNotContainsString('Inventory production readiness checklist', $output);
        $this->assertStringNotContainsString('Direct HTTPS Site-kit Audit', $output);
    }

    public function test_missing_storage_path_produces_fail_or_warn_according_to_scope(): void
    {
        Config::set('inventory.raw_archive_path', $this->relativeStoragePath('missing-raw'));
        Config::set('inventory.downloads_path', $this->relativeStoragePath('missing-downloads'));
        Config::set('inventory.install_preflight_base_path_override', 'E:\\unknown\\laravel');

        [$exitCode, $output] = $this->runCommand();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('[FAIL] Raw archive path missing, unreadable, or unwritable', $output);
        $this->assertStringContainsString('[WARN] Inventory downloads/site-kit path missing, unreadable, or unwritable', $output);
    }

    public function test_backup_path_missing_or_policy_unknown_produces_warn_without_mutation(): void
    {
        Config::set('inventory.backups_path', $this->relativeStoragePath('missing-backups'));

        [$exitCode, $output] = $this->runCommand();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('[WARN] Backup path missing, unreadable, or unwritable', $output);
        $this->assertStringContainsString('[WARN] Backup policy and restore rehearsal are not verified by this command', $output);
        $this->assertDirectoryDoesNotExist($this->testStorageRoot . '/missing-backups');
    }

    private function runCommand(): array
    {
        $this->withoutMockingConsoleOutput();

        $buffer = new BufferedOutput();
        $exitCode = Artisan::call('inventory:install-preflight', [], $buffer);

        return [$exitCode, $buffer->fetch()];
    }

    private function relativeStoragePath(string $path): string
    {
        return 'framework/testing/install-preflight/' . basename($this->testStorageRoot) . '/' . trim($path, '/');
    }

    private function migrationRows(): array
    {
        try {
            return DB::table('migrations')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
        } catch (\Throwable) {
            return [];
        }
    }
}
