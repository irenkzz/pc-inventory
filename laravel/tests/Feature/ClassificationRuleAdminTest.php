<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\User;
use App\Services\Inventory\InventoryIngestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ClassificationRuleAdminTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_create_rule_and_future_imports_use_it(): void
    {
        Storage::fake('local');
        $user = $this->adminUser();

        $this->actingAs($user)
            ->get('/classification-rules')
            ->assertOk()
            ->assertSee('Asset prefix -&gt; department', false)
            ->assertSee('BRT');

        $this->actingAs($user)
            ->post('/classification-rules', [
                'rule_type' => 'asset_prefix_department',
                'match_value' => 'dok',
                'output_value' => 'Dokumentasi',
                'priority' => '100',
                'is_active' => '1',
            ])
            ->assertRedirect('/classification-rules');

        app(InventoryIngestService::class)->ingestJsonPayload($this->payload([
            'asset_code' => 'DOK-PC-01',
            'motherboard_serial' => 'MB-DOK-PC-01',
            'serial_no' => 'SN-DOK-PC-01',
            'system_uuid' => '33333333-4444-5555-6666-000000000001',
        ]));

        $this->assertDatabaseHas('devices', [
            'current_asset_code' => 'DOK-PC-01',
            'current_department' => 'Dokumentasi',
            'current_site' => 'Bhayangkara',
        ]);
    }

    public function test_admin_can_apply_new_rule_to_existing_devices(): void
    {
        Storage::fake('local');
        $user = $this->adminUser();

        app(InventoryIngestService::class)->ingestJsonPayload($this->payload([
            'asset_code' => 'DOK-PC-02',
            'motherboard_serial' => 'MB-DOK-PC-02',
            'serial_no' => 'SN-DOK-PC-02',
            'system_uuid' => '33333333-4444-5555-6666-000000000002',
        ]));

        $this->assertDatabaseHas('devices', [
            'current_asset_code' => 'DOK-PC-02',
            'current_department' => '',
        ]);

        $this->actingAs($user)->post('/classification-rules', [
            'rule_type' => 'asset_prefix_department',
            'match_value' => 'DOK',
            'output_value' => 'Dokumentasi',
            'priority' => '100',
            'is_active' => '1',
        ]);

        $this->actingAs($user)
            ->post('/classification-rules/apply')
            ->assertRedirect('/classification-rules');

        $this->assertSame('Dokumentasi', Device::query()
            ->where('current_asset_code', 'DOK-PC-02')
            ->value('current_department'));
    }

    private function adminUser(): User
    {
        return User::query()->create([
            'name' => 'Inventory Admin',
            'email' => 'admin@tvri.local',
            'password' => Hash::make('password'),
        ]);
    }

    private function payload(array $overrides): array
    {
        return [
            'asset_code' => 'DOK-PC-01',
            'department' => 'Scanner Department',
            'location' => 'Bhayangkara',
            'room' => 'General',
            'site_estimated' => 'Head Office',
            'site_method' => 'manual',
            'site_confidence' => 'high',
            'user_name' => 'operator',
            'user_raw' => 'operator',
            'manufacturer' => 'Dell',
            'model' => 'OptiPlex',
            'system_type' => 'x64-based PC',
            'motherboard' => 'Dell board',
            'motherboard_serial' => 'MB-DOK-PC-01',
            'cpu' => 'Intel Core i5',
            'gpu' => 'Intel UHD',
            'ram_gb' => '16',
            'disk' => '512 GB SSD',
            'serial_no' => 'SN-DOK-PC-01',
            'system_uuid' => '33333333-4444-5555-6666-000000000001',
            'mac_address' => '',
            'scan_time' => '2026-04-22 08:00:00',
            ...$overrides,
        ];
    }
}
