<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\RawFile;
use App\Models\User;
use App\Services\Inventory\InventoryIngestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class RawEvidenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_raw_evidence_index_lists_archived_files_and_link_status(): void
    {
        Storage::fake('local');

        $this->ingestSampleCsv();

        $this->actingAs($this->adminUser())
            ->get('/raw-evidence')
            ->assertOk()
            ->assertSee('Raw Evidence')
            ->assertSee('sample_scan_1.csv')
            ->assertSee('Linked')
            ->assertSee('IT-WS-001');
    }

    public function test_raw_evidence_detail_shows_archive_record_linked_scan_and_snapshot(): void
    {
        Storage::fake('local');

        $this->ingestSampleCsv();
        $rawFile = RawFile::query()->firstOrFail();

        $this->actingAs($this->adminUser())
            ->get(route('admin.raw-evidence.show', $rawFile))
            ->assertOk()
            ->assertSee('Archive Record')
            ->assertSee('Linked Scan')
            ->assertSee('sample_scan_1.csv')
            ->assertSee('IT-WS-001')
            ->assertSee('Normalized Snapshot');
    }

    public function test_device_scan_timeline_links_back_to_raw_evidence(): void
    {
        Storage::fake('local');

        $this->ingestSampleCsv();
        $device = Device::query()->firstOrFail();
        $rawFile = RawFile::query()->firstOrFail();

        $this->actingAs($this->adminUser())
            ->get(route('admin.devices.show', $device))
            ->assertOk()
            ->assertSee('Scan Timeline')
            ->assertSee('sample_scan_1.csv')
            ->assertSee(route('admin.raw-evidence.show', $rawFile), false);
    }

    private function ingestSampleCsv(): void
    {
        app(InventoryIngestService::class)->ingestCsvText(
            (string) $this->fixtureContents('sample_data/sample_scan_1.csv'),
            'sample_scan_1.csv',
            'test_import',
        );
    }

    private function adminUser(): User
    {
        return User::query()->create([
            'name' => 'Inventory Admin',
            'email' => 'admin@tvri.local',
            'password' => Hash::make('password'),
        ]);
    }
}
