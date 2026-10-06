<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DownloadsAdminOnlyTest extends TestCase
{
    use RefreshDatabase;

    public function test_viewer_cannot_list_or_download_kits_and_does_not_see_the_menu_item(): void
    {
        $viewer = User::factory()->create(['role' => 'viewer']);

        $this->actingAs($viewer)->get(route('admin.downloads.index'))->assertForbidden();
        $this->actingAs($viewer)->get(route('admin.downloads.show', 'any-kit.zip'))->assertForbidden();
        $this->actingAs($viewer)->get(route('admin.dashboard'))->assertOk()->assertDontSee(route('admin.downloads.index'), false);
    }

    public function test_admin_can_open_downloads_and_sees_the_menu_item(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->get(route('admin.downloads.index'))->assertOk();
        $this->actingAs($admin)->get(route('admin.dashboard'))->assertOk()->assertSee(route('admin.downloads.index'), false);
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get(route('admin.downloads.index'))->assertRedirect();
    }
}
