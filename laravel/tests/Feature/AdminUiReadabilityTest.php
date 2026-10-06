<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\CollectorSite;
use App\Models\Runner;
use App\Models\RunnerCommand;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AdminUiReadabilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_devices_page_uses_custom_admin_pagination_markup(): void
    {
        $user = $this->adminUser();

        for ($i = 1; $i <= 55; $i++) {
            Device::query()->create([
                'device_uid' => 'ui-device-' . $i,
                'first_seen_at' => '2026-04-27 08:00:00',
                'last_seen_at' => sprintf('2026-04-27 08:%02d:00', $i % 60),
                'current_asset_code' => 'UI-' . str_pad((string) $i, 3, '0', STR_PAD_LEFT),
                'current_department' => '',
                'current_site' => 'Bhayangkara',
                'current_user_name' => '',
                'manufacturer' => 'Dell',
                'model' => 'OptiPlex',
            ]);
        }

        $this->actingAs($user)
            ->get('/devices')
            ->assertOk()
            ->assertSee('pagination-shell', false)
            ->assertSee('Previous')
            ->assertSee('Next')
            ->assertSee('Showing 1-50 of 55 item(s)');
    }

    public function test_pilot_readiness_page_renders_for_authenticated_admin(): void
    {
        $this->actingAs($this->adminUser())
            ->get('/pilot-readiness')
            ->assertOk()
            ->assertSee('Pilot Readiness')
            ->assertSee('Readiness Checks')
            ->assertSee('Recent collector heartbeat')
            ->assertSee('Runner Versions')
            ->assertSee('/runners?status=stale', false)
            ->assertSee('/commands?status=attention', false)
            ->assertSee('/storage-health?risk=critical', false);
    }

    public function test_runner_status_filters_show_operational_subsets(): void
    {
        $user = $this->adminUser();
        $this->travelTo('2026-04-30 12:00:00');

        Runner::query()->create([
            'runner_id' => 'PC-RECENT',
            'hostname' => 'PC-RECENT',
            'runner_version' => config('inventory.runner_target_version'),
            'last_seen_at' => now()->subMinutes(5),
        ]);

        Runner::query()->create([
            'runner_id' => 'PC-STALE',
            'hostname' => 'PC-STALE',
            'runner_version' => '1.0.18',
            'last_seen_at' => now()->subDays(2),
        ]);

        Runner::query()->create([
            'runner_id' => 'PC-OLD',
            'hostname' => 'PC-OLD',
            'runner_version' => '1.0.12',
            'last_seen_at' => now()->subMinutes(10),
        ]);

        $this->actingAs($user)
            ->get('/runners?status=stale')
            ->assertOk()
            ->assertSee('PC-STALE')
            ->assertDontSee('PC-RECENT');

        $this->actingAs($user)
            ->get('/runners?status=older-version')
            ->assertOk()
            ->assertSee('PC-OLD')
            ->assertDontSee('PC-RECENT');
    }

    public function test_runners_list_renders_transport_badges_and_direct_poll_status(): void
    {
        $this->travelTo('2026-05-04 10:00:00');
        CollectorSite::query()->create(['site_id' => 'SITE-HQ', 'site_name' => 'Bhayangkara']);

        Runner::query()->create([
            'runner_id' => 'PC-COLLECTOR-01',
            'hostname' => 'PC-COLLECTOR-01',
            'site_id' => 'SITE-HQ',
            'runner_version' => null,
            'last_seen_at' => now()->subMinutes(10),
            'transport_mode' => 'collector_share',
        ]);

        Runner::query()->create([
            'runner_id' => 'PC-DIRECT-01',
            'hostname' => 'PC-DIRECT-01',
            'site_id' => 'SITE-HQ',
            'runner_version' => '1.0.21',
            'last_seen_at' => now()->subMinutes(5),
            'last_direct_heartbeat_at' => now()->subMinutes(5),
            'last_direct_poll_at' => null,
            'transport_mode' => 'direct_https',
        ]);

        $this->actingAs($this->adminUser())
            ->get('/runners')
            ->assertOk()
            ->assertSee('Collector Share')
            ->assertSee('Direct HTTPS')
            ->assertSee('Version not reported')
            ->assertSee('No command poll recorded yet')
            ->assertSee('Not applicable — collector-share mode')
            ->assertSee('Healthy');
    }

    public function test_runners_list_uses_direct_upload_as_last_inventory(): void
    {
        $this->travelTo('2026-05-04 10:00:00');

        Runner::query()->create([
            'runner_id' => 'LAPTOP-I76TA97E',
            'hostname' => 'LAPTOP-I76TA97E',
            'runner_version' => '1.0.21',
            'last_seen_at' => now(),
            'last_direct_heartbeat_at' => now(),
            'transport_mode' => 'direct_https',
            'last_successful_inventory_at' => null,
            'last_inventory_status' => null,
            'last_upload_status' => null,
            'last_direct_upload_at' => '2026-05-04 09:10:00',
            'last_upload_error' => null,
        ]);

        $this->actingAs($this->adminUser())
            ->get('/runners')
            ->assertOk()
            ->assertSee('LAPTOP-I76TA97E')
            ->assertSee(\App\Support\InventoryTime::format('2026-05-04 09:10:00'))
            ->assertSee('Uploaded')
            ->assertDontSee('No direct upload yet')
            ->assertDontSee('- / -');
    }

    public function test_runners_list_uses_calm_direct_upload_missing_wording(): void
    {
        $this->travelTo('2026-05-04 10:00:00');

        Runner::query()->create([
            'runner_id' => 'PC-DIRECT-NO-UPLOAD',
            'hostname' => 'PC-DIRECT-NO-UPLOAD',
            'runner_version' => '1.0.21',
            'last_seen_at' => now(),
            'last_direct_heartbeat_at' => now(),
            'transport_mode' => 'direct_https',
            'last_successful_inventory_at' => null,
            'last_inventory_status' => null,
            'last_upload_status' => null,
            'last_direct_upload_at' => null,
        ]);

        $this->actingAs($this->adminUser())
            ->get('/runners')
            ->assertOk()
            ->assertSee('PC-DIRECT-NO-UPLOAD')
            ->assertSee('No direct upload yet')
            ->assertDontSee('- / -');
    }

    public function test_runners_list_collector_share_inventory_display_remains_unchanged(): void
    {
        $this->travelTo('2026-05-04 10:00:00');

        Runner::query()->create([
            'runner_id' => 'PC-COLLECTOR-HEALTH',
            'hostname' => 'PC-COLLECTOR-HEALTH',
            'runner_version' => '1.0.21',
            'last_seen_at' => now(),
            'transport_mode' => 'collector_share',
            'last_successful_inventory_at' => '2026-05-04 08:00:00',
            'last_inventory_status' => 'success',
            'last_upload_status' => 'uploaded',
            'last_direct_upload_at' => null,
        ]);

        $this->actingAs($this->adminUser())
            ->get('/runners')
            ->assertOk()
            ->assertSee('PC-COLLECTOR-HEALTH')
            ->assertSee(\App\Support\InventoryTime::format('2026-05-04 08:00:00'))
            ->assertSee('success / uploaded')
            ->assertDontSee('No direct upload yet');
    }

    public function test_collector_share_runner_missing_direct_poll_is_not_rendered_as_error(): void
    {
        $this->travelTo('2026-05-04 10:00:00');

        Runner::query()->create([
            'runner_id' => 'PC-COLLECTOR-01',
            'hostname' => 'PC-COLLECTOR-01',
            'last_seen_at' => now(),
            'transport_mode' => null,
        ]);

        $this->actingAs($this->adminUser())
            ->get('/runners')
            ->assertOk()
            ->assertSee('Collector Share')
            ->assertSee('Not applicable — collector-share mode')
            ->assertDontSee('No command poll recorded yet')
            ->assertDontSee('Stale — delivered but no ACK received');
    }

    public function test_runners_list_shows_direct_command_counts_and_does_not_render_token_secrets(): void
    {
        $this->travelTo('2026-05-04 10:00:00');
        Config::set('inventory.site_tokens_json', json_encode([
            'SITE-HQ' => [
                'token' => 'direct-secret-not-for-ui',
                'token_type' => 'direct_runner',
            ],
        ]));

        Runner::query()->create([
            'runner_id' => 'PC-DIRECT-01',
            'hostname' => 'PC-DIRECT-01',
            'runner_version' => '1.0.21',
            'last_seen_at' => now(),
            'last_direct_heartbeat_at' => now(),
            'last_direct_poll_at' => now()->subMinute(),
            'transport_mode' => 'direct_https',
        ]);

        RunnerCommand::query()->create([
            'runner_id' => 'PC-DIRECT-01',
            'command_type' => 'scan_now',
            'status' => 'dispatched',
            'requested_by' => 'portal',
            'requested_at' => now(),
            'dispatched_at' => now(),
        ]);

        $this->actingAs($this->adminUser())
            ->get('/runners')
            ->assertOk()
            ->assertSee('Direct HTTPS')
            ->assertSee('1 pending command(s)')
            ->assertSee('Command awaiting ACK')
            ->assertDontSee('direct-secret-not-for-ui');
    }

    public function test_runners_list_blocks_repair_update_action_for_direct_https_runner(): void
    {
        $this->travelTo('2026-05-04 10:00:00');

        $runner = Runner::query()->create([
            'runner_id' => 'PC-DIRECT-NO-REPAIR',
            'hostname' => 'PC-DIRECT-NO-REPAIR',
            'runner_version' => '1.0.21',
            'last_seen_at' => now(),
            'last_direct_heartbeat_at' => now(),
            'transport_mode' => 'direct_https',
        ]);

        $this->actingAs($this->adminUser())
            ->get('/runners')
            ->assertOk()
            ->assertSee('PC-DIRECT-NO-REPAIR')
            ->assertSee('Repair/update blocked for Direct HTTPS MVP')
            ->assertDontSee(route('admin.runners.repair', $runner), false);
    }

    public function test_runners_list_keeps_repair_update_action_for_collector_share_runner(): void
    {
        $this->travelTo('2026-05-04 10:00:00');

        $runner = Runner::query()->create([
            'runner_id' => 'PC-COLLECTOR-REPAIR',
            'hostname' => 'PC-COLLECTOR-REPAIR',
            'runner_version' => '1.0.18',
            'last_seen_at' => now(),
            'transport_mode' => 'collector_share',
        ]);

        $this->actingAs($this->adminUser())
            ->get('/runners')
            ->assertOk()
            ->assertSee('PC-COLLECTOR-REPAIR')
            ->assertSee(route('admin.runners.repair', $runner), false)
            ->assertSee('Repair/update')
            ->assertDontSee('Repair/update blocked for Direct HTTPS MVP');
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
