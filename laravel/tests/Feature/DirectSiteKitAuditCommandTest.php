<?php

namespace Tests\Feature;

use App\Models\Runner;
use App\Models\RunnerCommand;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

class DirectSiteKitAuditCommandTest extends TestCase
{
    use RefreshDatabase;

    private string $downloadsPath;

    private string $downloadsRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->downloadsPath = 'framework/testing/direct-site-kit-audit-' . str_replace('\\', '-', $this->name());
        $this->downloadsRoot = storage_path('app/' . $this->downloadsPath);
        Config::set('inventory.downloads_path', $this->downloadsPath);
        Config::set('app.url', 'https://inventory-pilot.internal.lan');
        File::deleteDirectory($this->downloadsRoot);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->downloadsRoot);

        parent::tearDown();
    }

    public function test_valid_direct_https_generated_fixture_passes(): void
    {
        $this->writeSiteKit([
            'transport_mode' => 'direct_https',
            'serverBaseUrl' => 'https://inventory-pilot.internal.lan',
            'runnerVersion' => '1.0.22',
            'sharedRoot' => '',
            'siteToken' => 'direct-token-value-not-printed',
        ]);

        [$exitCode, $output] = $this->runCommand();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Direct HTTPS Site-kit Audit', $output);
        $this->assertStringContainsString('[OK] Direct HTTPS site kit found:', $output);
        $this->assertStringContainsString('[OK] Runner config found', $output);
        $this->assertStringContainsString('[OK] README found', $output);
        $this->assertStringContainsString('[OK] README_DIRECT_HTTPS_RUNNER.txt found', $output);
        $this->assertStringContainsString('[OK] INSTALL_THIS_PC_DIRECT_HTTPS_RUNNER.cmd found', $output);
        $this->assertStringContainsString('[OK] runner/scripts/install_direct_https_runner.ps1 found', $output);
        $this->assertStringContainsString('[OK] Direct HTTPS package does not expose INSTALL_COLLECTOR_SITE.cmd top-level entry point', $output);
        $this->assertStringContainsString('[OK] transport_mode is direct_https', $output);
        $this->assertStringContainsString('[OK] serverBaseUrl is https://inventory-pilot.internal.lan', $output);
        $this->assertStringContainsString('[OK] serverBaseUrl uses HTTPS', $output);
        $this->assertStringContainsString('[OK] runner version is 1.0.22', $output);
        $this->assertStringContainsString('[OK] no stale HTTP endpoint detected', $output);
        $this->assertStringContainsString('[OK] no placeholder HTTPS endpoint detected', $output);
        $this->assertStringContainsString('[OK] Direct HTTPS active transport does not require collector-share fields', $output);
        $this->assertStringContainsString('[OK] Direct HTTPS README contains Direct HTTPS wording', $output);
        $this->assertStringContainsString('[OK] Direct HTTPS README contains small/no-IT wording', $output);
        $this->assertStringContainsString('[OK] Direct HTTPS README contains HTTPS/certificate warning', $output);
        $this->assertStringContainsString('[OK] Direct HTTPS README states Direct repair_update remains blocked', $output);
        $this->assertStringContainsString('[OK] no obvious rendered secret detected', $output);
        $this->assertStringContainsString('[OK] collector-share mode is not modified by this audit', $output);
        $this->assertStringContainsString('Result: PASS', $output);
        $this->assertStringNotContainsString('direct-token-value-not-printed', $output);
    }

    public function test_http_server_base_url_fixture_fails(): void
    {
        $this->writeSiteKit([
            'transport_mode' => 'direct_https',
            'serverBaseUrl' => 'http://192.168.100.110:8000',
            'runnerVersion' => '1.0.22',
            'sharedRoot' => '',
        ]);

        [$exitCode, $output] = $this->runCommand();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('[FAIL] serverBaseUrl uses HTTP or stale local endpoint.', $output);
        $this->assertStringContainsString('Result: FAIL', $output);
    }

    public function test_placeholder_https_fixture_fails(): void
    {
        $this->writeSiteKit([
            'transport_mode' => 'direct_https',
            'serverBaseUrl' => 'https://inventory.example.local',
            'runnerVersion' => '1.0.22',
            'sharedRoot' => '',
        ]);

        [$exitCode, $output] = $this->runCommand();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('[FAIL] serverBaseUrl uses placeholder endpoint.', $output);
        $this->assertStringContainsString('Result: FAIL', $output);
    }

    public function test_wrong_transport_fixture_fails(): void
    {
        $this->writeSiteKit([
            'transport_mode' => 'collector_share',
            'serverBaseUrl' => 'https://inventory-pilot.internal.lan',
            'runnerVersion' => '1.0.22',
            'sharedRoot' => '\\\\server\\share',
            'collectorName' => 'site-hq-collector',
        ]);

        [$exitCode, $output] = $this->runCommand();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('[FAIL] transport_mode is collector_share, expected direct_https.', $output);
        $this->assertStringContainsString('Result: FAIL', $output);
    }

    public function test_missing_generated_artifact_fails_safely(): void
    {
        [$exitCode, $output] = $this->runCommand();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('[FAIL] Direct HTTPS site kit not found.', $output);
        $this->assertStringContainsString('Hint: Generate official artifacts on Supermicro after profile validation.', $output);
        $this->assertStringContainsString('Result: FAIL', $output);
    }

    public function test_fake_token_secret_in_fixture_is_not_printed(): void
    {
        $this->writeSiteKit([
            'transport_mode' => 'direct_https',
            'serverBaseUrl' => 'https://inventory-pilot.internal.lan',
            'runnerVersion' => '1.0.22',
            'sharedRoot' => '',
        ], 'Authorization: Bearer fake-token-secret-super-sensitive');

        [$exitCode, $output] = $this->runCommand();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('[FAIL] obvious rendered secret detected; value redacted.', $output);
        $this->assertStringNotContainsString('fake-token-secret-super-sensitive', $output);
        $this->assertStringContainsString('Result: FAIL', $output);
    }

    public function test_command_is_read_only_for_files_database_and_tokens(): void
    {
        $artifact = $this->writeSiteKit([
            'transport_mode' => 'direct_https',
            'serverBaseUrl' => 'https://inventory-pilot.internal.lan',
            'runnerVersion' => '1.0.22',
            'sharedRoot' => '',
        ]);
        $tokenFile = storage_path('framework/testing/direct-site-kit-audit-tokens.json');
        File::ensureDirectoryExists(dirname($tokenFile));
        File::put($tokenFile, json_encode([
            'SITE-HQ' => [
                'token' => 'direct-runner-secret-not-printed',
                'token_type' => 'direct_runner',
                'last_used_at' => null,
                'last_used_ip' => null,
            ],
        ]));
        Config::set('inventory.site_tokens_json', null);
        Config::set('inventory.site_tokens_file', $tokenFile);

        $runner = Runner::query()->create([
            'runner_id' => 'PC-DIRECT-AUDIT',
            'hostname' => 'PC-DIRECT-AUDIT',
            'transport_mode' => 'direct_https',
        ]);
        $command = RunnerCommand::query()->create([
            'runner_id' => $runner->runner_id,
            'command_type' => 'scan_now',
            'status' => 'pending',
            'requested_at' => now(),
        ]);

        $fileTimesBefore = $this->fileTimes($artifact);
        $tokenContentsBefore = File::get($tokenFile);
        $tokenMtimeBefore = File::lastModified($tokenFile);
        $runnerBefore = $runner->fresh()->getAttributes();
        $commandBefore = $command->fresh()->getAttributes();

        [$exitCode, $output] = $this->runCommand();

        $this->assertSame(0, $exitCode);
        $this->assertStringNotContainsString('direct-runner-secret-not-printed', $output);
        $this->assertSame($fileTimesBefore, $this->fileTimes($artifact));
        $this->assertSame($tokenContentsBefore, File::get($tokenFile));
        $this->assertSame($tokenMtimeBefore, File::lastModified($tokenFile));
        $this->assertSame($runnerBefore, $runner->fresh()->getAttributes());
        $this->assertSame($commandBefore, $command->fresh()->getAttributes());

        File::delete($tokenFile);
    }

    public function test_collector_share_behavior_and_direct_repair_update_guardrail_remain_unchanged(): void
    {
        $this->writeSiteKit([
            'transport_mode' => 'direct_https',
            'serverBaseUrl' => 'https://inventory-pilot.internal.lan',
            'runnerVersion' => '1.0.22',
            'sharedRoot' => '',
        ]);

        $collectorRunner = Runner::query()->create([
            'runner_id' => 'PC-COLLECTOR-AUDIT',
            'hostname' => 'PC-COLLECTOR-AUDIT',
            'transport_mode' => 'collector_share',
        ]);
        $directRunner = Runner::query()->create([
            'runner_id' => 'PC-DIRECT-NO-REPAIR',
            'hostname' => 'PC-DIRECT-NO-REPAIR',
            'transport_mode' => 'direct_https',
        ]);

        [$exitCode, $output] = $this->runCommand();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('[OK] collector-share mode is not modified by this audit', $output);
        $this->assertDatabaseHas('runners', [
            'runner_id' => $collectorRunner->runner_id,
            'transport_mode' => 'collector_share',
        ]);

        $this->actingAs(\App\Models\User::query()->create([
            'name' => 'Inventory Admin',
            'email' => 'admin@example.test',
            'password' => bcrypt('password'),
        ]))
            ->post(route('admin.runners.repair', $directRunner))
            ->assertRedirect(route('admin.runners.show', $directRunner))
            ->assertSessionHas('status', 'Repair/update is not supported for direct HTTPS runners yet.');
    }

    private function writeSiteKit(array $runnerConfig, string $readme = 'Direct HTTPS pilot README.'): string
    {
        $artifact = $this->downloadsRoot . DIRECTORY_SEPARATOR . 'site-kit-SITE-HQ';
        $configPath = $artifact . DIRECTORY_SEPARATOR . 'runner' . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'runner-config.template.json';
        File::ensureDirectoryExists(dirname($configPath));
        File::put($configPath, json_encode([
            'runnerId' => '<SET-PER-DEVICE>',
            'siteId' => 'SITE-HQ',
            'siteName' => 'Bhayangkara',
            ...$runnerConfig,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        File::put($artifact . DIRECTORY_SEPARATOR . 'README_SITE_KIT.md', $readme);
        File::put($artifact . DIRECTORY_SEPARATOR . 'READ_ME_FIRST_FOR_BRANCH.txt', 'Direct HTTPS pilot instructions.');
        File::put($artifact . DIRECTORY_SEPARATOR . 'README_DIRECT_HTTPS_RUNNER.txt', "Direct HTTPS runner package.\nUse for a small/no-IT site.\nRequires an HTTPS endpoint and trusted certificate.\nDirect repair_update remains blocked for Direct HTTPS MVP.\n");
        File::put($artifact . DIRECTORY_SEPARATOR . 'INSTALL_THIS_PC_DIRECT_HTTPS_RUNNER.cmd', "@echo off\r\ncall \"%~dp0INSTALL_THIS_PC_RUNNER_ONLY.cmd\"\r\n");
        File::ensureDirectoryExists($artifact . DIRECTORY_SEPARATOR . 'runner' . DIRECTORY_SEPARATOR . 'scripts');
        File::put($artifact . DIRECTORY_SEPARATOR . 'runner' . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'install_direct_https_runner.ps1', "Write-Host 'Direct HTTPS installer'\r\n");

        return $artifact;
    }

    private function runCommand(): array
    {
        $this->withoutMockingConsoleOutput();

        $buffer = new BufferedOutput();
        $exitCode = Artisan::call('inventory:direct-site-kit-audit', [], $buffer);

        return [$exitCode, $buffer->fetch()];
    }

    private function fileTimes(string $root): array
    {
        $files = File::allFiles($root);
        $times = [];
        foreach ($files as $file) {
            $times[$file->getRelativePathname()] = $file->getMTime();
        }
        ksort($times);

        return $times;
    }
}
