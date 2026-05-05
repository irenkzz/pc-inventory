<?php

namespace Tests\Feature;

use App\Models\CollectorSite;
use App\Models\Runner;
use App\Models\RunnerCommand;
use App\Services\Runner\CommandQueueService;
use App\Services\Security\SiteTokenStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class DirectRunnerCommandPollApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_last_direct_poll_at_column_is_nullable_for_existing_runners(): void
    {
        $runner = $this->runner('PC-DIRECT-01', 'SITE-HQ');

        $this->assertTrue(Schema::hasColumn('runners', 'last_direct_poll_at'));
        $this->assertNull($runner->refresh()->last_direct_poll_at);
        $this->assertDatabaseHas('runners', [
            'runner_id' => 'PC-DIRECT-01',
            'last_direct_poll_at' => null,
        ]);
    }

    public function test_valid_direct_runner_receives_own_command(): void
    {
        $this->configureDirectRunnerToken();
        $runner = $this->runner('PC-DIRECT-01', 'SITE-HQ');
        $command = app(CommandQueueService::class)->queue($runner->runner_id, 'SITE-HQ', 'scan_now', 'test');

        $response = $this->postJson('/api/direct-runner/commands/poll', $this->payload(), $this->headers('direct-runner-token'))
            ->assertOk()
            ->json();

        $this->assertSame('ok', $response['status']);
        $this->assertCount(1, $response['commands']);
        $this->assertSame($command->id, $response['commands'][0]['id']);
        $this->assertSame('scan_now', $response['commands'][0]['command_type']);
        $this->assertNotNull(Runner::query()->where('runner_id', 'PC-DIRECT-01')->firstOrFail()->last_direct_poll_at);
    }

    public function test_valid_direct_runner_poll_with_zero_commands_updates_last_direct_poll_at(): void
    {
        $this->configureDirectRunnerToken();
        $this->runner('PC-DIRECT-01', 'SITE-HQ');

        $this->postJson('/api/direct-runner/commands/poll', $this->payload(), $this->headers('direct-runner-token'))
            ->assertOk()
            ->assertJson([
                'status' => 'ok',
                'commands' => [],
            ]);

        $this->assertNotNull(Runner::query()->where('runner_id', 'PC-DIRECT-01')->firstOrFail()->last_direct_poll_at);
    }

    public function test_runner_does_not_receive_another_runners_command(): void
    {
        $this->configureDirectRunnerToken();
        $this->runner('PC-DIRECT-01', 'SITE-HQ');
        $otherRunner = $this->runner('PC-DIRECT-02', 'SITE-HQ', '9c2c8c70-1111-4f24-9b10-1f5a3e3d4d21');
        app(CommandQueueService::class)->queue($otherRunner->runner_id, 'SITE-HQ', 'scan_now', 'test');

        $this->postJson('/api/direct-runner/commands/poll', $this->payload(), $this->headers('direct-runner-token'))
            ->assertOk()
            ->assertJson([
                'status' => 'ok',
                'commands' => [],
            ]);
    }

    public function test_wrong_site_is_rejected(): void
    {
        $this->configureDirectRunnerToken();
        $this->runner('PC-DIRECT-01', 'SITE-BRANCH');

        $this->postJson('/api/direct-runner/commands/poll', $this->payload(), $this->headers('direct-runner-token'))
            ->assertUnauthorized();
    }

    public function test_collector_token_is_rejected(): void
    {
        $this->runner('PC-DIRECT-01', 'SITE-HQ');

        Config::set('inventory.site_tokens_json', json_encode([
            'SITE-HQ' => [
                'token' => 'collector-token',
                'token_type' => SiteTokenStore::TYPE_COLLECTOR,
            ],
        ]));

        $this->postJson('/api/direct-runner/commands/poll', $this->payload(), $this->headers('collector-token'))
            ->assertUnauthorized();

        $this->assertNull(Runner::query()->where('runner_id', 'PC-DIRECT-01')->firstOrFail()->last_direct_poll_at);
    }

    public function test_invalid_token_does_not_update_last_direct_poll_at(): void
    {
        $this->configureDirectRunnerToken();
        $this->runner('PC-DIRECT-01', 'SITE-HQ');

        $this->postJson('/api/direct-runner/commands/poll', $this->payload(), $this->headers('wrong-token'))
            ->assertUnauthorized();

        $this->assertNull(Runner::query()->where('runner_id', 'PC-DIRECT-01')->firstOrFail()->last_direct_poll_at);
    }

    public function test_revoked_token_is_rejected(): void
    {
        $this->runner('PC-DIRECT-01', 'SITE-HQ');

        Config::set('inventory.site_tokens_json', json_encode([
            'SITE-HQ' => [
                'token' => 'revoked-direct-runner-token',
                'token_type' => SiteTokenStore::TYPE_DIRECT_RUNNER,
                'revoked_at' => '2026-05-01T00:00:00+00:00',
            ],
        ]));

        $this->postJson('/api/direct-runner/commands/poll', $this->payload(), $this->headers('revoked-direct-runner-token'))
            ->assertUnauthorized();

        $this->assertNull(Runner::query()->where('runner_id', 'PC-DIRECT-01')->firstOrFail()->last_direct_poll_at);
    }

    public function test_poll_marks_delivered_to_runner_at_only(): void
    {
        $this->configureDirectRunnerToken();
        $runner = $this->runner('PC-DIRECT-01', 'SITE-HQ');
        $command = app(CommandQueueService::class)->queue($runner->runner_id, 'SITE-HQ', 'scan_now', 'test');

        $this->postJson('/api/direct-runner/commands/poll', $this->payload(), $this->headers('direct-runner-token'))
            ->assertOk();

        $command = RunnerCommand::query()->findOrFail($command->id);
        $this->assertSame('dispatched', $command->status);
        $this->assertNotNull($command->dispatched_at);
        $this->assertNotNull($command->delivered_to_runner_at);
        $this->assertNull($command->acknowledged_at);
        $this->assertNull($command->completed_at);
        $this->assertNull($command->completion_status);
        $this->assertNull($command->completion_message);
    }

    public function test_recent_dispatched_unacked_command_is_not_redelivered_yet(): void
    {
        $this->configureDirectRunnerToken();
        $runner = $this->runner('PC-DIRECT-01', 'SITE-HQ');
        $command = app(CommandQueueService::class)->queue($runner->runner_id, 'SITE-HQ', 'scan_now', 'test');
        $command->forceFill([
            'status' => 'dispatched',
            'dispatched_at' => now()->subMinute(),
            'delivered_to_runner_at' => now()->subMinute(),
        ])->save();

        $this->postJson('/api/direct-runner/commands/poll', $this->payload(), $this->headers('direct-runner-token'))
            ->assertOk()
            ->assertJson([
                'status' => 'ok',
                'commands' => [],
            ]);
    }

    public function test_stale_dispatched_unacked_command_is_redelivered(): void
    {
        $this->configureDirectRunnerToken();
        $runner = $this->runner('PC-DIRECT-01', 'SITE-HQ');
        $command = app(CommandQueueService::class)->queue($runner->runner_id, 'SITE-HQ', 'scan_now', 'test');
        $staleDeliveredAt = now()->subMinutes(5);
        $command->forceFill([
            'status' => 'dispatched',
            'dispatched_at' => $staleDeliveredAt,
            'delivered_to_runner_at' => $staleDeliveredAt,
        ])->save();

        $response = $this->postJson('/api/direct-runner/commands/poll', $this->payload(), $this->headers('direct-runner-token'))
            ->assertOk()
            ->json();

        $this->assertCount(1, $response['commands']);
        $this->assertSame($command->id, $response['commands'][0]['id']);

        $command = RunnerCommand::query()->findOrFail($command->id);
        $this->assertSame('dispatched', $command->status);
        $this->assertNull($command->acknowledged_at);
        $this->assertNull($command->completed_at);
        $this->assertNull($command->completion_status);
        $this->assertTrue($command->delivered_to_runner_at->greaterThan($staleDeliveredAt));
    }

    public function test_completed_command_is_not_redelivered(): void
    {
        $this->configureDirectRunnerToken();
        $runner = $this->runner('PC-DIRECT-01', 'SITE-HQ');
        $command = app(CommandQueueService::class)->queue($runner->runner_id, 'SITE-HQ', 'scan_now', 'test');
        $command->forceFill([
            'status' => 'succeeded',
            'dispatched_at' => now()->subMinutes(10),
            'delivered_to_runner_at' => now()->subMinutes(10),
            'acknowledged_at' => now()->subMinutes(9),
            'completed_at' => now()->subMinutes(9),
            'completion_status' => 'succeeded',
        ])->save();

        $this->postJson('/api/direct-runner/commands/poll', $this->payload(), $this->headers('direct-runner-token'))
            ->assertOk()
            ->assertJson([
                'status' => 'ok',
                'commands' => [],
            ]);
    }

    public function test_command_is_not_completed_until_ack(): void
    {
        $this->configureDirectRunnerToken();
        $runner = $this->runner('PC-DIRECT-01', 'SITE-HQ');
        $command = app(CommandQueueService::class)->queue($runner->runner_id, 'SITE-HQ', 'scan_now', 'test');

        $this->postJson('/api/direct-runner/commands/poll', $this->payload(), $this->headers('direct-runner-token'))
            ->assertOk();

        $this->assertDatabaseHas('runner_commands', [
            'id' => $command->id,
            'status' => 'dispatched',
            'completion_status' => null,
            'completion_message' => null,
        ]);
    }

    private function configureDirectRunnerToken(): void
    {
        Config::set('inventory.direct_command_redelivery_minutes', 3);
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

    private function payload(): array
    {
        return [
            'site_code' => 'SITE-HQ',
            'runner_id' => 'PC-DIRECT-01',
            'runner_guid' => '8c2c8c70-1111-4f24-9b10-1f5a3e3d4d21',
            'hostname' => 'PC-DIRECT-01',
            'runner_version' => '1.0.21',
            'transport_mode' => 'direct_https',
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
