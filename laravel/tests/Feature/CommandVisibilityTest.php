<?php

namespace Tests\Feature;

use App\Models\CollectorSite;
use App\Models\Runner;
use App\Models\RunnerCommand;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class CommandVisibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_history_shows_queue_summary_and_runner_links(): void
    {
        $this->seedCommandSet();

        $this->actingAs($this->adminUser())
            ->get('/commands')
            ->assertOk()
            ->assertSee('Commands')
            ->assertSee('Pending dispatch')
            ->assertSee('IT-ADMIN')
            ->assertSee('Bhayangkara')
            ->assertSee('Manual scan')
            ->assertSee('Repair/update')
            ->assertSee('Command History');
    }

    public function test_attention_filter_shows_failed_and_stale_dispatched_commands(): void
    {
        $this->seedCommandSet();

        $this->actingAs($this->adminUser())
            ->get('/commands?status=attention')
            ->assertOk()
            ->assertSee('MCR-EDIT')
            ->assertSee('PGM-OLD')
            ->assertSee('Dispatched more than 24h ago')
            ->assertDontSee('BRT-DONE');
    }

    public function test_type_and_site_filters_limit_command_results(): void
    {
        $this->seedCommandSet();

        $this->actingAs($this->adminUser())
            ->get('/commands?site_id=SITE-HQ&type=repair_update')
            ->assertOk()
            ->assertSee('MCR-EDIT')
            ->assertDontSee('IT-ADMIN')
            ->assertDontSee('BRANCH-RUNNER');
    }

    public function test_direct_command_queue_renders_async_display_labels_without_changing_statuses(): void
    {
        $this->travelTo('2026-05-04 10:00:00');
        Config::set('inventory.site_tokens_json', json_encode([
            'SITE-HQ' => [
                'token' => 'direct-command-secret-not-rendered',
                'token_type' => 'direct_runner',
            ],
        ]));
        CollectorSite::query()->create(['site_id' => 'SITE-HQ', 'site_name' => 'Bhayangkara']);

        foreach ([
            'PC-DIRECT-QUEUED',
            'PC-DIRECT-DELIVERED',
            'PC-DIRECT-STALE',
            'PC-DIRECT-SUCCEEDED',
            'PC-DIRECT-FAILED',
            'PC-DIRECT-BLOCKED',
        ] as $runnerId) {
            Runner::query()->create([
                'runner_id' => $runnerId,
                'hostname' => $runnerId,
                'site_id' => 'SITE-HQ',
                'runner_version' => '1.0.21',
                'transport_mode' => 'direct_https',
                'last_seen_at' => now(),
            ]);
        }

        RunnerCommand::query()->create([
            'runner_id' => 'PC-DIRECT-QUEUED',
            'site_id' => 'SITE-HQ',
            'command_type' => 'scan_now',
            'status' => 'pending',
            'requested_by' => 'portal',
            'requested_at' => now(),
        ]);
        RunnerCommand::query()->create([
            'runner_id' => 'PC-DIRECT-DELIVERED',
            'site_id' => 'SITE-HQ',
            'command_type' => 'scan_now',
            'status' => 'dispatched',
            'requested_by' => 'portal',
            'requested_at' => now()->subMinutes(5),
            'dispatched_at' => now()->subMinutes(4),
            'delivered_to_runner_at' => now()->subMinutes(4),
        ]);
        RunnerCommand::query()->create([
            'runner_id' => 'PC-DIRECT-STALE',
            'site_id' => 'SITE-HQ',
            'command_type' => 'scan_now',
            'status' => 'dispatched',
            'requested_by' => 'portal',
            'requested_at' => now()->subHours(25),
            'dispatched_at' => now()->subHours(25),
            'delivered_to_runner_at' => now()->subHours(25),
        ]);
        RunnerCommand::query()->create([
            'runner_id' => 'PC-DIRECT-SUCCEEDED',
            'site_id' => 'SITE-HQ',
            'command_type' => 'scan_now',
            'status' => 'succeeded',
            'requested_by' => 'portal',
            'requested_at' => now()->subMinutes(20),
            'dispatched_at' => now()->subMinutes(19),
            'delivered_to_runner_at' => now()->subMinutes(19),
            'acknowledged_at' => now()->subMinutes(18),
            'completed_at' => now()->subMinutes(18),
            'completion_status' => 'succeeded',
        ]);
        RunnerCommand::query()->create([
            'runner_id' => 'PC-DIRECT-FAILED',
            'site_id' => 'SITE-HQ',
            'command_type' => 'scan_now',
            'status' => 'failed',
            'requested_by' => 'portal',
            'requested_at' => now()->subMinutes(30),
            'dispatched_at' => now()->subMinutes(29),
            'delivered_to_runner_at' => now()->subMinutes(29),
            'acknowledged_at' => now()->subMinutes(28),
            'completed_at' => now()->subMinutes(28),
            'completion_status' => 'failed',
        ]);
        RunnerCommand::query()->create([
            'runner_id' => 'PC-DIRECT-BLOCKED',
            'site_id' => 'SITE-HQ',
            'command_type' => 'repair_update',
            'status' => 'failed',
            'requested_by' => 'portal',
            'requested_at' => now()->subMinutes(40),
            'completed_at' => now()->subMinutes(40),
            'completion_status' => 'failed',
            'completion_message' => 'Blocked by Direct HTTPS MVP boundary.',
        ]);

        $this->actingAs($this->adminUser())
            ->get('/commands')
            ->assertOk()
            ->assertSee('Direct HTTPS')
            ->assertSee('Queued')
            ->assertSee('Delivered to runner')
            ->assertSee('Awaiting ACK')
            ->assertSee('Stale — delivered but no ACK received')
            ->assertSee('Succeeded')
            ->assertSee('Failed')
            ->assertSee('Blocked — not supported for Direct HTTPS MVP')
            ->assertDontSee('direct-command-secret-not-rendered');

        $this->assertDatabaseHas('runner_commands', [
            'runner_id' => 'PC-DIRECT-QUEUED',
            'status' => 'pending',
        ]);
        $this->assertDatabaseHas('runner_commands', [
            'runner_id' => 'PC-DIRECT-DELIVERED',
            'status' => 'dispatched',
            'acknowledged_at' => null,
        ]);
        $this->assertDatabaseHas('runner_commands', [
            'runner_id' => 'PC-DIRECT-BLOCKED',
            'command_type' => 'repair_update',
            'status' => 'failed',
        ]);
    }

    public function test_collector_share_command_queue_display_remains_normal(): void
    {
        $this->seedCommandSet();

        $this->actingAs($this->adminUser())
            ->get('/commands?q=IT-ADMIN')
            ->assertOk()
            ->assertSee('Collector Share')
            ->assertSee('pending')
            ->assertDontSee('Queued')
            ->assertDontSee('Direct HTTPS');
    }

    private function seedCommandSet(): void
    {
        $this->travelTo('2026-04-23 10:00:00');

        CollectorSite::query()->create([
            'site_id' => 'SITE-HQ',
            'site_name' => 'Bhayangkara',
        ]);
        CollectorSite::query()->create([
            'site_id' => 'SITE-BRANCH',
            'site_name' => 'SITE-BRANCH',
        ]);

        foreach ([
            ['runner_id' => 'IT-ADMIN', 'site_id' => 'SITE-HQ'],
            ['runner_id' => 'MCR-EDIT', 'site_id' => 'SITE-HQ'],
            ['runner_id' => 'PGM-OLD', 'site_id' => 'SITE-HQ'],
            ['runner_id' => 'BRT-DONE', 'site_id' => 'SITE-HQ'],
            ['runner_id' => 'BRANCH-RUNNER', 'site_id' => 'SITE-BRANCH'],
        ] as $runner) {
            Runner::query()->create([
                'runner_id' => $runner['runner_id'],
                'hostname' => $runner['runner_id'],
                'site_id' => $runner['site_id'],
                'runner_version' => '1.0.0',
                'last_seen_at' => now(),
            ]);
        }

        RunnerCommand::query()->create([
            'runner_id' => 'IT-ADMIN',
            'site_id' => 'SITE-HQ',
            'command_type' => 'scan_now',
            'status' => 'pending',
            'requested_by' => 'portal',
            'requested_at' => now(),
        ]);

        RunnerCommand::query()->create([
            'runner_id' => 'MCR-EDIT',
            'site_id' => 'SITE-HQ',
            'command_type' => 'repair_update',
            'status' => 'failed',
            'requested_by' => 'portal',
            'requested_at' => now()->subHour(),
            'completed_at' => now(),
            'completion_status' => 'failed',
            'completion_message' => 'Runner rejected command.',
        ]);

        RunnerCommand::query()->create([
            'runner_id' => 'PGM-OLD',
            'site_id' => 'SITE-HQ',
            'command_type' => 'scan_now',
            'status' => 'dispatched',
            'requested_by' => 'portal',
            'requested_at' => now()->subDays(2),
            'dispatched_at' => now()->subDays(2),
        ]);

        RunnerCommand::query()->create([
            'runner_id' => 'BRT-DONE',
            'site_id' => 'SITE-HQ',
            'command_type' => 'scan_now',
            'status' => 'completed',
            'requested_by' => 'portal',
            'requested_at' => now()->subHours(3),
            'completed_at' => now()->subHours(2),
            'completion_status' => 'success',
        ]);

        RunnerCommand::query()->create([
            'runner_id' => 'BRANCH-RUNNER',
            'site_id' => 'SITE-BRANCH',
            'command_type' => 'repair_update',
            'status' => 'pending',
            'requested_by' => 'portal',
            'requested_at' => now(),
        ]);
    }

    private function adminUser(): User
    {
        return User::query()->create([
            'name' => 'Inventory Admin',
            'email' => 'admin@tvri.local',
            'password' => Hash::make('password'),
        ]);
    }
}
