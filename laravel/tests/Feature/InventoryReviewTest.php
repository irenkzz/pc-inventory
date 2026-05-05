<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\DeviceIdentity;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class InventoryReviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_review_page_lists_devices_that_need_cleanup(): void
    {
        $user = $this->adminUser();

        $device = Device::query()->create([
            'device_uid' => 'review-device-1',
            'first_seen_at' => '2026-04-22 08:00:00',
            'last_seen_at' => '2026-04-22 08:00:00',
            'current_asset_code' => 'DESKTOP-ABC123',
            'current_department' => '',
            'current_site' => 'Bhayangkara',
            'current_user_name' => '',
            'manufacturer' => 'Dell',
            'model' => 'OptiPlex',
        ]);

        DeviceIdentity::query()->create([
            'device_id' => $device->id,
            'identity_type' => 'asset_code',
            'identity_value' => 'DESKTOP-ABC123',
            'weight' => 35,
            'first_seen_at' => '2026-04-22 08:00:00',
            'last_seen_at' => '2026-04-22 08:00:00',
        ]);

        $this->actingAs($user)
            ->get('/inventory-review')
            ->assertOk()
            ->assertSee('Review Queue')
            ->assertSee('DESKTOP-ABC123')
            ->assertSee('Department')
            ->assertSee('User')
            ->assertSee('Asset name')
            ->assertSee('Identity');
    }

    public function test_review_issue_filter_limits_results(): void
    {
        $user = $this->adminUser();

        Device::query()->create([
            'device_uid' => 'missing-department-device',
            'first_seen_at' => '2026-04-22 08:00:00',
            'last_seen_at' => '2026-04-22 08:00:00',
            'current_asset_code' => 'DOK-PC-01',
            'current_department' => '',
            'current_site' => 'Bhayangkara',
            'current_user_name' => 'operator',
        ]);

        Device::query()->create([
            'device_uid' => 'complete-device',
            'first_seen_at' => '2026-04-22 08:00:00',
            'last_seen_at' => '2026-04-22 08:00:00',
            'current_asset_code' => 'IT-ADMIN',
            'current_department' => 'IT',
            'current_site' => 'Bhayangkara',
            'current_user_name' => 'admin',
        ]);

        $this->actingAs($user)
            ->get('/inventory-review?issue=missing_department')
            ->assertOk()
            ->assertSee('DOK-PC-01')
            ->assertDontSee('IT-ADMIN');
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
