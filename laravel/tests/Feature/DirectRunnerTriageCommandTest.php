<?php

namespace Tests\Feature;

use App\Models\CollectorSite;
use App\Models\Runner;
use App\Models\RunnerCommand;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

class DirectRunnerTriageCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_healthy_direct_https_runner_returns_ok(): void
    {
        $this->travelTo('2026-05-06 12:00:00');
        CollectorSite::query()->create(['site_id' => 'SITE-HQ', 'site_name' => 'Bhayangkara']);
        $this->directRunner([
            'last_direct_heartbeat_at' => now()->subMinutes(5),
            'last_direct_poll_at' => now()->subMinutes(4),
            'last_direct_upload_at' => now()->subHours(2),
        ]);

        [$exitCode, $output] = $this->runCommand('IT-ADMIN');

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Direct HTTPS Runner Triage', $output);
        $this->assertStringContainsString('Resolved runner ID: IT-ADMIN', $output);
        $this->assertStringContainsString('Site: SITE-HQ / Bhayangkara', $output);
        $this->assertStringContainsString('Runner version: 1.0.22', $output);
        $this->assertStringContainsString('Runner GUID: present ending 4D21', $output);
        $this->assertStringContainsString('[STATUS] OK', $output);
        $this->assertStringContainsString('Result: OK', $output);
        $this->assertStringNotContainsString('8c2c8c70-1111-4f24-9b10-1f5a3e3d4d21', $output);
    }

    public function test_runner_not_found_returns_fail_without_exception_trace(): void
    {
        [$exitCode, $output] = $this->runCommand('MISSING-RUNNER');

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('[FAIL] Runner not found: MISSING-RUNNER', $output);
        $this->assertStringContainsString('Result: FAIL', $output);
        $this->assertStringNotContainsString('Exception', $output);
        $this->assertStringNotContainsString('Stack trace', $output);
    }

    public function test_collector_share_runner_returns_skipped_without_direct_warnings(): void
    {
        Runner::query()->create([
            'runner_id' => 'PC-COLLECTOR-01',
            'hostname' => 'PC-COLLECTOR-01',
            'transport_mode' => 'collector_share',
            'last_direct_poll_at' => null,
        ]);

        [$exitCode, $output] = $this->runCommand('PC-COLLECTOR-01');

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Result: SKIPPED', $output);
        $this->assertStringContainsString('This command is Direct HTTPS-only.', $output);
        $this->assertStringContainsString('Not applicable for collector-share mode.', $output);
        $this->assertStringNotContainsString('No direct poll yet', $output);
        $this->assertStringNotContainsString('stale/offline', $output);
    }

    public function test_missing_heartbeat_diagnosis_includes_scheduled_task_hint(): void
    {
        $this->directRunner([
            'last_direct_heartbeat_at' => null,
            'last_seen_at' => null,
            'last_direct_poll_at' => now(),
            'last_direct_upload_at' => now(),
        ]);

        [$exitCode, $output] = $this->runCommand('IT-ADMIN');

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('No heartbeat yet', $output);
        $this->assertStringContainsString('[STATUS] stale/offline', $output);
        $this->assertStringContainsString('Get-ScheduledTaskInfo -TaskName "InternalInventoryRunner"', $output);
        $this->assertStringContainsString('Get-ScheduledTaskInfo -TaskName "InternalInventoryRunner-Startup"', $output);
    }

    public function test_missing_direct_poll_diagnosis_includes_https_health_hint(): void
    {
        $this->directRunner([
            'last_direct_heartbeat_at' => now(),
            'last_direct_poll_at' => null,
            'last_direct_upload_at' => now(),
        ]);

        [$exitCode, $output] = $this->runCommand('IT-ADMIN');

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('No direct poll yet', $output);
        $this->assertStringContainsString('[STATUS] no command poll yet', $output);
        $this->assertStringContainsString('Resolve-DnsName inventory-pilot.internal.lan', $output);
        $this->assertStringContainsString('Test-NetConnection inventory-pilot.internal.lan -Port 443', $output);
        $this->assertStringContainsString('Invoke-WebRequest https://inventory-pilot.internal.lan/health -UseBasicParsing', $output);
    }

    public function test_missing_upload_diagnosis_includes_outbox_devices_hint(): void
    {
        $this->directRunner([
            'last_direct_heartbeat_at' => now(),
            'last_direct_poll_at' => now(),
            'last_direct_upload_at' => null,
            'last_successful_inventory_at' => null,
        ]);

        [$exitCode, $output] = $this->runCommand('IT-ADMIN');

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('No upload yet', $output);
        $this->assertStringContainsString('[STATUS] upload missing', $output);
        $this->assertStringContainsString('Check runner logs, outbox pending/failed counts, and Devices page.', $output);
    }

    public function test_queued_pending_command_count_is_shown_and_not_treated_as_delivered(): void
    {
        $runner = $this->directRunner([
            'last_direct_heartbeat_at' => now(),
            'last_direct_poll_at' => now(),
            'last_direct_upload_at' => now(),
        ]);
        RunnerCommand::query()->create([
            'runner_id' => $runner->runner_id,
            'command_type' => 'scan_now',
            'status' => 'pending',
            'requested_at' => now(),
        ]);

        [$exitCode, $output] = $this->runCommand('IT-ADMIN');

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Pending queued command count: 1', $output);
        $this->assertStringContainsString('Delivered awaiting ACK count: 0', $output);
        $this->assertStringContainsString('[STATUS] OK', $output);
    }

    public function test_delivered_awaiting_ack_diagnosis(): void
    {
        $runner = $this->directRunner([
            'last_direct_heartbeat_at' => now(),
            'last_direct_poll_at' => now(),
            'last_direct_upload_at' => now(),
        ]);
        RunnerCommand::query()->create([
            'runner_id' => $runner->runner_id,
            'command_type' => 'scan_now',
            'status' => 'dispatched',
            'requested_at' => now()->subMinutes(5),
            'dispatched_at' => now()->subMinutes(5),
            'delivered_to_runner_at' => now()->subMinutes(5),
        ]);

        [$exitCode, $output] = $this->runCommand('IT-ADMIN');

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Delivered awaiting ACK count: 1', $output);
        $this->assertStringContainsString('[STATUS] command awaiting ACK', $output);
        $this->assertStringContainsString('Polling means delivery, ACK is execution result.', $output);
        $this->assertStringContainsString('Check runner execution logs and state\\direct-acks.', $output);
    }

    public function test_stale_dispatched_no_ack_diagnosis(): void
    {
        $runner = $this->directRunner([
            'last_direct_heartbeat_at' => now(),
            'last_direct_poll_at' => now(),
            'last_direct_upload_at' => now(),
        ]);
        RunnerCommand::query()->create([
            'runner_id' => $runner->runner_id,
            'command_type' => 'scan_now',
            'status' => 'dispatched',
            'requested_at' => now()->subMinutes(45),
            'dispatched_at' => now()->subMinutes(45),
            'delivered_to_runner_at' => now()->subMinutes(45),
        ]);

        [$exitCode, $output] = $this->runCommand('IT-ADMIN');

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Stale dispatched/no ACK count: 1', $output);
        $this->assertStringContainsString('[STATUS] stale dispatched/no ACK', $output);
    }

    public function test_failed_command_present_diagnosis(): void
    {
        $runner = $this->directRunner([
            'last_direct_heartbeat_at' => now(),
            'last_direct_poll_at' => now(),
            'last_direct_upload_at' => now(),
        ]);
        RunnerCommand::query()->create([
            'runner_id' => $runner->runner_id,
            'command_type' => 'scan_now',
            'status' => 'failed',
            'requested_at' => now()->subMinutes(5),
            'acknowledged_at' => now()->subMinutes(4),
            'completed_at' => now()->subMinutes(4),
            'completion_status' => 'failed',
            'result_upload_id' => 'upload-result-1',
        ]);

        [$exitCode, $output] = $this->runCommand('IT-ADMIN');

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Failed command count: 1', $output);
        $this->assertStringContainsString('[STATUS] failed command present', $output);
        $this->assertStringContainsString('result_upload_id present', $output);
        $this->assertStringContainsString('Review latest command status and runner logs without payloads or secrets.', $output);
    }

    public function test_output_does_not_contain_sensitive_values_or_payloads(): void
    {
        $runner = $this->directRunner([
            'last_direct_heartbeat_at' => now(),
            'last_direct_poll_at' => now(),
            'last_direct_upload_at' => now(),
        ]);
        RunnerCommand::query()->create([
            'runner_id' => $runner->runner_id,
            'command_type' => 'scan_now',
            'status' => 'pending',
            'requested_at' => now(),
            'payload_json' => [
                'Authorization' => 'Bearer fake-bearer-token-not-output',
                'token_hash' => 'fake-token-hash-not-output',
                'raw_csv' => 'Hostname,Serial,Asset',
                'secret' => 'fake-token-secret-not-output',
            ],
        ]);

        [$exitCode, $output] = $this->runCommand('IT-ADMIN');

        $this->assertSame(0, $exitCode);
        $this->assertStringNotContainsString('fake-bearer-token-not-output', $output);
        $this->assertStringNotContainsString('fake-token-hash-not-output', $output);
        $this->assertStringNotContainsString('Authorization: Bearer fake-bearer-token-not-output', $output);
        $this->assertStringNotContainsString('Bearer', $output);
        $this->assertStringNotContainsString('token_hash', $output);
        $this->assertStringNotContainsString('Hostname,Serial,Asset', $output);
        $this->assertStringNotContainsString('fake-token-secret-not-output', $output);
        $this->assertStringNotContainsString('8c2c8c70-1111-4f24-9b10-1f5a3e3d4d21', $output);
    }

    public function test_command_is_read_only(): void
    {
        $runner = $this->directRunner([
            'last_direct_heartbeat_at' => now(),
            'last_direct_poll_at' => now(),
            'last_direct_upload_at' => now(),
        ]);
        $command = RunnerCommand::query()->create([
            'runner_id' => $runner->runner_id,
            'command_type' => 'scan_now',
            'status' => 'pending',
            'requested_at' => now(),
            'payload_json' => ['note' => 'do not mutate'],
        ]);

        $runnerBefore = $runner->fresh()->getAttributes();
        $commandBefore = $command->fresh()->getAttributes();

        [$exitCode] = $this->runCommand('IT-ADMIN');

        $this->assertSame(0, $exitCode);
        $this->assertSame($runnerBefore, $runner->fresh()->getAttributes());
        $this->assertSame($commandBefore, $command->fresh()->getAttributes());
    }

    private function directRunner(array $attributes = []): Runner
    {
        CollectorSite::query()->firstOrCreate(
            ['site_id' => 'SITE-HQ'],
            ['site_name' => 'Bhayangkara'],
        );

        return Runner::query()->create([
            'runner_id' => 'IT-ADMIN',
            'hostname' => 'IT-ADMIN',
            'site_id' => 'SITE-HQ',
            'runner_guid' => '8c2c8c70-1111-4f24-9b10-1f5a3e3d4d21',
            'runner_version' => '1.0.22',
            'transport_mode' => 'direct_https',
            ...$attributes,
        ]);
    }

    private function runCommand(string $runnerId): array
    {
        $this->withoutMockingConsoleOutput();

        $buffer = new BufferedOutput();
        $exitCode = Artisan::call('inventory:direct-runner-triage', ['runnerId' => $runnerId], $buffer);

        return [$exitCode, $buffer->fetch()];
    }
}
