<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Hash;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

class HealthAndDoctorTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_health_endpoint_reports_ok_database_status(): void
    {
        $this->getJson('/health')
            ->assertOk()
            ->assertJson([
                'status' => 'ok',
                'database' => 'ok',
            ]);
    }

    public function test_doctor_command_reports_core_readiness(): void
    {
        User::query()->create([
            'name' => 'Inventory Admin',
            'email' => 'admin@tvri.local',
            'password' => Hash::make('password'),
        ]);

        $this->artisan('inventory:doctor', ['--json' => true])
            ->expectsOutputToContain('"name": "Laravel version"')
            ->assertSuccessful();
    }

    public function test_doctor_fails_when_site_tokens_are_required_but_missing(): void
    {
        $tokenFile = storage_path('framework/testing/missing_doctor_site_tokens.json');
        if (is_file($tokenFile)) {
            unlink($tokenFile);
        }

        Config::set('inventory.require_site_tokens', true);
        Config::set('inventory.site_tokens_file', $tokenFile);

        $this->withoutMockingConsoleOutput();

        $buffer = new BufferedOutput();
        $exitCode = Artisan::call('inventory:doctor', ['--json' => true], $buffer);
        $output = $buffer->fetch();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('"status": "fail"', $output);
        $this->assertStringContainsString('Site tokens are required', $output);
    }

    public function test_production_doctor_fails_on_local_settings(): void
    {
        Config::set('app.env', 'local');
        Config::set('app.debug', true);
        Config::set('app.key', '');
        Config::set('app.url', 'http://localhost');
        Config::set('inventory.require_site_tokens', false);
        Config::set('inventory.site_tokens_file', storage_path('framework/testing/missing_production_site_tokens.json'));

        $this->withoutMockingConsoleOutput();

        $buffer = new BufferedOutput();
        $exitCode = Artisan::call('inventory:doctor', ['--json' => true, '--production' => true], $buffer);
        $output = $buffer->fetch();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('"status": "fail"', $output);
        $this->assertStringContainsString('APP_ENV must be production', $output);
        $this->assertStringContainsString('APP_KEY is empty', $output);
        $this->assertStringContainsString('APP_DEBUG=true', $output);
        $this->assertStringContainsString('APP_URL is local', $output);
        $this->assertStringContainsString('Site tokens are required', $output);
    }

    public function test_production_doctor_requires_strict_site_token_mode_even_when_tokens_exist(): void
    {
        $tokenFile = storage_path('framework/testing/production_site_tokens.json');
        if (! is_dir(dirname($tokenFile))) {
            mkdir(dirname($tokenFile), 0777, true);
        }
        file_put_contents($tokenFile, json_encode(['SITE-HQ' => 'real-token']));

        Config::set('app.env', 'production');
        Config::set('app.debug', false);
        Config::set('app.key', 'base64:' . str_repeat('a', 44));
        Config::set('app.url', 'https://inventory.example.local');
        Config::set('inventory.require_site_tokens', false);
        Config::set('inventory.site_tokens_file', $tokenFile);

        $this->withoutMockingConsoleOutput();

        $buffer = new BufferedOutput();
        $exitCode = Artisan::call('inventory:doctor', ['--json' => true, '--production' => true], $buffer);
        $output = $buffer->fetch();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Production requires INVENTORY_REQUIRE_SITE_TOKENS=true', $output);
    }

    public function test_site_token_listing_masks_configured_tokens(): void
    {
        $tokenFile = storage_path('framework/testing/list_site_tokens.json');
        if (! is_dir(dirname($tokenFile))) {
            mkdir(dirname($tokenFile), 0777, true);
        }
        file_put_contents($tokenFile, json_encode(['SITE-HQ' => 'abcd12345678wxyz']));

        Config::set('inventory.site_tokens_file', $tokenFile);

        $this->withoutMockingConsoleOutput();

        $buffer = new BufferedOutput();
        $exitCode = Artisan::call('inventory:site-tokens', [], $buffer);
        $output = $buffer->fetch();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Source: ' . $tokenFile, $output);
        $this->assertStringContainsString('SITE-HQ', $output);
        $this->assertStringContainsString('abcd********wxyz', $output);
        $this->assertStringNotContainsString('abcd12345678wxyz', $output);
    }
}
