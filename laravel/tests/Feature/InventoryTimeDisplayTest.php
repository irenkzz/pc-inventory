<?php

namespace Tests\Feature;

use App\Models\Runner;
use App\Models\CollectorSite;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class InventoryTimeDisplayTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_pages_show_configured_inventory_time_zone(): void
    {
        config([
            'app.locale' => 'en_US',
            'app.timezone' => 'Asia/Tokyo',
            'inventory.display_locale' => 'en_US',
            'inventory.display_timezone' => null,
            'inventory.display_timezone_label' => null,
            'inventory.display_datetime_format' => null,
        ]);

        User::query()->create([
            'name' => 'Inventory Admin',
            'email' => 'admin@tvri.local',
            'password' => Hash::make('password'),
        ]);

        CollectorSite::query()->create([
            'site_id' => 'SITE-HQ',
            'site_name' => 'Head Office',
        ]);

        Runner::query()->create([
            'runner_id' => 'IT-ADMIN',
            'site_id' => 'SITE-HQ',
            'hostname' => 'IT-ADMIN',
            'runner_version' => '1.0.12',
            'last_seen_at' => '2026-04-22 11:00:00',
            'last_successful_inventory_at' => '2026-04-22 10:30:00',
            'last_inventory_status' => 'success',
            'last_upload_status' => 'uploaded',
        ]);

        $this->actingAs(User::query()->firstOrFail())
            ->get('/')
            ->assertOk()
            ->assertSee('Locale: en_US')
            ->assertSee('Time zone: Asia/Tokyo (JST)')
            ->assertSee('Apr 22, 2026 11:00 AM JST')
            ->assertSee('Apr 22, 2026 10:30 AM JST');
    }

    public function test_admin_pages_can_follow_browser_indonesia_regional_locale(): void
    {
        config([
            'app.locale' => 'en_US',
            'app.timezone' => 'Asia/Jayapura',
            'inventory.display_locale' => null,
            'inventory.display_timezone' => 'Asia/Jayapura',
            'inventory.display_timezone_label' => 'WIT',
            'inventory.display_datetime_format' => null,
        ]);

        User::query()->create([
            'name' => 'Inventory Admin',
            'email' => 'admin@tvri.local',
            'password' => Hash::make('password'),
        ]);

        CollectorSite::query()->create([
            'site_id' => 'SITE-HQ',
            'site_name' => 'Bhayangkara',
        ]);

        Runner::query()->create([
            'runner_id' => 'IT-ADMIN',
            'site_id' => 'SITE-HQ',
            'hostname' => 'IT-ADMIN',
            'runner_version' => '1.0.12',
            'last_seen_at' => '2026-04-22 11:00:00',
            'last_successful_inventory_at' => '2026-04-22 10:30:00',
            'last_inventory_status' => 'success',
            'last_upload_status' => 'uploaded',
        ]);

        $this->actingAs(User::query()->firstOrFail())
            ->withHeader('Accept-Language', 'en-ID,en-US;q=0.9,en;q=0.8')
            ->get('/')
            ->assertOk()
            ->assertSee('Locale: en_ID')
            ->assertSee('22 Apr 2026 11:00 WIT')
            ->assertSee('22 Apr 2026 10:30 WIT');
    }
}
