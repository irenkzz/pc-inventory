<?php

namespace Tests\Feature;

use App\Models\Collector;
use App\Models\CollectorSite;
use App\Models\User;
use App\Services\Runner\CommandQueueService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class CollectorVisibilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $tokenFile = storage_path('framework/testing/collector_visibility_no_tokens.json');
        File::delete($tokenFile);

        Config::set('inventory.require_site_tokens', false);
        Config::set('inventory.site_tokens_file', $tokenFile);
    }

    public function test_collector_visibility_shows_site_health_runners_and_active_commands(): void
    {
        $this->travelTo('2026-04-23 10:00:00');

        $this->postJson('/api/collector/status', [
            'collector_name' => 'hq-collector',
            'collector_version' => '1.0.0',
            'site_name' => 'Head Office',
            'queue_depth_csv' => 2,
            'queue_depth_heartbeat' => 1,
            'last_seen_at' => now()->toIso8601String(),
        ], $this->headers())->assertOk();

        $this->postJson('/api/collector/heartbeat', [
            'runner_id' => 'PC-ACCOUNTING-01',
            'hostname' => 'PC-ACCOUNTING-01',
            'site_name' => 'Head Office',
            'runner_version' => '1.0.0',
            'last_inventory_status' => 'success',
            'last_successful_inventory_at' => now()->toIso8601String(),
            'last_seen_at' => now()->toIso8601String(),
        ], $this->headers())->assertOk();

        app(CommandQueueService::class)->queue('PC-ACCOUNTING-01', 'SITE-HQ', 'scan_now', 'test');

        $this->assertDatabaseHas('collector_sites', [
            'site_id' => 'SITE-HQ',
            'site_name' => 'Bhayangkara',
        ]);

        $this->actingAs($this->adminUser())
            ->get('/collectors')
            ->assertOk()
            ->assertSee('Collector Visibility')
            ->assertSee('Bhayangkara')
            ->assertSee('SITE-HQ')
            ->assertSee('hq-collector')
            ->assertSee('PC-ACCOUNTING-01')
            ->assertSee('Manual scan')
            ->assertSee('CSV 2');
    }

    public function test_attention_filter_limits_to_sites_with_stale_collectors(): void
    {
        CollectorSite::query()->create([
            'site_id' => 'SITE-HQ',
            'site_name' => 'Bhayangkara',
        ]);
        Collector::query()->create([
            'site_id' => 'SITE-HQ',
            'collector_name' => 'hq-collector',
            'collector_version' => '1.0.0',
            'last_seen_at' => now(),
            'last_status' => 'ok',
        ]);

        CollectorSite::query()->create([
            'site_id' => 'SITE-BRANCH',
            'site_name' => 'Branch Office',
        ]);
        Collector::query()->create([
            'site_id' => 'SITE-BRANCH',
            'collector_name' => 'branch-collector',
            'collector_version' => '1.0.0',
            'last_seen_at' => now()->subDays(3),
            'last_status' => 'ok',
        ]);

        $this->actingAs($this->adminUser())
            ->get('/collectors?status=attention')
            ->assertOk()
            ->assertSee('Branch Office')
            ->assertSee('branch-collector')
            ->assertDontSee('Bhayangkara');
    }

    private function adminUser(): User
    {
        return User::query()->create([
            'name' => 'Inventory Admin',
            'email' => 'admin@tvri.local',
            'password' => Hash::make('password'),
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
