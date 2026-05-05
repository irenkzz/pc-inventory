<?php

namespace Tests\Feature;

use App\Models\StorageHealthObservation;
use App\Services\Inventory\InventoryIngestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class StorageHealthIngestTest extends TestCase
{
    use RefreshDatabase;

    public function test_inventory_import_succeeds_when_smart_data_is_missing(): void
    {
        Storage::fake('local');

        $result = app(InventoryIngestService::class)->ingestJsonPayload($this->payload([
            [
                'disk_key' => 'serial:SSD001',
                'disk_model' => 'Controller-blocked SSD',
                'disk_serial' => 'SSD001',
                'disk_type' => 'ssd',
                'smart_available' => false,
                'tbw_bytes' => '',
                'tbw_gb' => '',
                'storage_health_detail' => 'SMART/NVMe write counter not readable through controller',
            ],
        ]), 'scan.json', 'test');

        $this->assertSame('created', $result['status']);
        $this->assertDatabaseHas('storage_health_observations', [
            'disk_key' => 'serial:SSD001',
            'risk_level' => 'unknown',
            'tbw_bytes' => null,
            'tbw_gb' => null,
        ]);
    }

    public function test_hdd_reallocated_sector_trend_is_scored_against_previous_scan(): void
    {
        Storage::fake('local');
        $ingest = app(InventoryIngestService::class);

        $ingest->ingestJsonPayload($this->payload([
            [
                'disk_key' => 'serial:HDD001',
                'disk_model' => 'WDC HDD',
                'disk_serial' => 'HDD001',
                'disk_type' => 'hdd',
                'smart_available' => true,
                'reallocated_sector_count' => 1,
            ],
        ], '2026-04-20 10:00:00'), 'scan-1.json', 'test');

        $ingest->ingestJsonPayload($this->payload([
            [
                'disk_key' => 'serial:HDD001',
                'disk_model' => 'WDC HDD',
                'disk_serial' => 'HDD001',
                'disk_type' => 'hdd',
                'smart_available' => true,
                'reallocated_sector_count' => 2,
            ],
        ], '2026-04-21 10:00:00'), 'scan-2.json', 'test');

        $latest = StorageHealthObservation::query()->orderByDesc('observed_at')->first();

        $this->assertSame('critical', $latest?->risk_level);
        $this->assertStringContainsString('increased', implode(' ', $latest?->risk_reasons ?? []));
    }

    public function test_missing_previous_disk_is_flagged_as_critical_when_current_storage_inventory_is_available(): void
    {
        Storage::fake('local');
        $ingest = app(InventoryIngestService::class);

        $ingest->ingestJsonPayload($this->payload([
            [
                'disk_key' => 'serial:SSD001',
                'disk_model' => 'System SSD',
                'disk_serial' => 'SSD001',
                'disk_type' => 'ssd',
                'smart_available' => true,
            ],
            [
                'disk_key' => 'serial:HDD001',
                'disk_model' => 'Data HDD',
                'disk_serial' => 'HDD001',
                'disk_type' => 'hdd',
                'smart_available' => true,
            ],
        ], '2026-04-20 10:00:00'), 'scan-1.json', 'test');

        $ingest->ingestJsonPayload($this->payload([
            [
                'disk_key' => 'serial:SSD001',
                'disk_model' => 'System SSD',
                'disk_serial' => 'SSD001',
                'disk_type' => 'ssd',
                'smart_available' => true,
            ],
        ], '2026-04-21 10:00:00'), 'scan-2.json', 'test');

        $this->assertDatabaseHas('storage_health_observations', [
            'disk_key' => 'serial:HDD001',
            'risk_level' => 'critical',
            'source_method' => 'inventory_trend',
        ]);
    }

    public function test_missing_external_usb_disk_is_not_flagged_as_critical(): void
    {
        Storage::fake('local');
        $ingest = app(InventoryIngestService::class);

        $ingest->ingestJsonPayload($this->payload([
            [
                'disk_key' => 'serial:SSD001',
                'disk_model' => 'System SSD',
                'disk_serial' => 'SSD001',
                'disk_type' => 'ssd',
                'smart_available' => true,
            ],
            [
                'disk_key' => 'serial:USB001',
                'disk_model' => 'ASMT 2105 USB Device',
                'disk_serial' => 'USB001',
                'disk_type' => 'external hard disk media',
                'interface_type' => 'USB',
                'smart_available' => true,
            ],
        ], '2026-04-20 10:00:00'), 'scan-1.json', 'test');

        $ingest->ingestJsonPayload($this->payload([
            [
                'disk_key' => 'serial:SSD001',
                'disk_model' => 'System SSD',
                'disk_serial' => 'SSD001',
                'disk_type' => 'ssd',
                'smart_available' => true,
            ],
        ], '2026-04-21 10:00:00'), 'scan-2.json', 'test');

        $this->assertDatabaseMissing('storage_health_observations', [
            'disk_key' => 'serial:USB001',
            'risk_level' => 'critical',
            'source_method' => 'inventory_trend',
        ]);
    }

    private function payload(array $storageHealth, string $scanTime = '2026-04-20 10:00:00'): array
    {
        return [
            'asset_code' => 'IT-WS-009',
            'user_name' => 'operator',
            'manufacturer' => 'Dell',
            'model' => 'OptiPlex',
            'system_uuid' => 'UUID-STORAGE-001',
            'motherboard_serial' => 'MB-STORAGE-001',
            'mac_address' => 'AA-BB-CC-DD-EE-09',
            'cpu' => 'Intel Core',
            'ram_gb' => '16',
            'disk' => 'Disk',
            'disk_detail' => 'Disk detail',
            'scan_time' => $scanTime,
            'storage_health_json' => json_encode($storageHealth, JSON_UNESCAPED_SLASHES),
        ];
    }
}
