<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Inventory\InventoryIngestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ReportExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_admin_can_export_device_and_change_reports(): void
    {
        Storage::fake('local');
        $this->actingAs(User::query()->create([
            'name' => 'Inventory Admin',
            'email' => 'admin@tvri.local',
            'password' => Hash::make('password'),
        ]));

        $ingest = app(InventoryIngestService::class);
        $ingest->ingestCsvText((string) $this->fixtureContents('sample_data/sample_scan_1.csv'), 'sample_scan_1.csv', 'test_import');
        $ingest->ingestCsvText((string) $this->fixtureContents('sample_data/sample_scan_2.csv'), 'sample_scan_2.csv', 'test_import');

        $devicesResponse = $this->get('/reports/devices/export')
            ->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8');

        $devicesCsv = $this->streamedContent($devicesResponse->baseResponse);
        $this->assertStringContainsString('asset_code', $devicesCsv);
        $this->assertStringContainsString('IT-WS-001', $devicesCsv);

        $changesResponse = $this->get('/reports/changes/export?department=IT')->assertOk();
        $changesCsv = $this->streamedContent($changesResponse->baseResponse);
        $this->assertStringContainsString('severity', $changesCsv);
        $this->assertStringContainsString('ram_gb', $changesCsv);
    }

    private function streamedContent($response): string
    {
        ob_start();
        $response->sendContent();

        return (string) ob_get_clean();
    }
}
