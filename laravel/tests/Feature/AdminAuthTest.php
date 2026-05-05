<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AdminAuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_pages_redirect_guests_to_login(): void
    {
        $this->get('/')->assertRedirect('/login');
        $this->get('/devices')->assertRedirect('/login');
    }

    public function test_admin_can_login_and_view_dashboard(): void
    {
        User::query()->create([
            'name' => 'Inventory Admin',
            'email' => 'admin@tvri.local',
            'password' => Hash::make('password'),
        ]);

        $this->post('/login', [
            'email' => 'admin@tvri.local',
            'password' => 'password',
        ])->assertRedirect('/');

        $this->get('/')->assertOk()->assertSee('Dashboard');
    }
}
