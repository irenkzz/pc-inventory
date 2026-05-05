<?php

namespace Tests\Unit;

use App\Services\Inventory\ChangeDetectionService;
use App\Services\Inventory\CsvNormalizer;
use Tests\TestCase;

class ChangeDetectionServiceTest extends TestCase
{
    public function test_ssd_health_fields_are_tracked_as_hardware_changes(): void
    {
        $service = new ChangeDetectionService(new CsvNormalizer());

        $changes = $service->detectChanges(
            [
                'ssd_tbw_gb' => 'Disk0 Samsung SSD=1819.0',
                'ssd_health_detail' => 'TBW 1819.0 GB | Used 12%',
            ],
            [
                'ssd_tbw_gb' => 'Disk0 Samsung SSD=1824.5',
                'ssd_health_detail' => 'TBW 1824.5 GB | Used 13%',
            ]
        );

        $this->assertCount(2, $changes);
        $this->assertSame('hardware', $changes[0]['change_group']);
        $this->assertSame('medium', $changes[0]['severity']);
        $this->assertSame('ssd_tbw_gb', $changes[0]['field_name']);
        $this->assertSame('ssd_health_detail', $changes[1]['field_name']);
    }
}
