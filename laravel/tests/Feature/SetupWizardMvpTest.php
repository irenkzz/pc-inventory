<?php

namespace Tests\Feature;

use App\Models\CollectorSite;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class SetupWizardMvpTest extends TestCase
{
    use RefreshDatabase;

    private string $downloadsPath;

    private string $downloadsRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->downloadsPath = 'framework/testing/setup-wizard-mvp-' . str_replace('\\', '-', $this->name());
        $this->downloadsRoot = storage_path('app/' . $this->downloadsPath);
        File::deleteDirectory($this->downloadsRoot);
        File::ensureDirectoryExists($this->downloadsRoot);

        Config::set('app.url', 'https://inventory-pilot.internal.lan');
        Config::set('app.key', 'base64:' . base64_encode(str_repeat('s', 32)));
        Config::set('inventory.downloads_path', $this->downloadsPath);
    }

    protected function tearDown(): void
    {
        if (isset($this->downloadsRoot)) {
            File::deleteDirectory($this->downloadsRoot);
        }

        parent::tearDown();
    }

    public function test_unauthenticated_users_cannot_access_setup_wizard(): void
    {
        $this->get('/setup-wizard')->assertRedirect('/login');
    }

    public function test_authenticated_admin_can_view_setup_wizard_successfully(): void
    {
        $this->actingAs($this->adminUser())
            ->get('/setup-wizard')
            ->assertOk()
            ->assertSee('Portal Setup Wizard MVP')
            ->assertSee('Read-only')
            ->assertSee('not production cutover approval')
            ->assertSee('official packages/site kits are generated only on Supermicro');
    }

    public function test_setup_wizard_references_preflight_and_site_kit_audit_without_running_commands(): void
    {
        Artisan::shouldReceive('call')->never();
        Artisan::shouldReceive('queue')->never();

        $this->actingAs($this->adminUser())
            ->get('/setup-wizard')
            ->assertOk()
            ->assertSee('php artisan inventory:install-preflight')
            ->assertSee('php artisan inventory:direct-site-kit-audit')
            ->assertSee('This page does not run nested Artisan commands');
    }

    public function test_setup_wizard_shows_deployment_modes_and_command_semantics(): void
    {
        $this->actingAs($this->adminUser())
            ->get('/setup-wizard')
            ->assertOk()
            ->assertSee('Small/no-IT Direct HTTPS')
            ->assertSee('HQ/multi-PC collector-share')
            ->assertSee('Hybrid')
            ->assertSee('For one PC, small site, remote office, or no local collector/share.')
            ->assertSee('Collector-share remains main HQ/multi-PC mode.')
            ->assertSee('Polling means delivery.')
            ->assertSee('ACK means execution result.')
            ->assertSee('Direct repair_update remains blocked.');
    }

    public function test_setup_wizard_links_or_references_verification_pages_and_download_guidance(): void
    {
        $this->actingAs($this->adminUser())
            ->get('/setup-wizard')
            ->assertOk()
            ->assertSee('/runners', false)
            ->assertSee('/collectors', false)
            ->assertSee('/commands', false)
            ->assertSee('/downloads', false)
            ->assertSee('Package / Download Guidance')
            ->assertSee('Runner appears on runners page.')
            ->assertSee('Collector appears on collectors page.');
    }

    public function test_setup_wizard_does_not_create_users_sites_tokens_or_generated_artifacts(): void
    {
        Config::set('inventory.site_tokens_json', json_encode([
            'SITE-HQ' => [
                'collector' => 'collector-token-secret-not-shown',
                'direct_runner' => 'direct-token-secret-not-shown',
            ],
        ]));

        $user = $this->adminUser();
        $before = [
            'users' => DB::table('users')->count(),
            'collector_sites' => DB::table('collector_sites')->count(),
        ];
        $filesBefore = File::allFiles($this->downloadsRoot);

        $this->actingAs($user)
            ->get('/setup-wizard')
            ->assertOk()
            ->assertDontSee('collector-token-secret-not-shown')
            ->assertDontSee('direct-token-secret-not-shown');

        $this->assertSame($before['users'], DB::table('users')->count());
        $this->assertSame($before['collector_sites'], DB::table('collector_sites')->count());
        $this->assertSame(count($filesBefore), count(File::allFiles($this->downloadsRoot)));
        $this->assertFalse(is_dir($this->downloadsRoot . '/site-kit-SITE-HQ'));
        $this->assertFalse(is_file($this->downloadsRoot . '/package.zip'));
    }

    public function test_setup_wizard_does_not_expose_app_key_env_values_or_token_like_values(): void
    {
        $encodedKey = base64_encode(str_repeat('k', 32));
        Config::set('app.key', 'base64:' . $encodedKey);
        Config::set('inventory.site_tokens_json', 'token-like-secret-value-not-for-ui');

        $this->actingAs($this->adminUser())
            ->get('/setup-wizard')
            ->assertOk()
            ->assertDontSee('do-not-print-this-app-key')
            ->assertDontSee($encodedKey)
            ->assertDontSee('token-like-secret-value-not-for-ui')
            ->assertDontSee('DB_PASSWORD')
            ->assertSee('raw `.env` values are not displayed.', false)
            ->assertSee('Never paste or expose token secrets')
            ->assertSee('APP_KEY values')
            ->assertSee('full runner GUIDs');
    }

    public function test_setup_wizard_counts_existing_site_runner_and_collector_state_read_only(): void
    {
        CollectorSite::query()->create(['site_id' => 'SITE-HQ', 'site_name' => 'HQ']);
        DB::table('collectors')->insert([
            'site_id' => 'SITE-HQ',
            'collector_name' => 'collector-01',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('runners')->insert([
            [
                'runner_id' => 'PC-DIRECT',
                'transport_mode' => 'direct_https',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'runner_id' => 'PC-COLLECTOR',
                'transport_mode' => 'collector_share',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $this->actingAs($this->adminUser())
            ->get('/setup-wizard')
            ->assertOk()
            ->assertSee('Collector sites')
            ->assertSee('Direct HTTPS runners')
            ->assertSee('Collector-share runners')
            ->assertSee('No sites, collector_sites, or tokens are created by this page.');
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
