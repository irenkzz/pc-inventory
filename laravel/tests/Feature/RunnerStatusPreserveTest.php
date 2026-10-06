<?php

namespace Tests\Feature;

use App\Models\Runner;
use App\Models\User;
use App\Services\Runner\DirectRunnerHeartbeatService;
use App\Services\Runner\RunnerStatusService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RunnerStatusPreserveTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_later_heartbeat_without_inventory_fields_keeps_what_an_upload_recorded(): void
    {
        $status = app(RunnerStatusService::class);
        $status->upsert([
            'runner_id' => 'R-DIRECT', 'site_id' => 'SITE-A', 'runner_version' => '1.0.22',
            'last_successful_inventory_at' => '2026-10-07T01:12:12+09:00',
            'last_inventory_status' => 'success', 'last_upload_status' => 'uploaded',
            'transport_mode' => 'direct_https', 'last_direct_upload_at' => '2026-10-07T01:12:14+09:00',
        ]);

        app(DirectRunnerHeartbeatService::class)->upsert(
            ['runner_id' => 'R-DIRECT', 'hostname' => 'R-DIRECT'],
            ['site_code' => 'SITE-A', 'token_type' => 'direct_runner'],
        );

        $r = Runner::query()->where('runner_id', 'R-DIRECT')->firstOrFail();
        $this->assertSame('1.0.22', $r->runner_version);
        $this->assertSame('success', $r->last_inventory_status);
        $this->assertSame('uploaded', $r->last_upload_status);
        $this->assertNotNull($r->last_successful_inventory_at);
        $this->assertNotNull($r->last_direct_heartbeat_at);
    }

    public function test_non_empty_values_still_overwrite(): void
    {
        $status = app(RunnerStatusService::class);
        $status->upsert(['runner_id' => 'R1', 'site_id' => 'S', 'runner_version' => '1.0.21', 'last_upload_status' => 'old']);
        $status->upsert(['runner_id' => 'R1', 'site_id' => 'S', 'runner_version' => '1.0.22', 'last_upload_status' => 'new']);

        $r = Runner::query()->where('runner_id', 'R1')->firstOrFail();
        $this->assertSame('1.0.22', $r->runner_version);
        $this->assertSame('new', $r->last_upload_status);
    }

    public function test_dashboard_shows_direct_upload_time_and_version_for_a_direct_runner(): void
    {
        Runner::query()->create([
            'runner_id' => 'R-DIRECT', 'site_id' => null, 'runner_version' => '1.0.22', 'transport_mode' => 'direct_https',
            'last_seen_at' => now(), 'last_direct_upload_at' => '2026-10-07 01:12:14',
            'last_successful_inventory_at' => null, 'last_inventory_status' => '', 'last_upload_status' => '',
        ]);

        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('1.0.22')
            ->assertSee('01:12')
            ->assertSee('success / uploaded');
    }
}
