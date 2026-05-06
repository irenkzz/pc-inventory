<?php

namespace Tests\Feature;

use App\Models\CollectorSite;
use App\Models\Runner;
use App\Models\RunnerCommand;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

class DirectPilotStatusCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_direct_pilot_status_reports_direct_runner_without_printing_token_secret(): void
    {
        $this->travelTo('2026-05-06 12:00:00');
        Config::set('app.url', 'https://inventory-pilot.internal.lan');
        Config::set('inventory.direct_command_redelivery_minutes', 3);
        Config::set('inventory.site_tokens_json', json_encode([
            'SITE-HQ' => [
                'token' => 'direct-token-secret-not-for-output',
                'token_type' => 'direct_runner',
                'last_used_at' => '2026-05-06 11:50:00',
                'last_used_ip' => '10.0.0.25',
            ],
        ]));

        CollectorSite::query()->create(['site_id' => 'SITE-HQ', 'site_name' => 'Bhayangkara']);
        $runner = Runner::query()->create([
            'runner_id' => 'IT-ADMIN',
            'hostname' => 'IT-ADMIN',
            'site_id' => 'SITE-HQ',
            'runner_guid' => '8c2c8c70-1111-4f24-9b10-1f5a3e3d4d21',
            'runner_version' => '1.0.22',
            'transport_mode' => 'direct_https',
            'last_seen_at' => now()->subMinutes(5),
            'last_direct_heartbeat_at' => now()->subMinutes(5),
            'last_direct_poll_at' => now()->subMinutes(4),
            'last_direct_upload_at' => now()->subMinutes(10),
            'last_successful_inventory_at' => now()->subMinutes(10),
        ]);

        RunnerCommand::query()->create([
            'runner_id' => $runner->runner_id,
            'site_id' => 'SITE-HQ',
            'command_type' => 'scan_now',
            'status' => 'pending',
            'requested_at' => now()->subMinute(),
        ]);
        RunnerCommand::query()->create([
            'runner_id' => $runner->runner_id,
            'site_id' => 'SITE-HQ',
            'command_type' => 'scan_now',
            'status' => 'dispatched',
            'requested_at' => now()->subMinutes(2),
            'dispatched_at' => now()->subMinutes(2),
            'delivered_to_runner_at' => now()->subMinutes(2),
        ]);
        RunnerCommand::query()->create([
            'runner_id' => $runner->runner_id,
            'site_id' => 'SITE-HQ',
            'command_type' => 'scan_now',
            'status' => 'dispatched',
            'requested_at' => now()->subMinutes(20),
            'dispatched_at' => now()->subMinutes(20),
            'delivered_to_runner_at' => now()->subMinutes(20),
        ]);
        RunnerCommand::query()->create([
            'runner_id' => $runner->runner_id,
            'site_id' => 'SITE-HQ',
            'command_type' => 'scan_now',
            'status' => 'failed',
            'requested_at' => now()->subMinutes(30),
            'acknowledged_at' => now()->subMinutes(29),
            'completed_at' => now()->subMinutes(29),
            'completion_status' => 'failed',
        ]);

        [$exitCode, $output] = $this->runCommand();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Direct HTTPS pilot status', $output);
        $this->assertStringContainsString('APP_URL HTTPS status', $output);
        $this->assertStringContainsString('OK', $output);
        $this->assertStringContainsString('blocked for Direct HTTPS MVP', $output);
        $this->assertStringContainsString('SITE-HQ / Bhayangkara', $output);
        $this->assertStringContainsString('IT-ADMIN', $output);
        $this->assertStringContainsString('1.0.22', $output);
        $this->assertStringContainsString('Present ending 4d21', $output);
        $this->assertStringContainsString('Direct runner token metadata', $output);
        $this->assertStringContainsString('direct_runner', $output);
        $this->assertStringContainsString('active', $output);
        $this->assertStringContainsString('10.0.0.25', $output);
        $this->assertStringContainsString('scan_now / pending', $output);
        $this->assertStringContainsString('Command wording: polling means delivery, not execution. ACK is the execution result.', $output);
        $this->assertStringNotContainsString('direct-token-secret-not-for-output', $output);
        $this->assertStringNotContainsString('8c2c8c70-1111-4f24-9b10-1f5a3e3d4d21', $output);

        $this->assertMatchesRegularExpression('/\|\s+1\s+\|\s+2\s+\|\s+1\s+\|\s+1\s+\|/', $output);
    }

    public function test_direct_pilot_status_is_read_only(): void
    {
        Config::set('app.url', 'https://inventory-pilot.internal.lan');

        $runner = Runner::query()->create([
            'runner_id' => 'PC-DIRECT-READONLY',
            'hostname' => 'PC-DIRECT-READONLY',
            'transport_mode' => 'direct_https',
            'last_direct_heartbeat_at' => '2026-05-06 10:00:00',
        ]);
        $command = RunnerCommand::query()->create([
            'runner_id' => $runner->runner_id,
            'command_type' => 'scan_now',
            'status' => 'pending',
            'requested_at' => '2026-05-06 10:05:00',
            'payload_json' => ['note' => 'secret-ish payload should not print'],
        ]);

        $beforeRunner = $runner->fresh()->getAttributes();
        $beforeCommand = $command->fresh()->getAttributes();

        [$exitCode] = $this->runCommand();

        $this->assertSame(0, $exitCode);
        $this->assertSame($beforeRunner, $runner->fresh()->getAttributes());
        $this->assertSame($beforeCommand, $command->fresh()->getAttributes());
    }

    public function test_direct_pilot_status_renders_friendly_missing_timestamp_wording(): void
    {
        Config::set('app.url', 'https://inventory-pilot.internal.lan');
        Config::set('inventory.site_tokens_json', json_encode([
            'SITE-HQ' => [
                'token' => 'another-secret-not-for-output',
                'token_type' => 'direct_runner',
            ],
        ]));

        Runner::query()->create([
            'runner_id' => 'PC-DIRECT-EMPTY',
            'hostname' => null,
            'transport_mode' => 'direct_https',
            'runner_guid' => null,
        ]);

        [$exitCode, $output] = $this->runCommand();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('No heartbeat yet', $output);
        $this->assertStringContainsString('No direct poll yet', $output);
        $this->assertStringContainsString('No upload yet', $output);
        $this->assertStringContainsString('No ACK yet', $output);
        $this->assertStringContainsString('No direct commands yet', $output);
        $this->assertStringContainsString('No data yet', $output);
        $this->assertStringContainsString('Token has not been used yet', $output);
        $this->assertStringNotContainsString('another-secret-not-for-output', $output);
    }

    public function test_direct_pilot_status_http_app_url_produces_warning(): void
    {
        Config::set('app.url', 'http://inventory-pilot.internal.lan');

        [$exitCode, $output] = $this->runCommand();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('APP_URL is not HTTPS. Confirm Direct HTTPS site profile/serverBaseUrl uses the pilot HTTPS endpoint.', $output);
    }

    public function test_direct_pilot_status_https_app_url_produces_ok(): void
    {
        Config::set('app.url', 'https://inventory-pilot.internal.lan');

        [$exitCode, $output] = $this->runCommand();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('| APP_URL HTTPS status', $output);
        $this->assertStringContainsString('| OK', $output);
    }

    public function test_collector_share_runner_is_not_marked_unhealthy_for_missing_direct_poll(): void
    {
        Config::set('app.url', 'https://inventory-pilot.internal.lan');

        Runner::query()->create([
            'runner_id' => 'PC-COLLECTOR-01',
            'hostname' => 'PC-COLLECTOR-01',
            'transport_mode' => 'collector_share',
            'last_direct_poll_at' => null,
        ]);

        [$exitCode, $output] = $this->runCommand();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('collector_share runner count', $output);
        $this->assertStringContainsString('Not applicable for collector-share mode', $output);
        $this->assertStringContainsString('Collector-share mode is not modified by this report.', $output);
        $this->assertStringNotContainsString('PC-COLLECTOR-01 |', $output);
    }

    private function runCommand(): array
    {
        $this->withoutMockingConsoleOutput();

        $buffer = new BufferedOutput();
        $exitCode = Artisan::call('inventory:direct-pilot-status', [], $buffer);

        return [$exitCode, $buffer->fetch()];
    }
}
