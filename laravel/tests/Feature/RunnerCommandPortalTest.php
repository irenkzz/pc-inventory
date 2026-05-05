<?php

namespace Tests\Feature;

use App\Models\Runner;
use App\Models\RunnerCommand;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class RunnerCommandPortalTest extends TestCase
{
    use RefreshDatabase;

    public function test_portal_does_not_queue_duplicate_active_manual_scan_command(): void
    {
        $user = $this->adminUser();
        $runner = $this->runner();

        $this->actingAs($user)
            ->post(route('admin.runners.manual-scan', $runner))
            ->assertRedirect(route('admin.runners.show', $runner))
            ->assertSessionHas('status');

        $this->actingAs($user)
            ->post(route('admin.runners.manual-scan', $runner))
            ->assertRedirect(route('admin.runners.show', $runner))
            ->assertSessionHas('status', 'Manual scan is already queued for this runner.');

        $this->assertSame(1, RunnerCommand::query()->count());
        $this->assertDatabaseHas('runner_commands', [
            'runner_id' => 'IT-ADMIN',
            'command_type' => 'scan_now',
            'status' => 'pending',
        ]);
    }

    public function test_portal_supersedes_existing_command_when_new_type_is_queued(): void
    {
        $user = $this->adminUser();
        $runner = $this->runner();

        $this->actingAs($user)->post(route('admin.runners.manual-scan', $runner));

        $this->actingAs($user)
            ->post(route('admin.runners.repair', $runner))
            ->assertRedirect(route('admin.runners.show', $runner))
            ->assertSessionHas('status', fn (string $message): bool => str_contains($message, 'Previous active command was superseded.'));

        $this->assertSame(2, RunnerCommand::query()->count());
        $this->assertDatabaseHas('runner_commands', [
            'runner_id' => 'IT-ADMIN',
            'command_type' => 'scan_now',
            'status' => 'superseded',
        ]);
        $this->assertDatabaseHas('runner_commands', [
            'runner_id' => 'IT-ADMIN',
            'command_type' => 'repair_update',
            'status' => 'pending',
        ]);
    }

    public function test_repair_update_is_completed_immediately_when_runner_already_matches_target_version(): void
    {
        config(['inventory.runner_target_version' => '1.0.20']);

        $user = $this->adminUser();
        $runner = Runner::query()->create([
            'runner_id' => 'IT-ADMIN-NEW',
            'hostname' => 'IT-ADMIN-NEW',
            'runner_version' => '1.0.20',
            'last_seen_at' => '2026-04-30 17:50:00',
            'last_inventory_status' => 'success',
            'last_upload_status' => 'uploaded',
        ]);

        $this->actingAs($user)
            ->post(route('admin.runners.repair', $runner))
            ->assertRedirect(route('admin.runners.show', $runner))
            ->assertSessionHas('status', 'Repair/update skipped for IT-ADMIN-NEW; runner already reports 1.0.20.');

        $this->assertDatabaseHas('runner_commands', [
            'runner_id' => 'IT-ADMIN-NEW',
            'command_type' => 'repair_update',
            'status' => 'completed',
            'completion_status' => 'completed',
            'completion_message' => 'Runner already reports target version 1.0.20; no repair command dispatched.',
        ]);
        $this->assertSame(0, RunnerCommand::query()->whereIn('status', ['pending', 'dispatched'])->count());
    }

    public function test_repair_update_queues_when_runner_is_below_target_version(): void
    {
        config(['inventory.runner_target_version' => '1.0.20']);

        $user = $this->adminUser();
        $runner = $this->runner();

        $this->actingAs($user)
            ->post(route('admin.runners.repair', $runner))
            ->assertRedirect(route('admin.runners.show', $runner));

        $this->assertDatabaseHas('runner_commands', [
            'runner_id' => 'IT-ADMIN',
            'command_type' => 'repair_update',
            'status' => 'pending',
        ]);
    }

    public function test_repair_update_is_rejected_for_direct_runner(): void
    {
        config(['inventory.runner_target_version' => '1.0.20']);

        $user = $this->adminUser();
        $runner = $this->runner([
            'runner_id' => 'PC-DIRECT-01',
            'hostname' => 'PC-DIRECT-01',
            'transport_mode' => 'direct_https',
        ]);

        $this->actingAs($user)
            ->post(route('admin.runners.repair', $runner))
            ->assertRedirect(route('admin.runners.show', $runner))
            ->assertSessionHas('status', 'Repair/update is not supported for direct HTTPS runners yet.');

        $this->assertSame(0, RunnerCommand::query()->count());
    }

    public function test_repair_update_is_allowed_for_collector_runner(): void
    {
        config(['inventory.runner_target_version' => '1.0.20']);

        $user = $this->adminUser();
        $runner = $this->runner([
            'runner_id' => 'PC-COLLECTOR-01',
            'hostname' => 'PC-COLLECTOR-01',
            'transport_mode' => 'collector_share',
        ]);

        $this->actingAs($user)
            ->post(route('admin.runners.repair', $runner))
            ->assertRedirect(route('admin.runners.show', $runner));

        $this->assertDatabaseHas('runner_commands', [
            'runner_id' => 'PC-COLLECTOR-01',
            'command_type' => 'repair_update',
            'status' => 'pending',
        ]);
    }

    public function test_manual_scan_still_works_for_direct_and_collector_runners(): void
    {
        $user = $this->adminUser();
        $directRunner = $this->runner([
            'runner_id' => 'PC-DIRECT-01',
            'hostname' => 'PC-DIRECT-01',
            'transport_mode' => 'direct_https',
        ]);
        $collectorRunner = $this->runner([
            'runner_id' => 'PC-COLLECTOR-01',
            'hostname' => 'PC-COLLECTOR-01',
            'transport_mode' => 'collector_share',
        ]);

        $this->actingAs($user)
            ->post(route('admin.runners.manual-scan', $directRunner))
            ->assertRedirect(route('admin.runners.show', $directRunner));

        $this->actingAs($user)
            ->post(route('admin.runners.manual-scan', $collectorRunner))
            ->assertRedirect(route('admin.runners.show', $collectorRunner));

        $this->assertDatabaseHas('runner_commands', [
            'runner_id' => 'PC-DIRECT-01',
            'command_type' => 'scan_now',
            'status' => 'pending',
        ]);
        $this->assertDatabaseHas('runner_commands', [
            'runner_id' => 'PC-COLLECTOR-01',
            'command_type' => 'scan_now',
            'status' => 'pending',
        ]);
    }

    public function test_runner_detail_shows_active_command_state(): void
    {
        $user = $this->adminUser();
        $runner = $this->runner();

        $this->actingAs($user)->post(route('admin.runners.manual-scan', $runner));

        $this->actingAs($user)
            ->get(route('admin.runners.show', $runner))
            ->assertOk()
            ->assertSee('Active command')
            ->assertSee('Manual scan')
            ->assertSee('Command Actions')
            ->assertSee('Command History');
    }

    public function test_direct_runner_detail_shows_direct_https_status_with_masked_guid(): void
    {
        Config::set('inventory.site_tokens_json', json_encode([
            'SITE-HQ' => [
                'token' => 'direct-token-secret-not-rendered',
                'token_type' => 'direct_runner',
            ],
        ]));

        $runner = $this->runner([
            'runner_id' => 'PC-DIRECT-01',
            'hostname' => 'PC-DIRECT-01',
            'site_id' => null,
            'runner_guid' => '8c2c8c70-1111-4f24-9b10-1f5a3e3d4d21',
            'runner_version' => '1.0.21',
            'transport_mode' => 'direct_https',
            'last_direct_heartbeat_at' => '2026-05-04 09:00:00',
            'last_direct_poll_at' => '2026-05-04 09:05:00',
        ]);

        RunnerCommand::query()->create([
            'runner_id' => 'PC-DIRECT-01',
            'command_type' => 'scan_now',
            'status' => 'succeeded',
            'requested_by' => 'portal',
            'requested_at' => '2026-05-04 09:02:00',
            'dispatched_at' => '2026-05-04 09:05:00',
            'acknowledged_at' => '2026-05-04 09:06:00',
            'completed_at' => '2026-05-04 09:06:00',
            'completion_status' => 'succeeded',
        ]);

        $this->actingAs($this->adminUser())
            ->get(route('admin.runners.show', $runner))
            ->assertOk()
            ->assertSee('Direct HTTPS Status')
            ->assertSee('Direct HTTPS')
            ->assertSee('Present · ending 4d21')
            ->assertDontSee('8c2c8c70-1111-4f24-9b10-1f5a3e3d4d21')
            ->assertSee('scan_now/manual_scan supported')
            ->assertSee('repair_update blocked for Direct HTTPS MVP')
            ->assertDontSee('direct-token-secret-not-rendered');
    }

    public function test_direct_runner_detail_uses_direct_upload_as_last_inventory(): void
    {
        $runner = $this->runner([
            'runner_id' => 'LAPTOP-I76TA97E',
            'hostname' => 'LAPTOP-I76TA97E',
            'transport_mode' => 'direct_https',
            'last_successful_inventory_at' => null,
            'last_inventory_status' => null,
            'last_upload_status' => null,
            'last_direct_upload_at' => '2026-05-04 09:10:00',
            'last_upload_error' => null,
        ]);

        $this->actingAs($this->adminUser())
            ->get(route('admin.runners.show', $runner))
            ->assertOk()
            ->assertSee('Runner Health')
            ->assertSee(\App\Support\InventoryTime::format('2026-05-04 09:10:00'))
            ->assertSee('Uploaded')
            ->assertDontSee('No direct upload yet');
    }

    public function test_direct_runner_detail_without_direct_upload_uses_calm_health_wording(): void
    {
        $runner = $this->runner([
            'runner_id' => 'PC-DIRECT-NO-UPLOAD',
            'hostname' => 'PC-DIRECT-NO-UPLOAD',
            'transport_mode' => 'direct_https',
            'last_successful_inventory_at' => null,
            'last_inventory_status' => null,
            'last_upload_status' => null,
            'last_direct_upload_at' => null,
        ]);

        $this->actingAs($this->adminUser())
            ->get(route('admin.runners.show', $runner))
            ->assertOk()
            ->assertSee('Runner Health')
            ->assertSee('No direct upload yet');
    }

    public function test_collector_share_runner_detail_health_display_remains_unchanged(): void
    {
        $runner = $this->runner([
            'runner_id' => 'PC-COLLECTOR-HEALTH',
            'hostname' => 'PC-COLLECTOR-HEALTH',
            'transport_mode' => 'collector_share',
            'last_successful_inventory_at' => '2026-05-04 08:00:00',
            'last_inventory_status' => 'success',
            'last_upload_status' => 'uploaded',
            'last_direct_upload_at' => null,
        ]);

        $this->actingAs($this->adminUser())
            ->get(route('admin.runners.show', $runner))
            ->assertOk()
            ->assertSee(\App\Support\InventoryTime::format('2026-05-04 08:00:00'))
            ->assertSee('success')
            ->assertSee('uploaded')
            ->assertDontSee('No direct upload yet');
    }

    public function test_direct_runner_detail_does_not_render_active_repair_update_queue_action(): void
    {
        $runner = $this->runner([
            'runner_id' => 'PC-DIRECT-NO-REPAIR',
            'hostname' => 'PC-DIRECT-NO-REPAIR',
            'transport_mode' => 'direct_https',
        ]);

        $this->actingAs($this->adminUser())
            ->get(route('admin.runners.show', $runner))
            ->assertOk()
            ->assertSee('Repair/update blocked for Direct HTTPS MVP')
            ->assertSee('repair_update blocked for Direct HTTPS MVP')
            ->assertDontSee('Queue repair/update');
    }

    public function test_direct_runner_detail_uses_calm_missing_data_wording(): void
    {
        $runner = $this->runner([
            'runner_id' => 'PC-DIRECT-EMPTY',
            'hostname' => 'PC-DIRECT-EMPTY',
            'runner_guid' => null,
            'runner_version' => null,
            'transport_mode' => 'direct_https',
            'last_seen_at' => null,
            'last_direct_heartbeat_at' => null,
            'last_direct_poll_at' => null,
        ]);

        $this->actingAs($this->adminUser())
            ->get(route('admin.runners.show', $runner))
            ->assertOk()
            ->assertSee('Direct HTTPS Status')
            ->assertSee('Runner GUID not reported')
            ->assertSee('Version not reported')
            ->assertSee('Waiting for first runner cycle')
            ->assertSee('No command poll recorded yet')
            ->assertSee('No command ACK yet')
            ->assertSee('No direct commands yet');
    }

    public function test_collector_share_runner_detail_does_not_show_direct_https_status(): void
    {
        $runner = $this->runner([
            'runner_id' => 'PC-COLLECTOR-01',
            'hostname' => 'PC-COLLECTOR-01',
            'transport_mode' => 'collector_share',
            'last_direct_poll_at' => null,
        ]);

        $this->actingAs($this->adminUser())
            ->get(route('admin.runners.show', $runner))
            ->assertOk()
            ->assertDontSee('Direct HTTPS Status')
            ->assertDontSee('No command poll recorded yet')
            ->assertSee('Runner Health');
    }

    public function test_direct_runner_detail_shows_stale_unacked_command_indicator(): void
    {
        $this->travelTo('2026-05-04 10:00:00');
        $runner = $this->runner([
            'runner_id' => 'PC-DIRECT-STALE',
            'hostname' => 'PC-DIRECT-STALE',
            'transport_mode' => 'direct_https',
            'last_direct_heartbeat_at' => now(),
            'last_direct_poll_at' => now()->subHours(25),
        ]);

        RunnerCommand::query()->create([
            'runner_id' => 'PC-DIRECT-STALE',
            'command_type' => 'scan_now',
            'status' => 'dispatched',
            'requested_by' => 'portal',
            'requested_at' => now()->subHours(25),
            'dispatched_at' => now()->subHours(25),
            'delivered_to_runner_at' => now()->subHours(25),
        ]);

        $this->actingAs($this->adminUser())
            ->get(route('admin.runners.show', $runner))
            ->assertOk()
            ->assertSee('Direct HTTPS Status')
            ->assertSee('Stale — delivered but no ACK received');
    }

    private function adminUser(): User
    {
        return User::query()->create([
            'name' => 'Inventory Admin',
            'email' => 'admin@tvri.local',
            'password' => Hash::make('password'),
        ]);
    }

    private function runner(array $attributes = []): Runner
    {
        return Runner::query()->create([
            'runner_id' => 'IT-ADMIN',
            'hostname' => 'IT-ADMIN',
            'runner_version' => '1.0.0',
            'last_seen_at' => '2026-04-22 08:00:00',
            'last_inventory_status' => 'success',
            'last_upload_status' => 'uploaded',
            ...$attributes,
        ]);
    }
}
