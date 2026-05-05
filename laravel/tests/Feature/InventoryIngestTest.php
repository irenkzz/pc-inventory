<?php

namespace Tests\Feature;

use App\Models\ChangeLog;
use App\Models\Device;
use App\Models\DeviceScan;
use App\Models\RawFile;
use App\Models\Runner;
use App\Models\RunnerCommand;
use App\Services\Inventory\InventoryIngestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class InventoryIngestTest extends TestCase
{
    use RefreshDatabase;

    public function test_sample_csvs_merge_into_one_device_and_record_changes(): void
    {
        Storage::fake('local');

        $ingest = app(InventoryIngestService::class);
        $first = $this->fixtureContents('sample_data/sample_scan_1.csv');
        $second = $this->fixtureContents('sample_data/sample_scan_2.csv');

        $firstResult = $ingest->ingestCsvText((string) $first, 'sample_scan_1.csv', 'test_import');
        $secondResult = $ingest->ingestCsvText((string) $second, 'sample_scan_2.csv', 'test_import');

        $this->assertSame('created', $firstResult[0]['status']);
        $this->assertSame('updated', $secondResult[0]['status']);
        $this->assertSame('strong_identity_match', $secondResult[0]['match_reason']);

        $this->assertSame(1, Device::query()->count());
        $this->assertSame(2, DeviceScan::query()->count());
        $this->assertSame(6, ChangeLog::query()->count());
        $this->assertSame(2, RawFile::query()->count());

        $this->assertDatabaseHas('devices', [
            'current_asset_code' => 'IT-WS-001',
            'current_user_name' => 'budi',
            'current_department' => 'IT',
            'current_site' => 'Bhayangkara',
            'current_location' => '',
            'current_room' => '',
        ]);

        $this->assertDatabaseHas('change_log', [
            'field_name' => 'ram_gb',
            'severity' => 'medium',
            'old_value' => '16',
            'new_value' => '32',
        ]);

        $this->assertDatabaseHas('change_log', [
            'field_name' => 'present_peripherals',
            'severity' => 'minor',
        ]);
    }

    public function test_duplicate_raw_hash_does_not_create_another_scan(): void
    {
        Storage::fake('local');

        $ingest = app(InventoryIngestService::class);
        $csv = (string) $this->fixtureContents('sample_data/sample_scan_1.csv');

        $created = $ingest->ingestCsvText($csv, 'sample_scan_1.csv', 'test_import');
        $duplicate = $ingest->ingestCsvText($csv, 'sample_scan_1.csv', 'test_import');

        $this->assertSame('created', $created[0]['status']);
        $this->assertSame('duplicate', $duplicate[0]['status']);
        $this->assertSame(1, Device::query()->count());
        $this->assertSame(1, DeviceScan::query()->count());
    }

    public function test_ingest_reconciles_runner_when_hostname_changes_on_same_device(): void
    {
        Storage::fake('local');

        $ingest = app(InventoryIngestService::class);

        $basePayload = [
            'asset_code' => 'DESKTOP-I5BLORA',
            'manufacturer' => 'Gigabyte Technology Co., Ltd.',
            'model' => 'Z590 UD',
            'system_uuid' => '035E02D8-04D3-05AE-3F06-BC0700080009',
            'mac_address' => 'D8-5E-D3-AE-3F-BC',
            'cpu' => 'Intel Core i5',
            'ram_gb' => '16',
            'disk' => 'SSD',
            'gpu' => 'Intel',
            'scan_time' => '2026-04-28 18:48:26',
        ];

        $ingest->ingestJsonPayload($basePayload, 'desktop-old.json', 'test_import', [
            'runner_id' => 'DESKTOP-I5BLORA',
            'hostname' => 'DESKTOP-I5BLORA',
            'site_id' => 'SITE-HQ',
            'runner_version' => '1.0.18',
        ]);

        RunnerCommand::query()->create([
            'runner_id' => 'DESKTOP-I5BLORA',
            'site_id' => 'SITE-HQ',
            'command_type' => 'scan_now',
            'status' => 'completed',
            'requested_at' => now(),
        ]);

        $updated = $basePayload;
        $updated['asset_code'] = 'MCR-CHARGEN';
        $updated['scan_time'] = '2026-04-30 13:08:09';

        $result = $ingest->ingestJsonPayload($updated, 'mcr-chargen.json', 'test_import', [
            'runner_id' => 'MCR-CHARGEN',
            'hostname' => 'MCR-CHARGEN',
            'site_id' => 'SITE-HQ',
            'runner_version' => '1.0.18',
        ]);

        $this->assertSame('updated', $result['status']);
        $this->assertSame(1, Device::query()->count());
        $this->assertSame(1, Runner::query()->count());
        $this->assertDatabaseMissing('runners', ['runner_id' => 'DESKTOP-I5BLORA']);
        $this->assertDatabaseHas('runners', [
            'runner_id' => 'MCR-CHARGEN',
            'hostname' => 'MCR-CHARGEN',
        ]);
        $this->assertDatabaseHas('runner_commands', [
            'runner_id' => 'MCR-CHARGEN',
            'command_type' => 'scan_now',
        ]);
        $this->assertContains('DESKTOP-I5BLORA', Runner::query()->where('runner_id', 'MCR-CHARGEN')->firstOrFail()->raw_state_json['previous_runner_ids']);
    }
}
