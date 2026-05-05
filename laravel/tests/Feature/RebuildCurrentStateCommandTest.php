<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Services\Inventory\InventoryIngestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class RebuildCurrentStateCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_rebuild_current_state_repairs_device_projection_from_latest_snapshot(): void
    {
        Storage::fake('local');

        $ingest = app(InventoryIngestService::class);
        $ingest->ingestCsvText((string) $this->fixtureContents('sample_data/sample_scan_1.csv'), 'sample_scan_1.csv', 'test_import');
        $ingest->ingestCsvText((string) $this->fixtureContents('sample_data/sample_scan_2.csv'), 'sample_scan_2.csv', 'test_import');

        $device = Device::query()->firstOrFail();
        $device->forceFill([
            'current_user_name' => 'broken',
            'current_room' => 'broken',
        ])->save();

        $this->artisan('inventory:rebuild-current-state')
            ->expectsOutput('Updated devices: 1')
            ->expectsOutput('Skipped without snapshots: 0')
            ->assertSuccessful();

        $device->refresh();

        $this->assertSame('budi', $device->current_user_name);
        $this->assertSame('', $device->current_room);
    }
}
