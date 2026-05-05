<?php

namespace Tests\Feature;

use App\Models\CollectorSite;
use App\Models\Runner;
use App\Models\RunnerCommand;
use App\Services\Runner\CommandQueueService;
use App\Services\Security\SiteTokenStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class DirectRunnerCommandAckApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_valid_ack_completes_command(): void
    {
        $this->configureDirectRunnerToken();
        $runner = $this->runner('PC-DIRECT-01', 'SITE-HQ');
        $command = app(CommandQueueService::class)->queue($runner->runner_id, 'SITE-HQ', 'scan_now', 'test');

        $this->postJson('/api/direct-runner/commands/ack', $this->payload($command->id), $this->headers('direct-runner-token'))
            ->assertOk()
            ->assertJson([
                'status' => 'ok',
                'command_id' => $command->id,
                'completion_status' => 'succeeded',
            ]);

        $this->assertDatabaseHas('runner_commands', [
            'id' => $command->id,
            'status' => 'succeeded',
            'completion_status' => 'succeeded',
            'completion_message' => 'Manual scan completed.',
            'result_upload_id' => 'result-upload-1',
        ]);

        $command = RunnerCommand::query()->findOrFail($command->id);
        $this->assertNotNull($command->acknowledged_at);
        $this->assertNotNull($command->completed_at);
    }

    public function test_failed_ack_marks_command_failed(): void
    {
        $this->configureDirectRunnerToken();
        $runner = $this->runner('PC-DIRECT-01', 'SITE-HQ');
        $command = app(CommandQueueService::class)->queue($runner->runner_id, 'SITE-HQ', 'scan_now', 'test');
        $payload = $this->payload($command->id);
        $payload['status'] = 'failed';
        $payload['message'] = 'Scan failed.';

        $this->postJson('/api/direct-runner/commands/ack', $payload, $this->headers('direct-runner-token'))
            ->assertOk();

        $this->assertDatabaseHas('runner_commands', [
            'id' => $command->id,
            'status' => 'failed',
            'completion_status' => 'failed',
            'completion_message' => 'Scan failed.',
        ]);
    }

    public function test_wrong_runner_is_rejected(): void
    {
        $this->configureDirectRunnerToken();
        $runner = $this->runner('PC-DIRECT-01', 'SITE-HQ');
        $this->runner('PC-DIRECT-02', 'SITE-HQ', '9c2c8c70-1111-4f24-9b10-1f5a3e3d4d21');
        $command = app(CommandQueueService::class)->queue($runner->runner_id, 'SITE-HQ', 'scan_now', 'test');
        $payload = $this->payload($command->id);
        $payload['runner_id'] = 'PC-DIRECT-02';
        $payload['runner_guid'] = '9c2c8c70-1111-4f24-9b10-1f5a3e3d4d21';

        $this->postJson('/api/direct-runner/commands/ack', $payload, $this->headers('direct-runner-token'))
            ->assertUnauthorized();

        $this->assertDatabaseHas('runner_commands', [
            'id' => $command->id,
            'status' => 'pending',
        ]);
    }

    public function test_wrong_site_is_rejected(): void
    {
        $this->configureDirectRunnerToken();
        $runner = $this->runner('PC-DIRECT-01', 'SITE-BRANCH');
        $command = app(CommandQueueService::class)->queue($runner->runner_id, 'SITE-BRANCH', 'scan_now', 'test');

        $this->postJson('/api/direct-runner/commands/ack', $this->payload($command->id), $this->headers('direct-runner-token'))
            ->assertUnauthorized();
    }

    public function test_collector_token_is_rejected(): void
    {
        Config::set('inventory.site_tokens_json', json_encode([
            'SITE-HQ' => [
                'token' => 'collector-token',
                'token_type' => SiteTokenStore::TYPE_COLLECTOR,
            ],
        ]));
        $runner = $this->runner('PC-DIRECT-01', 'SITE-HQ');
        $command = app(CommandQueueService::class)->queue($runner->runner_id, 'SITE-HQ', 'scan_now', 'test');

        $this->postJson('/api/direct-runner/commands/ack', $this->payload($command->id), $this->headers('collector-token'))
            ->assertUnauthorized();
    }

    public function test_revoked_token_is_rejected(): void
    {
        Config::set('inventory.site_tokens_json', json_encode([
            'SITE-HQ' => [
                'token' => 'revoked-direct-runner-token',
                'token_type' => SiteTokenStore::TYPE_DIRECT_RUNNER,
                'revoked_at' => '2026-05-01T00:00:00+00:00',
            ],
        ]));
        $runner = $this->runner('PC-DIRECT-01', 'SITE-HQ');
        $command = app(CommandQueueService::class)->queue($runner->runner_id, 'SITE-HQ', 'scan_now', 'test');

        $this->postJson('/api/direct-runner/commands/ack', $this->payload($command->id), $this->headers('revoked-direct-runner-token'))
            ->assertUnauthorized();
    }

    public function test_wrong_runner_guid_is_rejected_when_known(): void
    {
        $this->configureDirectRunnerToken();
        $runner = $this->runner('PC-DIRECT-01', 'SITE-HQ');
        $command = app(CommandQueueService::class)->queue($runner->runner_id, 'SITE-HQ', 'scan_now', 'test');
        $payload = $this->payload($command->id);
        $payload['runner_guid'] = '9c2c8c70-1111-4f24-9b10-1f5a3e3d4d21';

        $this->postJson('/api/direct-runner/commands/ack', $payload, $this->headers('direct-runner-token'))
            ->assertUnauthorized();
    }

    public function test_polling_without_ack_does_not_complete_command(): void
    {
        $this->configureDirectRunnerToken();
        $runner = $this->runner('PC-DIRECT-01', 'SITE-HQ');
        $command = app(CommandQueueService::class)->queue($runner->runner_id, 'SITE-HQ', 'scan_now', 'test');

        $this->postJson('/api/direct-runner/commands/poll', [
            'site_code' => 'SITE-HQ',
            'runner_id' => 'PC-DIRECT-01',
            'runner_guid' => '8c2c8c70-1111-4f24-9b10-1f5a3e3d4d21',
            'hostname' => 'PC-DIRECT-01',
            'runner_version' => '1.0.21',
            'transport_mode' => 'direct_https',
        ], $this->headers('direct-runner-token'))->assertOk();

        $this->assertDatabaseHas('runner_commands', [
            'id' => $command->id,
            'status' => 'dispatched',
            'completion_status' => null,
            'completion_message' => null,
            'result_upload_id' => null,
        ]);
    }

    private function configureDirectRunnerToken(): void
    {
        Config::set('inventory.site_tokens_json', json_encode([
            'SITE-HQ' => [
                'token' => 'direct-runner-token',
                'token_type' => SiteTokenStore::TYPE_DIRECT_RUNNER,
            ],
        ]));
    }

    private function runner(string $runnerId, string $siteId, string $runnerGuid = '8c2c8c70-1111-4f24-9b10-1f5a3e3d4d21'): Runner
    {
        CollectorSite::query()->firstOrCreate(['site_id' => $siteId], ['site_name' => $siteId]);

        return Runner::query()->create([
            'runner_id' => $runnerId,
            'runner_guid' => $runnerGuid,
            'hostname' => $runnerId,
            'site_id' => $siteId,
            'runner_version' => '1.0.21',
        ]);
    }

    private function payload(int $commandId): array
    {
        return [
            'site_code' => 'SITE-HQ',
            'runner_id' => 'PC-DIRECT-01',
            'runner_guid' => '8c2c8c70-1111-4f24-9b10-1f5a3e3d4d21',
            'hostname' => 'PC-DIRECT-01',
            'command_id' => $commandId,
            'status' => 'succeeded',
            'started_at' => '2026-05-01T09:00:00+09:00',
            'finished_at' => '2026-05-01T09:01:00+09:00',
            'message' => 'Manual scan completed.',
            'result_upload_id' => 'result-upload-1',
        ];
    }

    private function headers(string $token): array
    {
        return [
            'X-Site-Id' => 'SITE-HQ',
            'X-Site-Token' => $token,
        ];
    }
}
