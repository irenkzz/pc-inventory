<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\DeviceScan;
use App\Models\User;
use App\Services\Inventory\InventoryIngestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ExtraSecurityFieldsTest extends TestCase
{
    use RefreshDatabase;

    private const BASE_HEADERS = 'Asset_Code,Manufacturer,Model,Serial_No,System_UUID,MAC_Address,Scan_Time';
    private const BASE_ROW = 'IT-EXTRA-001,Dell,OptiPlex,SN-EXTRA-001,11111111-2222-3333-4444-555555555555,00-11-22-33-44-66,2026-05-01T08:00:00';

    public function test_csv_with_extra_columns_stores_them_in_snapshot_json(): void
    {
        Storage::fake('local');
        $csv = self::BASE_HEADERS . ",Battery_Health_Percent,Hotfix_Count,Last_Hotfix_Date,BitLocker_System_Drive,TPM_Enabled,TPM_Spec_Version,Installed_Software_Count\n"
            . self::BASE_ROW . ",87,42,2026-04-10,on,true,2.0,118\n";

        $result = app(InventoryIngestService::class)->ingestCsvText($csv);

        $json = DeviceScan::query()->findOrFail($result[0]['scan_id'])->snapshot->snapshot_json;
        $this->assertSame('87', $json['battery_health_percent']);
        $this->assertSame('42', $json['hotfix_count']);
        $this->assertSame('2026-04-10', $json['last_hotfix_date']);
        $this->assertSame('on', $json['bitlocker_system_drive']);
        $this->assertSame('true', $json['tpm_enabled']);
        $this->assertSame('2.0', $json['tpm_spec_version']);
        $this->assertSame('118', $json['installed_software_count']);
    }

    public function test_old_csv_without_extra_columns_still_ingests_and_blank_stays_blank(): void
    {
        Storage::fake('local');
        $service = app(InventoryIngestService::class);

        $old = $service->ingestCsvText(self::BASE_HEADERS . "\n" . self::BASE_ROW . "\n");
        $this->assertSame('created', $old[0]['status']);
        $this->assertSame('duplicate', $service->ingestCsvText(self::BASE_HEADERS . "\n" . self::BASE_ROW . "\n")[0]['status']);

        $blank = $service->ingestCsvText(self::BASE_HEADERS . ",Battery_Health_Percent,Hotfix_Count\n"
            . str_replace('08:00:00', '09:00:00', self::BASE_ROW) . ",,\n");
        $json = DeviceScan::query()->findOrFail($blank[0]['scan_id'])->snapshot->snapshot_json;
        $this->assertSame('', $json['battery_health_percent']);
        $this->assertSame('', $json['hotfix_count']);
        $this->assertSame(1, Device::query()->count());
    }

    public function test_device_page_shows_values_and_not_reported_placeholder(): void
    {
        Storage::fake('local');
        $user = User::query()->create(['name' => 'A', 'email' => 'a@tvri.local', 'password' => Hash::make('password')]);
        $csv = self::BASE_HEADERS . ",Battery_Health_Percent,BitLocker_System_Drive\n" . self::BASE_ROW . ",87,on\n";
        $result = app(InventoryIngestService::class)->ingestCsvText($csv);

        $this->actingAs($user)
            ->get(route('admin.devices.show', Device::query()->findOrFail($result[0]['device_id'])))
            ->assertOk()
            ->assertSee('Security &amp; health', false)
            ->assertSee('87%')
            ->assertSee('not reported');
    }
}
