<?php

namespace Tests\Unit;

use App\Services\Inventory\CsvNormalizer;
use Tests\TestCase;

class CsvNormalizerTest extends TestCase
{
    public function test_generic_identity_values_are_not_authoritative(): void
    {
        $normalizer = new CsvNormalizer();

        $payload = $normalizer->normalizePayload([
            'serial_no' => 'To Be Filled By O.E.M.',
            'motherboard_serial' => '00000000',
            'system_uuid' => 'FFFFFFFF',
            'mac_address' => 'AA-BB-CC-DD-EE-01',
            'asset_code' => 'IT-WS-001',
            'scan_time' => '2026-04-20T10:00:00',
        ]);

        $this->assertSame('', $payload['serial_no']);
        $this->assertSame('', $payload['motherboard_serial']);
        $this->assertSame('', $payload['system_uuid']);
        $this->assertSame('AA-BB-CC-DD-EE-01', $payload['mac_address']);

        $identityMap = $normalizer->extractIdentityMap($payload);

        $this->assertArrayNotHasKey('serial_no', $identityMap);
        $this->assertArrayNotHasKey('motherboard_serial', $identityMap);
        $this->assertArrayNotHasKey('system_uuid', $identityMap);
        $this->assertSame(70, $identityMap['mac_address']['weight']);
        $this->assertSame(35, $identityMap['asset_code']['weight']);
    }

    public function test_csv_columns_are_mapped_to_internal_snapshot_fields(): void
    {
        $normalizer = new CsvNormalizer();
        $csv = implode(',', [
            'Asset_Code',
            'User',
            'Serial_No',
            'System_UUID',
            'RAM_Manufacturers',
            'RAM_Part_Numbers',
            'RAM_Serial_Numbers',
            'RAM_Speeds_MHz',
            'RAM_Types',
            'RAM_Slots',
            'SSD_TBW_Bytes',
            'SSD_TBW_GB',
            'SSD_Health_Detail',
            'Scan_Time',
        ]) . "\n"
            . implode(',', [
                'IT-WS-001',
                'andi',
                'ABC123',
                'UUID-001',
                'Samsung',
                'M471A2K43CB1',
                '1234ABCD',
                '3200',
                'DDR4',
                'DIMM 1',
                '1953125000000',
                '1819.0',
                'TBW 1819.0 GB | Used 12% | Power-On 10345 h | Source smartctl_nvme_data_units_written',
                '2026-04-20 10:00:00',
            ]) . "\n";

        $payloads = $normalizer->csvTextToPayloads($csv);

        $this->assertCount(1, $payloads);
        $this->assertSame('IT-WS-001', $payloads[0]['asset_code']);
        $this->assertSame('andi', $payloads[0]['user_name']);
        $this->assertSame('ABC123', $payloads[0]['serial_no']);
        $this->assertSame('UUID-001', $payloads[0]['system_uuid']);
        $this->assertSame('Samsung', $payloads[0]['ram_manufacturers']);
        $this->assertSame('M471A2K43CB1', $payloads[0]['ram_part_numbers']);
        $this->assertSame('1234ABCD', $payloads[0]['ram_serial_numbers']);
        $this->assertSame('3200', $payloads[0]['ram_speeds_mhz']);
        $this->assertSame('DDR4', $payloads[0]['ram_types']);
        $this->assertSame('DIMM 1', $payloads[0]['ram_slots']);
        $this->assertSame('1953125000000', $payloads[0]['ssd_tbw_bytes']);
        $this->assertSame('1819.0', $payloads[0]['ssd_tbw_gb']);
        $this->assertStringContainsString('Used 12%', $payloads[0]['ssd_health_detail']);
        $this->assertSame('2026-04-20 10:00:00', $payloads[0]['scan_time']);
    }

    public function test_scan_time_with_timezone_offset_is_converted_to_app_timezone(): void
    {
        config(['app.timezone' => 'Asia/Tokyo']);

        $normalizer = new CsvNormalizer();

        $payload = $normalizer->normalizePayload([
            'scan_time' => '2026-04-22T07:23:06+00:00',
        ]);

        $this->assertSame('2026-04-22 16:23:06', $payload['scan_time']);
    }

    public function test_smartctl_json_fragments_are_not_identity_values(): void
    {
        $normalizer = new CsvNormalizer();

        $payload = $normalizer->normalizePayload([
            'system_uuid' => '"argv":["smartctl"',
            'serial_no' => '"platform_info":"x86_64-w64-mingw32"',
            'mac_address' => 'AA-BB-CC-DD-EE-01',
            'scan_time' => '2026-04-20T10:00:00',
        ]);

        $this->assertSame('', $payload['system_uuid']);
        $this->assertSame('', $payload['serial_no']);
        $this->assertSame('AA-BB-CC-DD-EE-01', $payload['mac_address']);
    }
}
