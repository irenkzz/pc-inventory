<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

class SiteProfileValidationCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_site_profile_validation_passes_with_warnings_for_sample_placeholder_token(): void
    {
        $this->withoutMockingConsoleOutput();

        $buffer = new BufferedOutput();
        $exitCode = Artisan::call('inventory:validate-site-profile', [
            'profile' => 'deployment/profiles/site_hq.sample.json',
        ], $buffer);
        $output = $buffer->fetch();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('"status": "ok"', $output);
        $this->assertStringContainsString('site_token is still a placeholder', $output);
    }

    public function test_site_profile_validation_strict_mode_fails_on_warnings(): void
    {
        $this->withoutMockingConsoleOutput();

        $buffer = new BufferedOutput();
        $exitCode = Artisan::call('inventory:validate-site-profile', [
            'profile' => 'deployment/profiles/site_hq.sample.json',
            '--strict' => true,
        ], $buffer);
        $output = $buffer->fetch();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('"status": "fail"', $output);
        $this->assertStringContainsString('site_token is still a placeholder', $output);
    }

    public function test_site_profile_validation_fails_invalid_profile(): void
    {
        $profilePath = storage_path('framework/testing/invalid_site_profile.json');
        File::ensureDirectoryExists(dirname($profilePath));
        File::put($profilePath, json_encode([
            'site_id' => 'bad site id',
            'server_base_url' => 'not-a-url',
            'scan_interval_minutes' => 0,
        ]));

        $this->withoutMockingConsoleOutput();

        $buffer = new BufferedOutput();
        $exitCode = Artisan::call('inventory:validate-site-profile', [
            'profile' => $profilePath,
        ], $buffer);
        $output = $buffer->fetch();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('site_name is required', $output);
        $this->assertStringContainsString('server_base_url must be a valid URL', $output);
        $this->assertStringContainsString('scan_interval_minutes must be an integer between 15 and 10080', $output);
    }

    public function test_site_profile_validation_strict_mode_allows_private_internal_http_url(): void
    {
        $profilePath = storage_path('framework/testing/private_internal_http_site_profile.json');
        File::ensureDirectoryExists(dirname($profilePath));
        File::put($profilePath, json_encode([
            'site_id' => 'SITE-HQ',
            'site_name' => 'Head Office',
            'collector_name' => 'hq-collector',
            'share_root' => '\\\\srv-server02\\Shares\\PUBLIC\\Inventory\\sites\\SITE-HQ',
            'server_base_url' => 'http://192.168.100.14:8000',
            'site_token' => 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
            'scan_interval_minutes' => 240,
            'collector_poll_interval_minutes' => 5,
            'task_random_delay_minutes' => 15,
        ]));

        $this->withoutMockingConsoleOutput();

        $buffer = new BufferedOutput();
        $exitCode = Artisan::call('inventory:validate-site-profile', [
            'profile' => $profilePath,
            '--strict' => true,
        ], $buffer);
        $output = $buffer->fetch();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('"status": "ok"', $output);
        $this->assertStringContainsString('"warnings": []', $output);
    }

    public function test_prepare_site_profile_writes_strict_ready_profile_from_registered_token(): void
    {
        $tokenFile = storage_path('framework/testing/prepare_site_tokens.json');
        $outputProfile = storage_path('framework/testing/generated_site_hq.json');

        File::ensureDirectoryExists(dirname($tokenFile));
        File::put($tokenFile, json_encode(['SITE-HQ' => 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa']));
        File::delete($outputProfile);
        Config::set('inventory.site_tokens_file', $tokenFile);

        $this->withoutMockingConsoleOutput();

        $buffer = new BufferedOutput();
        $exitCode = Artisan::call('inventory:prepare-site-profile', [
            'profile' => 'deployment/profiles/site_hq.sample.json',
            '--output' => $outputProfile,
            '--server-base-url' => 'https://inventory.example.local',
            '--strict' => true,
        ], $buffer);
        $output = $buffer->fetch();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('"status": "ok"', $output);
        $this->assertFileExists($outputProfile);

        $profile = json_decode((string) file_get_contents($outputProfile), true);
        $this->assertSame('SITE-HQ', $profile['site_id']);
        $this->assertSame('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', $profile['site_token']);
        $this->assertSame('https://inventory.example.local', $profile['server_base_url']);
    }

    public function test_prepare_site_profile_fails_when_no_site_token_is_available(): void
    {
        $tokenFile = storage_path('framework/testing/missing_prepare_site_tokens.json');
        $outputProfile = storage_path('framework/testing/generated_missing_token.json');

        File::delete($tokenFile);
        File::delete($outputProfile);
        Config::set('inventory.site_tokens_file', $tokenFile);

        $this->withoutMockingConsoleOutput();

        $buffer = new BufferedOutput();
        $exitCode = Artisan::call('inventory:prepare-site-profile', [
            'profile' => 'deployment/profiles/site_hq.sample.json',
            '--output' => $outputProfile,
            '--server-base-url' => 'https://inventory.example.local',
        ], $buffer);
        $output = $buffer->fetch();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('No site token provided and no registered token found for SITE-HQ', $output);
        $this->assertFileDoesNotExist($outputProfile);
    }

    public function test_build_site_kit_strict_mode_fails_on_profile_warnings(): void
    {
        $this->withoutMockingConsoleOutput();

        $buffer = new BufferedOutput();
        $exitCode = Artisan::call('inventory:build-site-kit', [
            '--profile' => 'deployment/profiles/site_hq.sample.json',
            '--strict' => true,
        ], $buffer);
        $output = $buffer->fetch();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('"status": "fail"', $output);
        $this->assertStringContainsString('Strict profile warnings', $output);
        $this->assertStringContainsString('site_token is still a placeholder', $output);
    }

    public function test_build_site_kit_strict_mode_accepts_cli_overrides(): void
    {
        Config::set('inventory.downloads_path', 'framework/testing/site-kit-build');
        File::deleteDirectory(storage_path('app/framework/testing/site-kit-build'));

        $this->withoutMockingConsoleOutput();

        $buffer = new BufferedOutput();
        $exitCode = Artisan::call('inventory:build-site-kit', [
            '--profile' => 'deployment/profiles/site_hq.sample.json',
            '--site-token' => 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb',
            '--server-base-url' => 'https://inventory.example.local',
            '--strict' => true,
        ], $buffer);
        $output = $buffer->fetch();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('"profile_warnings": []', $output);

        $collectorConfig = storage_path('app/framework/testing/site-kit-build/site-kit-SITE-HQ/collector/collector_config.json');
        $this->assertFileExists($collectorConfig);
        $this->assertFileExists(storage_path('app/framework/testing/site-kit-build/site-kit-SITE-HQ/START_HERE_INSTALL_COLLECTOR_PC.cmd'));
        $this->assertFileExists(storage_path('app/framework/testing/site-kit-build/site-kit-SITE-HQ/INSTALL_COLLECTOR_SITE.cmd'));
        $this->assertFileExists(storage_path('app/framework/testing/site-kit-build/site-kit-SITE-HQ/README_COLLECTOR_SITE.txt'));
        $this->assertFileExists(storage_path('app/framework/testing/site-kit-build/site-kit-SITE-HQ/INSTALL_THIS_PC_RUNNER_ONLY.cmd'));
        $this->assertFileExists(storage_path('app/framework/testing/site-kit-build/site-kit-SITE-HQ/READ_ME_FIRST_FOR_BRANCH.txt'));
        $this->assertFileDoesNotExist(storage_path('app/framework/testing/site-kit-build/site-kit-SITE-HQ/INSTALL_THIS_PC_DIRECT_HTTPS_RUNNER.cmd'));

        $config = json_decode((string) file_get_contents($collectorConfig), true);
        $this->assertSame('bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb', $config['site_token']);
        $this->assertSame('https://inventory.example.local', $config['server_base_url']);

        $branchInstructions = (string) file_get_contents(storage_path('app/framework/testing/site-kit-build/site-kit-SITE-HQ/READ_ME_FIRST_FOR_BRANCH.txt'));
        $this->assertStringContainsString('START_HERE_INSTALL_COLLECTOR_PC.cmd', $branchInstructions);
        $this->assertStringContainsString('INSTALL_THIS_PC_RUNNER_ONLY.cmd', $branchInstructions);
        $this->assertStringContainsString('copies itself locally, then asks for administrator approval', $branchInstructions);

        $runnerLauncher = (string) file_get_contents(storage_path('app/framework/testing/site-kit-build/site-kit-SITE-HQ/INSTALL_THIS_PC_RUNNER_ONLY.cmd'));
        $this->assertStringContainsString('set "STAGE=%PUBLIC%\\InternalInventorySiteKit\\site-kit-SITE-HQ"', $runnerLauncher);
        $this->assertStringContainsString('pushd "%SOURCE%"', $runnerLauncher);
        $this->assertStringContainsString('robocopy "." "%STAGE%"', $runnerLauncher);
        $this->assertStringContainsString('Start-Process powershell.exe -Verb RunAs', $runnerLauncher);
        $this->assertStringNotContainsString('install_direct_https_runner.ps1', $runnerLauncher);

        $collectorSiteLauncher = (string) file_get_contents(storage_path('app/framework/testing/site-kit-build/site-kit-SITE-HQ/INSTALL_COLLECTOR_SITE.cmd'));
        $this->assertStringContainsString('HQ/branch/multi-PC collector-share mode', $collectorSiteLauncher);
        $this->assertStringContainsString('collector\\install_collector_site.ps1', $collectorSiteLauncher);
        $this->assertStringNotContainsString('dddddddddddddddddddddddddddddddd', $collectorSiteLauncher);

        $collectorReadme = (string) file_get_contents(storage_path('app/framework/testing/site-kit-build/site-kit-SITE-HQ/README_COLLECTOR_SITE.txt'));
        $this->assertStringContainsString('HQ, branch, lab, or multi-PC site', $collectorReadme);
        $this->assertStringContainsString('Runner PCs -> local/SMB branch share -> Collector -> Laravel HTTPS portal', $collectorReadme);
        $this->assertStringContainsString('Python 3.x is required on the collector host for MVP', $collectorReadme);
        $this->assertStringContainsString('Python is not auto-installed or bundled in this MVP', $collectorReadme);
        $this->assertStringContainsString('Requires a branch-share path', $collectorReadme);
        $this->assertStringContainsString('/collectors', $collectorReadme);
        $this->assertStringContainsString('/runners', $collectorReadme);
        $this->assertStringContainsString('Hybrid means one organization may use both modes across different sites', $collectorReadme);
        $this->assertStringContainsString('It does not mean mixing Direct HTTPS and collector-share inside one runner installation', $collectorReadme);
        $this->assertStringNotContainsString('bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb', $collectorReadme);

        $installer = (string) file_get_contents(storage_path('app/framework/testing/site-kit-build/site-kit-SITE-HQ/INSTALL_SITE_KIT.ps1'));
        $this->assertStringContainsString('function Invoke-LoggedCommand', $installer);
        $this->assertStringContainsString('function Require-ScheduledTask', $installer);
        $this->assertStringContainsString("Require-ScheduledTask 'InternalInventoryCollectorRelay'", $installer);
        $this->assertStringContainsString('Require-ScheduledTask "InternalInventoryRunner"', $installer);

        $collectorInstaller = (string) file_get_contents(storage_path('app/framework/testing/site-kit-build/site-kit-SITE-HQ/collector/install_collector.ps1'));
        $collectorSiteInstaller = (string) file_get_contents(storage_path('app/framework/testing/site-kit-build/site-kit-SITE-HQ/collector/install_collector_site.ps1'));
        $runnerInstaller = (string) file_get_contents(storage_path('app/framework/testing/site-kit-build/site-kit-SITE-HQ/runner/scripts/install_runner.ps1'));
        $scannerScript = (string) file_get_contents(storage_path('app/framework/testing/site-kit-build/site-kit-SITE-HQ/runner/scripts/scanner_core_v4.ps1'));
        $this->assertFileExists(storage_path('app/framework/testing/site-kit-build/site-kit-SITE-HQ/collector/run_collector_hidden.pyw'));
        $this->assertFileExists(storage_path('app/framework/testing/site-kit-build/site-kit-SITE-HQ/collector/install_collector_site.ps1'));
        $this->assertFileDoesNotExist(storage_path('app/framework/testing/site-kit-build/site-kit-SITE-HQ/runner/scripts/install_direct_https_runner.ps1'));
        $this->assertFileDoesNotExist(storage_path('app/framework/testing/site-kit-build/site-kit-SITE-HQ/branch-share/packages/runner/current/scripts/install_direct_https_runner.ps1'));
        $this->assertFileExists(storage_path('app/framework/testing/site-kit-build/site-kit-SITE-HQ/runner/tools/smartctl.exe'));
        $this->assertFileExists(storage_path('app/framework/testing/site-kit-build/site-kit-SITE-HQ/runner/tools/drivedb.h'));
        $this->assertFileExists(storage_path('app/framework/testing/site-kit-build/site-kit-SITE-HQ/runner/tools/COPYING.smartmontools.txt'));
        $this->assertStringContainsString('function Resolve-PythonExecutable', $collectorInstaller);
        $this->assertStringContainsString("'pythonw', 'python'", $collectorInstaller);
        $this->assertStringContainsString('Get-Command py -ErrorAction SilentlyContinue', $collectorInstaller);
        $this->assertStringContainsString('Python runtime was not found', $collectorInstaller);
        $this->assertStringContainsString('run_collector_hidden.pyw', $collectorInstaller);
        $this->assertStringContainsString('install_collector.ps1', $collectorSiteInstaller);
        $this->assertStringContainsString('sharedRoot reachable and read/write probe succeeded', $collectorSiteInstaller);
        $this->assertStringContainsString('Invoke-WebRequest -Uri $healthUrl -UseBasicParsing', $collectorSiteInstaller);
        $this->assertStringNotContainsString('SkipCertificateCheck', $collectorSiteInstaller);
        $this->assertStringNotContainsString('bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb', $output);
        $this->assertStringNotContainsString('powershell.exe -NoProfile -ExecutionPolicy Bypass -WindowStyle Hidden', $collectorInstaller);
        $this->assertStringContainsString('schtasks.exe /Create', $collectorInstaller);
        $this->assertStringContainsString('schtasks.exe /Create', $runnerInstaller);
        $this->assertStringContainsString("Join-Path \$ScriptRoot 'tools\\smartctl.exe'", $scannerScript);
        $this->assertStringNotContainsString('Register-ScheduledTask', $collectorInstaller);
        $this->assertStringNotContainsString('Register-ScheduledTask', $runnerInstaller);
        $this->assertStringNotContainsString('New-ScheduledTaskTrigger', $collectorInstaller);
        $this->assertStringNotContainsString('New-ScheduledTaskTrigger', $runnerInstaller);
    }

    public function test_direct_https_site_profile_validation_passes_with_direct_token_warning(): void
    {
        $this->withoutMockingConsoleOutput();

        $buffer = new BufferedOutput();
        $exitCode = Artisan::call('inventory:validate-site-profile', [
            'profile' => 'deployment/profiles/site_hq_direct_https.sample.json',
        ], $buffer);
        $output = $buffer->fetch();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('"status": "ok"', $output);
        $this->assertStringContainsString('direct_runner_token is still a placeholder', $output);
    }

    public function test_direct_https_https_server_base_url_passes_without_http_guardrail_warning(): void
    {
        $profilePath = $this->writeDirectProfile('direct_https_profile_https.json', [
            'server_base_url' => 'https://inventory.example.local',
            'direct_runner_token' => 'direct-runner-token-secret-123456',
        ]);

        $this->withoutMockingConsoleOutput();

        $buffer = new BufferedOutput();
        $exitCode = Artisan::call('inventory:validate-site-profile', [
            'profile' => $profilePath,
        ], $buffer);
        $output = $buffer->fetch();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('"status": "ok"', $output);
        $this->assertStringNotContainsString('Direct HTTPS profile uses plain HTTP', $output);
        $this->assertStringNotContainsString('direct-runner-token-secret-123456', $output);
    }

    public function test_direct_https_localhost_http_passes_with_local_smoke_test_warning(): void
    {
        $profilePath = $this->writeDirectProfile('direct_https_profile_localhost_http.json', [
            'server_base_url' => 'http://localhost:8000',
            'direct_runner_token' => 'direct-runner-token-secret-123456',
        ]);

        $this->withoutMockingConsoleOutput();

        $buffer = new BufferedOutput();
        $exitCode = Artisan::call('inventory:validate-site-profile', [
            'profile' => $profilePath,
        ], $buffer);
        $output = $buffer->fetch();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Direct HTTPS profile uses plain HTTP. This is allowed only for local development smoke testing. Do not use for pilot, production, or cross-network deployment.', $output);
        $this->assertStringNotContainsString('direct-runner-token-secret-123456', $output);
    }

    public function test_direct_https_loopback_http_passes_with_local_smoke_test_warning(): void
    {
        $profilePath = $this->writeDirectProfile('direct_https_profile_loopback_http.json', [
            'server_base_url' => 'http://127.0.0.1:8000',
            'direct_runner_token' => 'direct-runner-token-secret-123456',
        ]);

        $this->withoutMockingConsoleOutput();

        $buffer = new BufferedOutput();
        $exitCode = Artisan::call('inventory:validate-site-profile', [
            'profile' => $profilePath,
        ], $buffer);
        $output = $buffer->fetch();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Direct HTTPS profile uses plain HTTP. This is allowed only for local development smoke testing. Do not use for pilot, production, or cross-network deployment.', $output);
        $this->assertStringNotContainsString('direct-runner-token-secret-123456', $output);
    }

    public function test_direct_https_private_lan_http_is_blocked(): void
    {
        $profilePath = $this->writeDirectProfile('direct_https_profile_private_http.json', [
            'server_base_url' => 'http://192.168.100.110:8000',
            'direct_runner_token' => 'direct-runner-token-secret-123456',
        ]);

        $this->withoutMockingConsoleOutput();

        $buffer = new BufferedOutput();
        $exitCode = Artisan::call('inventory:validate-site-profile', [
            'profile' => $profilePath,
        ], $buffer);
        $output = $buffer->fetch();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Direct HTTPS runner mode requires an https:// serverBaseUrl for pilot/production site kits. Plain HTTP may expose direct_runner tokens, inventory payloads, and command polling/ACK traffic.', $output);
        $this->assertStringNotContainsString('direct-runner-token-secret-123456', $output);
    }

    public function test_direct_https_site_kit_build_blocks_private_lan_http_without_printing_token_secret(): void
    {
        $this->withoutMockingConsoleOutput();

        $buffer = new BufferedOutput();
        $exitCode = Artisan::call('inventory:build-site-kit', [
            '--profile' => 'deployment/profiles/site_hq_direct_https.sample.json',
            '--server-base-url' => 'http://192.168.100.110:8000',
            '--direct-runner-token' => 'direct-runner-token-secret-123456',
        ], $buffer);
        $output = $buffer->fetch();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Direct HTTPS runner mode requires an https:// serverBaseUrl for pilot/production site kits. Plain HTTP may expose direct_runner tokens, inventory payloads, and command polling/ACK traffic.', $output);
        $this->assertStringNotContainsString('direct-runner-token-secret-123456', $output);
    }

    public function test_direct_https_site_kit_generates_runner_config_without_collector_by_default(): void
    {
        Config::set('inventory.downloads_path', 'framework/testing/site-kit-direct-build');
        File::deleteDirectory(storage_path('app/framework/testing/site-kit-direct-build'));

        $this->withoutMockingConsoleOutput();

        $buffer = new BufferedOutput();
        $exitCode = Artisan::call('inventory:build-site-kit', [
            '--profile' => 'deployment/profiles/site_hq_direct_https.sample.json',
            '--direct-runner-token' => 'dddddddddddddddddddddddddddddddd',
            '--strict' => true,
        ], $buffer);
        $output = $buffer->fetch();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('"transport_mode": "direct_https"', $output);

        $buildRoot = storage_path('app/framework/testing/site-kit-direct-build/site-kit-SITE-HQ');
        $runnerConfigPath = $buildRoot . '/runner/config/runner-config.template.json';
        $this->assertFileExists($runnerConfigPath);

        $runnerConfig = json_decode((string) file_get_contents($runnerConfigPath), true);
        $this->assertSame('direct_https', $runnerConfig['transport_mode']);
        $this->assertSame('https://inventory.example.local', $runnerConfig['serverBaseUrl']);
        $this->assertSame('dddddddddddddddddddddddddddddddd', $runnerConfig['siteToken']);
        $this->assertSame('SITE-HQ', $runnerConfig['siteId']);
        $this->assertSame('', $runnerConfig['sharedRoot']);
        $this->assertArrayNotHasKey('site_token', $runnerConfig);

        $this->assertFileDoesNotExist($buildRoot . '/collector/collector_config.json');
        $this->assertFileDoesNotExist($buildRoot . '/collector/install_collector_site.ps1');
        $this->assertFileDoesNotExist($buildRoot . '/START_HERE_INSTALL_COLLECTOR_PC.cmd');
        $this->assertFileDoesNotExist($buildRoot . '/INSTALL_COLLECTOR_ONLY.cmd');
        $this->assertFileDoesNotExist($buildRoot . '/INSTALL_COLLECTOR_SITE.cmd');
        $this->assertFileDoesNotExist($buildRoot . '/INSTALL_COLLECTOR_COMMAND.txt');
        $this->assertFileExists($buildRoot . '/INSTALL_THIS_PC_RUNNER_ONLY.cmd');
        $this->assertFileExists($buildRoot . '/INSTALL_THIS_PC_DIRECT_HTTPS_RUNNER.cmd');
        $this->assertFileExists($buildRoot . '/README_DIRECT_HTTPS_RUNNER.txt');
        $this->assertFileExists($buildRoot . '/runner/scripts/install_direct_https_runner.ps1');

        $directRunnerLauncher = (string) file_get_contents($buildRoot . '/INSTALL_THIS_PC_RUNNER_ONLY.cmd');
        $this->assertStringContainsString('install_direct_https_runner.ps1', $directRunnerLauncher);
        $this->assertStringContainsString('runner-config.template.json', $directRunnerLauncher);
        $this->assertStringContainsString('-UseComputerNameAsRunnerId', $directRunnerLauncher);
        $this->assertStringNotContainsString('INSTALL_SITE_KIT.ps1', $directRunnerLauncher);

        $directAliasLauncher = (string) file_get_contents($buildRoot . '/INSTALL_THIS_PC_DIRECT_HTTPS_RUNNER.cmd');
        $this->assertStringContainsString('small/no-IT site', $directAliasLauncher);
        $this->assertStringContainsString('INSTALL_THIS_PC_RUNNER_ONLY.cmd', $directAliasLauncher);
        $this->assertStringNotContainsString('dddddddddddddddddddddddddddddddd', $directAliasLauncher);

        $directReadme = (string) file_get_contents($buildRoot . '/README_DIRECT_HTTPS_RUNNER.txt');
        $this->assertStringContainsString('small/no-IT site', $directReadme);
        $this->assertStringContainsString('Runner on this PC -> Laravel HTTPS portal', $directReadme);
        $this->assertStringContainsString('Requires an HTTPS endpoint and trusted certificate', $directReadme);
        $this->assertStringContainsString('Direct repair_update remains blocked for Direct HTTPS MVP', $directReadme);
        $this->assertStringContainsString('/runners', $directReadme);
        $this->assertStringContainsString('php artisan inventory:direct-pilot-status', $directReadme);
        $this->assertStringContainsString('php artisan inventory:direct-runner-triage {runnerId}', $directReadme);
        $this->assertStringContainsString('php artisan inventory:direct-site-kit-audit', $directReadme);
        $this->assertStringContainsString('Hybrid means one organization may use both modes across different sites', $directReadme);
        $this->assertStringContainsString('It does not mean mixing Direct HTTPS and collector-share inside one runner installation', $directReadme);
        $this->assertStringNotContainsString('dddddddddddddddddddddddddddddddd', $directReadme);

        $directInstaller = (string) file_get_contents($buildRoot . '/runner/scripts/install_direct_https_runner.ps1');
        $this->assertStringContainsString('serverBaseUrl must use https://', $directInstaller);
        $this->assertStringContainsString('Invoke-WebRequest -Uri $healthUrl -UseBasicParsing', $directInstaller);
        $this->assertStringNotContainsString('SkipCertificateCheck', $directInstaller);
        $this->assertStringNotContainsString('dddddddddddddddddddddddddddddddd', $output);
    }

    public function test_invalid_transport_mode_fails_validation(): void
    {
        $profilePath = storage_path('framework/testing/invalid_transport_mode_profile.json');
        File::ensureDirectoryExists(dirname($profilePath));
        File::put($profilePath, json_encode([
            'transport_mode' => 'branch_magic',
            'site_id' => 'SITE-HQ',
            'site_name' => 'Head Office',
            'collector_name' => 'hq-collector',
            'share_root' => '\\\\srv-server02\\Shares\\PUBLIC\\Inventory\\sites\\SITE-HQ',
            'server_base_url' => 'https://inventory.example.local',
            'site_token' => 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
        ]));

        $this->withoutMockingConsoleOutput();

        $buffer = new BufferedOutput();
        $exitCode = Artisan::call('inventory:validate-site-profile', [
            'profile' => $profilePath,
        ], $buffer);
        $output = $buffer->fetch();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('transport_mode must be collector_share or direct_https', $output);
    }

    public function test_direct_https_missing_direct_token_fails_validation(): void
    {
        Config::set('inventory.site_tokens_json', '');
        Config::set('inventory.site_tokens_file', storage_path('framework/testing/missing_direct_token_profile_tokens.json'));
        File::delete(config('inventory.site_tokens_file'));

        $profilePath = storage_path('framework/testing/missing_direct_token_profile.json');
        File::ensureDirectoryExists(dirname($profilePath));
        File::put($profilePath, json_encode([
            'transport_mode' => 'direct_https',
            'site_id' => 'SITE-HQ',
            'site_name' => 'Head Office',
            'server_base_url' => 'https://inventory.example.local',
        ]));

        $this->withoutMockingConsoleOutput();

        $buffer = new BufferedOutput();
        $exitCode = Artisan::call('inventory:validate-site-profile', [
            'profile' => $profilePath,
        ], $buffer);
        $output = $buffer->fetch();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('direct_runner_token is required for direct_https profiles', $output);
    }

    private function writeDirectProfile(string $name, array $overrides = []): string
    {
        $profilePath = storage_path('framework/testing/' . $name);
        File::ensureDirectoryExists(dirname($profilePath));
        File::put($profilePath, json_encode([
            'transport_mode' => 'direct_https',
            'site_id' => 'SITE-HQ',
            'site_name' => 'Head Office',
            'server_base_url' => 'https://inventory.example.local',
            'direct_runner_token' => 'direct-runner-token-secret-123456',
            ...$overrides,
        ]));

        return $profilePath;
    }
}
