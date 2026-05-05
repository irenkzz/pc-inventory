<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\User;
use App\Services\Inventory\InventoryIngestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DeviceAssignmentOverrideTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_define_device_department_site_location_and_room(): void
    {
        Storage::fake('local');

        $user = User::query()->create([
            'name' => 'Inventory Admin',
            'email' => 'admin@tvri.local',
            'password' => Hash::make('password'),
        ]);

        $ingest = app(InventoryIngestService::class);
        $created = $ingest->ingestJsonPayload($this->payload([
            'department' => 'Scanner IT',
            'site_estimated' => 'SITE-HQ',
            'location' => 'Head Office',
            'room' => 'General',
            'scan_time' => '2026-04-22 08:00:00',
        ]));

        $device = Device::query()->findOrFail($created['device_id']);

        $this->assertDatabaseHas('devices', [
            'id' => $device->id,
            'current_department' => 'IT',
            'current_site' => 'Bhayangkara',
            'current_location' => '',
            'current_room' => '',
        ]);

        $this->actingAs($user)
            ->post(route('admin.devices.assignment.update', $device), [
                'department' => 'Finance',
                'site' => 'SITE-BRANCH',
                'location' => 'Branch Office',
                'room' => 'Room 204',
            ])
            ->assertRedirect(route('admin.devices.show', $device));

        $this->assertDatabaseHas('devices', [
            'id' => $device->id,
            'manual_department' => 'Finance',
            'manual_site' => 'SITE-BRANCH',
            'manual_location' => 'Branch Office',
            'manual_room' => 'Room 204',
            'current_department' => 'Finance',
            'current_site' => 'SITE-BRANCH',
            'current_location' => 'Branch Office',
            'current_room' => 'Room 204',
        ]);

        $ingest->ingestJsonPayload($this->payload([
            'department' => 'Scanner Changed',
            'site_estimated' => 'SITE-SCANNER',
            'location' => 'Scanner Location',
            'room' => 'Scanner Room',
            'scan_time' => '2026-04-22 09:00:00',
        ]));

        $this->assertSame(1, Device::query()->count());
        $this->assertDatabaseHas('devices', [
            'id' => $device->id,
            'current_department' => 'Finance',
            'current_site' => 'SITE-BRANCH',
            'current_location' => 'Branch Office',
            'current_room' => 'Room 204',
        ]);
    }

    public function test_blank_portal_assignment_fields_clear_current_assignment_values(): void
    {
        Storage::fake('local');

        $user = User::query()->create([
            'name' => 'Inventory Admin',
            'email' => 'admin@tvri.local',
            'password' => Hash::make('password'),
        ]);

        $created = app(InventoryIngestService::class)->ingestJsonPayload($this->payload([]));
        $device = Device::query()->findOrFail($created['device_id']);

        $this->actingAs($user)
            ->post(route('admin.devices.assignment.update', $device), [
                'department' => 'Finance',
                'site' => 'SITE-BRANCH',
                'location' => 'Branch Office',
                'room' => 'Room 204',
            ])
            ->assertRedirect(route('admin.devices.show', $device));

        $this->actingAs($user)
            ->post(route('admin.devices.assignment.update', $device), [
                'department' => '',
                'site' => '',
                'location' => '',
                'room' => '',
            ])
            ->assertRedirect(route('admin.devices.show', $device));

        $this->assertDatabaseHas('devices', [
            'id' => $device->id,
            'manual_department' => null,
            'manual_site' => null,
            'manual_location' => null,
            'manual_room' => null,
            'current_department' => 'IT',
            'current_site' => 'Bhayangkara',
            'current_location' => '',
            'current_room' => '',
        ]);
    }

    public function test_device_detail_page_shows_operational_sections(): void
    {
        Storage::fake('local');

        $user = User::query()->create([
            'name' => 'Inventory Admin',
            'email' => 'admin@tvri.local',
            'password' => Hash::make('password'),
        ]);

        $created = app(InventoryIngestService::class)->ingestJsonPayload($this->payload([]));
        $device = Device::query()->findOrFail($created['device_id']);

        $this->actingAs($user)
            ->get(route('admin.devices.show', $device))
            ->assertOk()
            ->assertSee('Device inventory')
            ->assertSee('Assignment Override')
            ->assertSee('Hardware and System')
            ->assertSee('Identity Evidence')
            ->assertSee('Latest Raw Snapshot');
    }

    public function test_assignment_override_form_prefills_current_values_when_manual_values_are_blank(): void
    {
        Storage::fake('local');

        $user = User::query()->create([
            'name' => 'Inventory Admin',
            'email' => 'admin@tvri.local',
            'password' => Hash::make('password'),
        ]);

        $created = app(InventoryIngestService::class)->ingestJsonPayload($this->payload([]));
        $device = Device::query()->findOrFail($created['device_id']);
        $device->forceFill([
            'current_department' => 'IT',
            'current_site' => 'Bhayangkara',
            'current_location' => 'Jayapura',
            'current_room' => 'Studio 1',
            'manual_department' => null,
            'manual_site' => null,
            'manual_location' => null,
            'manual_room' => null,
        ])->save();

        $this->actingAs($user)
            ->get(route('admin.devices.show', $device))
            ->assertOk()
            ->assertSee('name="department" value="IT"', false)
            ->assertSee('name="site" value="Bhayangkara"', false)
            ->assertSee('name="location" value="Jayapura"', false)
            ->assertSee('name="room" value="Studio 1"', false);
    }

    public function test_device_pages_render_semicolon_hardware_details_as_readable_lists(): void
    {
        Storage::fake('local');

        $user = User::query()->create([
            'name' => 'Inventory Admin',
            'email' => 'admin@tvri.local',
            'password' => Hash::make('password'),
        ]);

        $created = app(InventoryIngestService::class)->ingestJsonPayload($this->payload([
            'gpu' => 'Intel UHD Graphics 770; NVIDIA RTX 3060',
            'disk' => '512 GB NVMe SSD; 1 TB SATA HDD',
            'ram_gb' => '16384',
            'ram_slots_used' => '2',
            'ram_detail' => 'Slot 1: 8192 MB DDR4 3200 MHz; Slot 2: 8192 MB DDR4 3200 MHz',
            'ssd_tbw_gb' => '1234.5',
            'ssd_percentage_used' => '12',
        ]));
        $device = Device::query()->findOrFail($created['device_id']);

        $this->actingAs($user)
            ->get(route('admin.devices.index'))
            ->assertOk()
            ->assertSee('<ul class="spec-list compact">', false)
            ->assertSee('Intel UHD Graphics 770')
            ->assertSee('NVIDIA RTX 3060')
            ->assertSee('16.384 GB');

        $this->actingAs($user)
            ->get(route('admin.devices.show', $device))
            ->assertOk()
            ->assertSee('<ul class="spec-list">', false)
            ->assertSee('Slot 1: 8192 MB DDR4 3200 MHz')
            ->assertSee('Slot 2: 8192 MB DDR4 3200 MHz')
            ->assertSee('1.234,5 GB')
            ->assertSee('12%');
    }

    public function test_department_is_assigned_from_asset_name_prefix(): void
    {
        Storage::fake('local');

        $ingest = app(InventoryIngestService::class);

        foreach ([
            'BRT-REDAKTUR' => 'Pemberitaan',
            'EDT-PAT2023' => 'Teknik',
            'MCR-PLAYOUT1' => 'Teknik',
            'PGM-PROGRAM03' => 'Program',
            'IT-ADMIN' => 'IT',
            'SRV-SERVER01' => 'IT',
        ] as $assetCode => $department) {
            $result = $ingest->ingestJsonPayload($this->payload([
                'asset_code' => $assetCode,
                'motherboard_serial' => 'MB-' . $assetCode,
                'serial_no' => 'SN-' . $assetCode,
                'system_uuid' => '11111111-2222-3333-4444-' . sprintf('%012u', crc32($assetCode)),
                'mac_address' => '',
            ]));

            $this->assertDatabaseHas('devices', [
                'id' => $result['device_id'],
                'current_asset_code' => $assetCode,
                'current_department' => $department,
            ]);
        }
    }

    public function test_site_hq_scanner_value_is_displayed_as_bhayangkara(): void
    {
        Storage::fake('local');

        $ingest = app(InventoryIngestService::class);

        foreach (['SITE-HQ', 'Head Office'] as $siteEstimated) {
            $result = $ingest->ingestJsonPayload($this->payload([
                'asset_code' => 'IT-' . strtoupper(str_replace(' ', '-', $siteEstimated)),
                'motherboard_serial' => 'MB-' . $siteEstimated,
                'serial_no' => 'SN-' . $siteEstimated,
                'system_uuid' => '22222222-3333-4444-5555-' . sprintf('%012u', crc32($siteEstimated)),
                'mac_address' => '',
                'site_estimated' => $siteEstimated,
            ]));

            $this->assertDatabaseHas('devices', [
                'id' => $result['device_id'],
                'current_site' => 'Bhayangkara',
            ]);
        }
    }

    private function payload(array $overrides): array
    {
        return [
            'asset_code' => 'IT-OVR-001',
            'department' => 'Scanner IT',
            'location' => 'Head Office',
            'room' => 'General',
            'site_estimated' => 'SITE-HQ',
            'site_method' => 'manual',
            'site_confidence' => 'high',
            'user_name' => 'budi',
            'user_raw' => 'budi',
            'manufacturer' => 'Lenovo',
            'model' => 'ThinkCentre',
            'system_type' => 'x64-based PC',
            'motherboard' => 'Lenovo board',
            'motherboard_serial' => 'MB-OVERRIDE-001',
            'cpu' => 'Intel Core i5',
            'gpu' => 'Intel UHD',
            'ram_gb' => '16',
            'disk' => '512 GB SSD',
            'serial_no' => 'SN-OVERRIDE-001',
            'system_uuid' => '11111111-2222-3333-4444-555555555555',
            'mac_address' => '00-11-22-33-44-55',
            'scan_time' => '2026-04-22 08:00:00',
            ...$overrides,
        ];
    }
}
