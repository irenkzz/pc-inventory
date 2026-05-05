<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Inventory\InventoryIngestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DeviceHardwareFilterTest extends TestCase
{
    use RefreshDatabase;

    public function test_devices_page_can_filter_by_processor(): void
    {
        Storage::fake('local');
        $this->seedHardwareDevices();

        $this->actingAs($this->adminUser())
            ->get('/devices?cpu=Intel%20Core%20i5')
            ->assertOk()
            ->assertSee('Processor')
            ->assertSee('IT-WS-001')
            ->assertDontSee('PGM-EDIT-01');
    }

    public function test_devices_page_can_filter_by_ram_size(): void
    {
        Storage::fake('local');
        $this->seedHardwareDevices();

        $this->actingAs($this->adminUser())
            ->get('/devices?ram_gb=32')
            ->assertOk()
            ->assertSee('RAM exact')
            ->assertSee('PGM-EDIT-01')
            ->assertDontSee('IT-WS-001');
    }

    public function test_devices_page_can_filter_by_minimum_ram(): void
    {
        Storage::fake('local');
        $this->seedHardwareDevices();

        $this->actingAs($this->adminUser())
            ->get('/devices?ram_min_gb=16')
            ->assertOk()
            ->assertSee('RAM minimum')
            ->assertSee('IT-WS-001')
            ->assertSee('PGM-EDIT-01')
            ->assertDontSee('BRT-LOWRAM-01');
    }

    public function test_devices_page_can_filter_by_gpu_and_storage(): void
    {
        Storage::fake('local');
        $this->seedHardwareDevices();

        $this->actingAs($this->adminUser())
            ->get('/devices?gpu=NVIDIA&disk=1%20TB')
            ->assertOk()
            ->assertSee('GPU')
            ->assertSee('Storage')
            ->assertSee('PGM-EDIT-01')
            ->assertDontSee('IT-WS-001')
            ->assertDontSee('BRT-LOWRAM-01');
    }

    public function test_devices_page_uses_latest_snapshot_for_hardware_filters(): void
    {
        Storage::fake('local');
        $user = $this->adminUser();

        $ingest = app(InventoryIngestService::class);
        $ingest->ingestJsonPayload($this->payload([
            'asset_code' => 'SRV-UPGRADE-01',
            'motherboard_serial' => 'MB-SRV-001',
            'serial_no' => 'SN-SRV-001',
            'system_uuid' => '11111111-2222-3333-4444-000000000010',
            'cpu' => 'Intel Core i3',
            'ram_gb' => '8',
            'scan_time' => '2026-04-27 07:00:00',
        ]));
        $ingest->ingestJsonPayload($this->payload([
            'asset_code' => 'SRV-UPGRADE-01',
            'motherboard_serial' => 'MB-SRV-001',
            'serial_no' => 'SN-SRV-001',
            'system_uuid' => '11111111-2222-3333-4444-000000000010',
            'cpu' => 'Intel Core i7',
            'ram_gb' => '32',
            'scan_time' => '2026-04-27 10:00:00',
        ]));

        $this->actingAs($user)
            ->get('/devices?cpu=Intel%20Core%20i3')
            ->assertOk()
            ->assertDontSee('SRV-UPGRADE-01');

        $this->actingAs($user)
            ->get('/devices?cpu=Intel%20Core%20i7&ram_min_gb=32')
            ->assertOk()
            ->assertSee('SRV-UPGRADE-01');
    }

    public function test_devices_page_can_sort_by_asset_name(): void
    {
        Storage::fake('local');
        $this->seedHardwareDevices();

        $this->actingAs($this->adminUser())
            ->get('/devices?sort=asset&direction=asc')
            ->assertOk()
            ->assertSeeInOrder([
                'BRT-LOWRAM-01',
                'IT-WS-001',
                'PGM-EDIT-01',
            ]);
    }

    public function test_devices_page_can_sort_by_ram_descending(): void
    {
        Storage::fake('local');
        $this->seedHardwareDevices();

        $this->actingAs($this->adminUser())
            ->get('/devices?sort=ram&direction=desc')
            ->assertOk()
            ->assertSeeInOrder([
                'PGM-EDIT-01',
                'IT-WS-001',
                'BRT-LOWRAM-01',
            ]);
    }

    private function seedHardwareDevices(): void
    {
        $ingest = app(InventoryIngestService::class);

        $ingest->ingestJsonPayload($this->payload([
            'asset_code' => 'IT-WS-001',
            'motherboard_serial' => 'MB-IT-001',
            'serial_no' => 'SN-IT-001',
            'system_uuid' => '11111111-2222-3333-4444-000000000001',
            'cpu' => 'Intel Core i5',
            'ram_gb' => '16',
            'scan_time' => '2026-04-27 08:00:00',
        ]));

        $ingest->ingestJsonPayload($this->payload([
            'asset_code' => 'PGM-EDIT-01',
            'motherboard_serial' => 'MB-PGM-001',
            'serial_no' => 'SN-PGM-001',
            'system_uuid' => '11111111-2222-3333-4444-000000000002',
            'cpu' => 'AMD Ryzen 7',
            'gpu' => 'NVIDIA RTX 3060',
            'ram_gb' => '32',
            'disk' => '1 TB NVMe SSD',
            'scan_time' => '2026-04-27 09:00:00',
        ]));

        $ingest->ingestJsonPayload($this->payload([
            'asset_code' => 'BRT-LOWRAM-01',
            'motherboard_serial' => 'MB-BRT-001',
            'serial_no' => 'SN-BRT-001',
            'system_uuid' => '11111111-2222-3333-4444-000000000003',
            'cpu' => 'Intel Pentium Gold',
            'gpu' => 'Intel UHD',
            'ram_gb' => '8',
            'disk' => '256 GB SSD',
            'scan_time' => '2026-04-27 10:00:00',
        ]));
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
            'asset_code' => 'IT-WS-001',
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
            'motherboard_serial' => 'MB-IT-001',
            'cpu' => 'Intel Core i5',
            'gpu' => 'Intel UHD',
            'ram_gb' => '16',
            'disk' => '512 GB SSD',
            'serial_no' => 'SN-IT-001',
            'system_uuid' => '11111111-2222-3333-4444-000000000001',
            'mac_address' => '',
            'scan_time' => '2026-04-27 08:00:00',
            ...$overrides,
        ];
    }
}
