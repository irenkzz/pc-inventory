<?php

namespace Tests\Feature;

use App\Models\Collector;
use App\Models\Runner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

class ProductionReadinessCommandTest extends TestCase
{
    use RefreshDatabase;

    private string $testStorageRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->testStorageRoot = storage_path('app/framework/testing/production-readiness/' . str_replace('.', '_', uniqid('', true)));
        File::ensureDirectoryExists($this->testStorageRoot . '/raw');
        File::ensureDirectoryExists($this->testStorageRoot . '/downloads');
        File::ensureDirectoryExists($this->testStorageRoot . '/backups');

        Config::set('app.key', 'base64:production-readiness-test-key');
        Config::set('app.debug', false);
        Config::set('app.env', 'local');
        Config::set('app.url', 'https://inventory-pilot.internal.lan');
        Config::set('app.timezone', 'Asia/Jayapura');
        Config::set('inventory.raw_archive_path', $this->relativeStoragePath('raw'));
        Config::set('inventory.downloads_path', $this->relativeStoragePath('downloads'));
        Config::set('inventory.backups_path', $this->relativeStoragePath('backups'));
        Config::set('inventory.site_tokens_json', null);
    }

    protected function tearDown(): void
    {
        if (isset($this->testStorageRoot) && is_dir($this->testStorageRoot)) {
            File::deleteDirectory($this->testStorageRoot);
        }

        parent::tearDown();
    }

    public function test_command_exists_and_renders_required_sections(): void
    {
        [$exitCode, $output] = $this->runCommand();

        $this->assertSame(0, $exitCode);
        foreach ([
            'Environment',
            'Database',
            'HTTPS / Proxy',
            'Storage / Evidence',
            'Security / Secrets',
            'Operational Commands',
            'Direct HTTPS Readiness',
            'Collector-share Readiness',
            'Cutover Blockers',
            'Result',
        ] as $section) {
            $this->assertStringContainsString($section, $output);
        }
    }

    public function test_healthy_pilot_like_environment_returns_warn_not_pass(): void
    {
        [$exitCode, $output] = $this->runCommand();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Pilot readiness:', $output);
        $this->assertStringContainsString('Direct HTTPS small-site readiness:', $output);
        $this->assertStringContainsString('Production/cutover readiness:', $output);
        $this->assertStringContainsString('Result: WARN', $output);
        $this->assertStringNotContainsString('Result: PASS', $output);
    }

    public function test_production_with_debug_true_returns_fail(): void
    {
        Config::set('app.env', 'production');
        Config::set('app.debug', true);

        [$exitCode, $output] = $this->runCommand();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('[FAIL] APP_DEBUG=true in production', $output);
        $this->assertStringContainsString('Result: FAIL', $output);
    }

    public function test_http_app_url_in_production_returns_fail(): void
    {
        Config::set('app.env', 'production');
        Config::set('app.url', 'http://inventory.example.test');

        [$exitCode, $output] = $this->runCommand();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('[FAIL] APP_URL uses HTTP or is not HTTPS in production', $output);
        $this->assertStringContainsString('Result: FAIL', $output);
    }

    public function test_https_app_url_returns_ok_for_https_check(): void
    {
        Config::set('app.url', 'https://inventory-pilot.internal.lan');

        [, $output] = $this->runCommand();

        $this->assertStringContainsString('[OK] APP_URL uses HTTPS', $output);
    }

    public function test_sqlite_warns_outside_production_and_fails_in_production_unless_allowed(): void
    {
        Config::set('app.env', 'local');

        [$localExitCode, $localOutput] = $this->runCommand();

        $this->assertSame(0, $localExitCode);
        $this->assertStringContainsString('[WARN] SQLite used outside production database configuration', $localOutput);

        Config::set('app.env', 'production');
        Config::set('app.url', 'https://inventory.internal.lan');
        Config::set('inventory.production_sqlite_allowed', false);

        [$productionExitCode, $productionOutput] = $this->runCommand();

        $this->assertSame(1, $productionExitCode);
        $this->assertStringContainsString('[FAIL] SQLite used in production without explicit allowance', $productionOutput);

        Config::set('inventory.production_sqlite_allowed', true);

        [$allowedExitCode, $allowedOutput] = $this->runCommand();

        $this->assertSame(0, $allowedExitCode);
        $this->assertStringNotContainsString('[FAIL] SQLite used in production without explicit allowance', $allowedOutput);
    }

    public function test_app_key_presence_is_shown_without_printing_value(): void
    {
        Config::set('app.key', 'base64:super-secret-app-key-value');

        [, $output] = $this->runCommand();

        $this->assertStringContainsString('[OK] APP_KEY present: yes', $output);
        $this->assertStringNotContainsString('super-secret-app-key-value', $output);
    }

    public function test_database_reachable_check_returns_ok_in_normal_test_setup(): void
    {
        [, $output] = $this->runCommand();

        $this->assertStringContainsString('[OK] Database reachable: yes', $output);
        $this->assertStringContainsString('[OK] Migrations table reachable: yes', $output);
    }

    public function test_migrator_problem_returns_fail_when_migrations_table_missing(): void
    {
        Schema::drop('migrations');

        [$exitCode, $output] = $this->runCommand();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('[FAIL] Migrations table reachable: no', $output);
        $this->assertStringContainsString('Result: FAIL', $output);
    }

    public function test_storage_raw_archive_or_download_path_missing_returns_fail(): void
    {
        Config::set('inventory.raw_archive_path', $this->relativeStoragePath('missing-raw'));
        Config::set('inventory.downloads_path', $this->relativeStoragePath('missing-downloads'));

        [$exitCode, $output] = $this->runCommand();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('[FAIL] Raw archive path missing or not writable', $output);
        $this->assertStringContainsString('[FAIL] Downloads path missing or not writable', $output);
    }

    public function test_required_operational_command_registration_is_checked(): void
    {
        [, $output] = $this->runCommand();

        foreach ([
            'inventory:doctor',
            'inventory:direct-pilot-status',
            'inventory:direct-site-kit-audit',
            'inventory:direct-runner-triage',
        ] as $command) {
            $this->assertStringContainsString("[OK] Command registered: {$command}", $output);
        }
    }

    public function test_direct_https_runner_summary_counts_current_stale_old_and_missing_version_runners(): void
    {
        $this->travelTo('2026-05-06 12:00:00');

        Runner::query()->create([
            'runner_id' => 'PC-DIRECT-CURRENT',
            'transport_mode' => 'direct_https',
            'runner_version' => '1.0.22',
            'last_direct_heartbeat_at' => now()->subHour(),
        ]);
        Runner::query()->create([
            'runner_id' => 'PC-DIRECT-OLD',
            'transport_mode' => 'direct_https',
            'runner_version' => '1.0.21',
            'last_direct_heartbeat_at' => now()->subHours(30),
        ]);
        Runner::query()->create([
            'runner_id' => 'PC-DIRECT-MISSING',
            'transport_mode' => 'direct_https',
            'runner_version' => null,
            'last_direct_poll_at' => now()->subMinutes(10),
        ]);

        [, $output] = $this->runCommand();

        $this->assertStringContainsString('Direct HTTPS runner count: 3', $output);
        $this->assertStringContainsString('Stale Direct HTTPS runner count: 1', $output);
        $this->assertStringContainsString('Direct HTTPS runners on expected version 1.0.22 count: 1', $output);
        $this->assertStringContainsString('Direct HTTPS runners missing version count: 1', $output);
        $this->assertStringContainsString('Direct HTTPS runners on old version count: 1', $output);
    }

    public function test_collector_share_runner_is_counted_but_not_warned_for_missing_direct_poll(): void
    {
        Runner::query()->create([
            'runner_id' => 'PC-COLLECTOR-SHARE',
            'transport_mode' => 'collector_share',
            'last_direct_poll_at' => null,
        ]);
        Collector::query()->create([
            'site_id' => $this->createSiteId(),
            'collector_name' => 'collector-01',
            'last_seen_at' => now(),
        ]);

        [, $output] = $this->runCommand();

        $this->assertStringContainsString('Collector-share runner count: 1', $output);
        $this->assertStringContainsString('Recent collector heartbeat count: 1', $output);
        $this->assertStringNotContainsString('PC-COLLECTOR-SHARE', $output);
        $this->assertStringNotContainsString('missing Direct HTTPS poll', $output);
    }

    public function test_fake_secrets_seeded_in_config_and_database_fields_are_not_printed(): void
    {
        Config::set('inventory.site_tokens_json', json_encode([
            'SITE-SECRET' => [
                'collector' => 'collector-secret-token-value',
                'direct_runner' => 'direct-runner-secret-token-value',
            ],
        ]));

        Runner::query()->create([
            'runner_id' => 'PC-SECRET',
            'transport_mode' => 'direct_https',
            'runner_version' => '1.0.22',
            'raw_state_json' => ['token' => 'raw-state-secret-token-value'],
        ]);

        [, $output] = $this->runCommand();

        $this->assertStringContainsString('Site token metadata count: 2', $output);
        $this->assertStringNotContainsString('collector-secret-token-value', $output);
        $this->assertStringNotContainsString('direct-runner-secret-token-value', $output);
        $this->assertStringNotContainsString('raw-state-secret-token-value', $output);
    }

    public function test_command_is_read_only_for_counts_and_timestamps(): void
    {
        $runner = Runner::query()->create([
            'runner_id' => 'PC-READONLY',
            'transport_mode' => 'direct_https',
            'runner_version' => '1.0.22',
            'last_direct_heartbeat_at' => '2026-05-06 10:00:00',
        ]);

        $beforeCounts = [
            'runners' => DB::table('runners')->count(),
            'collectors' => DB::table('collectors')->count(),
            'runner_commands' => DB::table('runner_commands')->count(),
        ];
        $beforeRunner = $runner->fresh()->getAttributes();

        $this->runCommand();

        $this->assertSame($beforeCounts, [
            'runners' => DB::table('runners')->count(),
            'collectors' => DB::table('collectors')->count(),
            'runner_commands' => DB::table('runner_commands')->count(),
        ]);
        $this->assertSame($beforeRunner, $runner->fresh()->getAttributes());
    }

    public function test_output_ends_with_exactly_one_result_line(): void
    {
        [, $output] = $this->runCommand();

        $this->assertSame(1, preg_match_all('/^Result: (PASS|WARN|FAIL)\r?$/m', $output));
        $this->assertMatchesRegularExpression('/Result: (PASS|WARN|FAIL)\s*$/', $output);
    }

    private function runCommand(): array
    {
        $this->withoutMockingConsoleOutput();

        $buffer = new BufferedOutput();
        $exitCode = Artisan::call('inventory:production-readiness', [], $buffer);

        return [$exitCode, $buffer->fetch()];
    }

    private function relativeStoragePath(string $path): string
    {
        return 'framework/testing/production-readiness/' . basename($this->testStorageRoot) . '/' . trim($path, '/');
    }

    private function createSiteId(): string
    {
        DB::table('collector_sites')->insert([
            'site_id' => 'SITE-HQ',
            'site_name' => 'HQ',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return 'SITE-HQ';
    }
}
