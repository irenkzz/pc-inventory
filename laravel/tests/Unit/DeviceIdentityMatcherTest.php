<?php

namespace Tests\Unit;

use App\Models\Device;
use App\Services\Inventory\CsvNormalizer;
use App\Services\Inventory\DeviceIdentityMatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeviceIdentityMatcherTest extends TestCase
{
    use RefreshDatabase;

    public function test_hostname_change_can_match_by_existing_hardware_hash(): void
    {
        $device = Device::query()->create([
            'device_uid' => hash('sha256', 'old-device'),
            'status' => 'active',
            'first_seen_at' => '2026-04-20 10:00:00',
            'last_seen_at' => '2026-04-20 10:00:00',
            'current_asset_code' => 'OLD-NAME',
            'manufacturer' => 'Dell Inc.',
            'model' => 'OptiPlex',
            'hardware_hash' => hash('sha256', 'same-hardware'),
        ]);

        $matcher = new DeviceIdentityMatcher(new CsvNormalizer());
        $match = $matcher->findBestDeviceMatch([
            'asset_code' => 'NEW-NAME',
            'manufacturer' => 'Dell Inc.',
            'model' => 'OptiPlex',
            'hardware_hash' => hash('sha256', 'same-hardware'),
            'system_uuid' => '',
            'motherboard_serial' => '',
            'serial_no' => '',
            'mac_address' => '',
        ]);

        $this->assertFalse($match['is_new_device']);
        $this->assertSame($device->id, $match['device_id']);
        $this->assertGreaterThanOrEqual(80, $match['score']);
    }
}
