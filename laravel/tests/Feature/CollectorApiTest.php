<?php

namespace Tests\Feature;

use App\Models\Collector;
use App\Models\DeviceScan;
use App\Models\RawFile;
use App\Models\Runner;
use App\Models\RunnerCommand;
use App\Services\Runner\CommandQueueService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class CollectorApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $tokenFile = storage_path('framework/testing/collector_api_no_tokens.json');
        File::delete($tokenFile);

        Config::set('inventory.require_site_tokens', false);
        Config::set('inventory.site_tokens_file', $tokenFile);
    }

    public function test_collector_status_and_runner_heartbeat_are_upserted(): void
    {
        Config::set('app.timezone', 'Asia/Tokyo');

        $this->postJson('/api/collector/status', [
            'collector_name' => 'hq-collector',
            'collector_version' => '1.0.0',
            'site_name' => 'Head Office',
            'queue_depth_csv' => 2,
            'queue_depth_heartbeat' => 1,
            'last_seen_at' => '2026-04-22T07:23:06+00:00',
        ], $this->headers())->assertOk()->assertJson(['status' => 'ok']);

        $this->postJson('/api/collector/heartbeat', [
            'runner_id' => 'PC-ACCOUNTING-01',
            'hostname' => 'PC-ACCOUNTING-01',
            'site_name' => 'Head Office',
            'runner_version' => '1.0.0',
            'last_inventory_status' => 'success',
            'last_seen_at' => '2026-04-22T07:23:06+00:00',
        ], $this->headers())->assertOk()->assertJson(['status' => 'ok']);

        $this->assertDatabaseHas('collectors', [
            'site_id' => 'SITE-HQ',
            'collector_name' => 'hq-collector',
            'queue_depth_csv' => 2,
            'last_seen_at' => '2026-04-22 16:23:06',
        ]);

        $this->assertDatabaseHas('runners', [
            'runner_id' => 'PC-ACCOUNTING-01',
            'site_id' => 'SITE-HQ',
            'last_inventory_status' => 'success',
            'last_seen_at' => '2026-04-22 16:23:06',
            'transport_mode' => null,
            'last_direct_heartbeat_at' => null,
            'last_upload_error' => null,
        ]);

        $this->assertSame(1, Collector::query()->count());
        $this->assertSame(1, Runner::query()->count());
    }

    public function test_collector_command_poll_marks_pending_commands_dispatched_and_ack_completes_them(): void
    {
        $this->postJson('/api/collector/heartbeat', [
            'runner_id' => 'PC-ACCOUNTING-01',
            'hostname' => 'PC-ACCOUNTING-01',
        ], $this->headers())->assertOk();

        $command = app(CommandQueueService::class)->queue('PC-ACCOUNTING-01', 'SITE-HQ', 'scan_now', 'test');

        $response = $this->getJson('/api/collector/commands', $this->headers())
            ->assertOk()
            ->json();

        $this->assertCount(1, $response);
        $this->assertSame('scan_now', $response[0]['command_type']);
        $this->assertDatabaseHas('runner_commands', [
            'id' => $command->id,
            'status' => 'dispatched',
        ]);
        $this->assertNull(Runner::query()->where('runner_id', 'PC-ACCOUNTING-01')->firstOrFail()->last_direct_poll_at);

        $this->postJson('/api/collector/command-ack', [
            'command_id' => $command->id,
            'status' => 'completed',
            'message' => 'Manual scan executed.',
            'runner_state' => [
                'runner_id' => 'PC-ACCOUNTING-01',
                'site_id' => 'SITE-HQ',
                'last_command_type' => 'scan_now',
            ],
        ], $this->headers())->assertOk()->assertJson(['status' => 'ok']);

        $this->assertDatabaseHas('runner_commands', [
            'id' => $command->id,
            'status' => 'completed',
            'completion_message' => 'Manual scan executed.',
        ]);

        $this->assertSame('completed', RunnerCommand::query()->find($command->id)->status);
    }

    public function test_collector_command_poll_does_not_redeliver_dispatched_commands(): void
    {
        $this->postJson('/api/collector/heartbeat', [
            'runner_id' => 'PC-ACCOUNTING-01',
            'hostname' => 'PC-ACCOUNTING-01',
        ], $this->headers())->assertOk();

        $command = app(CommandQueueService::class)->queue('PC-ACCOUNTING-01', 'SITE-HQ', 'scan_now', 'test');
        $command->forceFill([
            'status' => 'dispatched',
            'dispatched_at' => now()->subMinutes(10),
            'delivered_to_runner_at' => now()->subMinutes(10),
        ])->save();

        $this->getJson('/api/collector/commands', $this->headers())
            ->assertOk()
            ->assertJsonCount(0);

        $this->assertDatabaseHas('runner_commands', [
            'id' => $command->id,
            'status' => 'dispatched',
        ]);
    }

    public function test_runner_guid_reconciles_hostname_change_without_changing_device_identity_rules(): void
    {
        $runnerGuid = '8c2c8c70-1111-4f24-9b10-1f5a3e3d4d21';

        $this->postJson('/api/collector/heartbeat', [
            'runner_id' => 'DESKTOP-I5BLORA',
            'runner_guid' => $runnerGuid,
            'hostname' => 'DESKTOP-I5BLORA',
            'site_name' => 'Head Office',
            'runner_version' => '1.0.20',
            'last_seen_at' => '2026-04-22T07:23:06+00:00',
        ], $this->headers())->assertOk();

        RunnerCommand::query()->create([
            'runner_id' => 'DESKTOP-I5BLORA',
            'site_id' => 'SITE-HQ',
            'command_type' => 'scan_now',
            'status' => 'completed',
            'requested_by' => 'test',
        ]);

        $this->postJson('/api/collector/heartbeat', [
            'runner_id' => 'MCR-CHARGEN',
            'runner_guid' => $runnerGuid,
            'hostname' => 'MCR-CHARGEN',
            'site_name' => 'Head Office',
            'runner_version' => '1.0.21',
            'last_seen_at' => '2026-04-22T08:23:06+00:00',
        ], $this->headers())->assertOk();

        $this->assertSame(1, Runner::query()->count());
        $this->assertDatabaseHas('runners', [
            'runner_id' => 'MCR-CHARGEN',
            'runner_guid' => $runnerGuid,
            'hostname' => 'MCR-CHARGEN',
            'runner_version' => '1.0.21',
        ]);
        $this->assertDatabaseMissing('runners', [
            'runner_id' => 'DESKTOP-I5BLORA',
        ]);
        $this->assertDatabaseHas('runner_commands', [
            'runner_id' => 'MCR-CHARGEN',
            'command_type' => 'scan_now',
        ]);

        $aliases = Runner::query()->where('runner_id', 'MCR-CHARGEN')->firstOrFail()->raw_state_json['previous_runner_ids'] ?? [];
        $this->assertContains('DESKTOP-I5BLORA', $aliases);
    }

    public function test_read_apis_return_devices_and_runners(): void
    {
        Runner::query()->create([
            'runner_id' => 'PC-ACCOUNTING-01',
            'hostname' => 'PC-ACCOUNTING-01',
            'site_id' => null,
        ]);

        $this->getJson('/api/runners')->assertOk()->assertJsonFragment([
            'runner_id' => 'PC-ACCOUNTING-01',
        ]);
    }

    public function test_collector_csv_intake_continues_without_upload_id(): void
    {
        $csv = (string) $this->fixtureContents('sample_data/sample_scan_1.csv');
        $file = UploadedFile::fake()->createWithContent('scan.csv', $csv);

        $this->post('/api/collector/intake/csv', [
            'file' => $file,
            'runner_id' => 'PC-COLLECTOR-01',
            'hostname' => 'PC-COLLECTOR-01',
            'runner_version' => '1.0.21',
        ], $this->headers())->assertOk();

        $this->assertSame(1, DeviceScan::query()->count());
        $this->assertSame(1, RawFile::query()->count());
        $this->assertNull(RawFile::query()->firstOrFail()->upload_id);
        $this->assertDatabaseHas('runners', [
            'runner_id' => 'PC-COLLECTOR-01',
            'site_id' => 'SITE-HQ',
            'transport_mode' => null,
            'last_direct_upload_at' => null,
        ]);
    }

    private function headers(): array
    {
        return [
            'X-Site-Id' => 'SITE-HQ',
            'X-Site-Token' => 'unused-when-no-token-map',
        ];
    }
}
