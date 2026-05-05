<?php

namespace Tests\Unit;

use App\Models\StorageHealthObservation;
use App\Services\Inventory\StorageHealthRiskService;
use Tests\TestCase;

class StorageHealthRiskServiceTest extends TestCase
{
    public function test_nvme_data_units_written_are_converted_to_tbw(): void
    {
        $service = new StorageHealthRiskService();

        $row = $service->normalizeObservation([
            'disk_key' => 'serial:NVME001',
            'disk_type' => 'nvme',
            'smart_available' => true,
            'data_units_written' => 1000,
        ]);

        $this->assertSame(512000000, $row['tbw_bytes']);
        $this->assertSame(0.51, $row['tbw_gb']);
    }

    public function test_unavailable_tbw_remains_null(): void
    {
        $service = new StorageHealthRiskService();

        $row = $service->normalizeObservation([
            'disk_key' => 'serial:SSD001',
            'disk_type' => 'ssd',
            'smart_available' => true,
            'percentage_used' => '',
            'tbw_bytes' => '',
            'tbw_gb' => '',
        ]);

        $this->assertNull($row['tbw_bytes']);
        $this->assertNull($row['tbw_gb']);
    }

    public function test_hdd_pending_sector_triggers_critical(): void
    {
        $service = new StorageHealthRiskService();

        $row = $service->normalizeObservation([
            'disk_key' => 'serial:HDD001',
            'disk_type' => 'hdd',
            'smart_available' => true,
            'current_pending_sector' => 1,
        ]);

        $this->assertSame('critical', $row['risk_level']);
    }

    public function test_hdd_reallocated_sector_increase_triggers_critical(): void
    {
        $service = new StorageHealthRiskService();
        $previous = new StorageHealthObservation(['reallocated_sector_count' => 3]);

        $row = $service->normalizeObservation([
            'disk_key' => 'serial:HDD001',
            'disk_type' => 'hdd',
            'smart_available' => true,
            'reallocated_sector_count' => 4,
        ], $previous);

        $this->assertSame('critical', $row['risk_level']);
    }

    public function test_hdd_stable_reallocated_sectors_trigger_warning(): void
    {
        $service = new StorageHealthRiskService();
        $previous = new StorageHealthObservation(['reallocated_sector_count' => 4]);

        $row = $service->normalizeObservation([
            'disk_key' => 'serial:HDD001',
            'disk_type' => 'hdd',
            'smart_available' => true,
            'reallocated_sector_count' => 4,
        ], $previous);

        $this->assertSame('warning', $row['risk_level']);
    }

    public function test_ssd_percentage_used_thresholds(): void
    {
        $service = new StorageHealthRiskService();

        $critical = $service->normalizeObservation([
            'disk_key' => 'serial:SSD001',
            'disk_type' => 'ssd',
            'smart_available' => true,
            'percentage_used' => 90,
        ]);
        $warning = $service->normalizeObservation([
            'disk_key' => 'serial:SSD002',
            'disk_type' => 'ssd',
            'smart_available' => true,
            'percentage_used' => 70,
        ]);

        $this->assertSame('critical', $critical['risk_level']);
        $this->assertSame('warning', $warning['risk_level']);
    }

    public function test_unreadable_smart_triggers_unknown(): void
    {
        $service = new StorageHealthRiskService();

        $row = $service->normalizeObservation([
            'disk_key' => 'serial:SSD001',
            'disk_type' => 'ssd',
            'smart_available' => false,
            'storage_health_detail' => 'SMART/NVMe write counter not readable through controller',
        ]);

        $this->assertSame('unknown', $row['risk_level']);
        $this->assertStringContainsString('controller', implode(' ', $row['risk_reasons']));
    }
}
