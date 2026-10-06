<?php

namespace Tests\Feature;

use App\Services\Security\SiteTokenVerifier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

class SiteTokenLifecycleTest extends TestCase
{

    private string $file;

    protected function setUp(): void
    {
        parent::setUp();
        // Not RefreshDatabase: its artisan() binding swallows later Artisan::call output buffers.
        Artisan::call('migrate:fresh');
        $this->file = storage_path('framework/testing/lifecycle_tokens.json');
        @mkdir(dirname($this->file), 0777, true);
        Config::set('inventory.site_tokens_file', $this->file);
        Config::set('inventory.site_tokens_json', '');
    }

    private function writeTokens(array $data): void
    {
        file_put_contents($this->file, json_encode($data));
    }

    private function stored(): array
    {
        return json_decode((string) file_get_contents($this->file), true);
    }

    private function verify(string $token, string $site = 'S1'): bool
    {
        $request = Request::create('/x', 'POST', [], [], [], ['REMOTE_ADDR' => '10.1.2.3']);
        $request->headers->set('X-Site-Id', $site);
        $request->headers->set('X-Site-Token', $token);
        try {
            app(SiteTokenVerifier::class)->verify($request);

            return true;
        } catch (HttpException $e) {
            $this->assertSame(401, $e->getStatusCode());

            return false;
        }
    }

    public function test_expired_token_is_rejected_and_future_accepted(): void
    {
        $this->writeTokens(['S1' => ['token' => 'tok', 'expires_at' => now()->subMinute()->toIso8601String()]]);
        $this->assertFalse($this->verify('tok'));

        $this->writeTokens(['S1' => ['token' => 'tok', 'expires_at' => now()->addDay()->toIso8601String()]]);
        $this->assertTrue($this->verify('tok'));
    }

    public function test_last_used_is_recorded_and_throttled(): void
    {
        $this->writeTokens(['S1' => 'tok']);
        $this->assertTrue($this->verify('tok'));
        $first = $this->stored()['S1'];
        $this->assertSame('tok', $first['token']);
        $this->assertSame('10.1.2.3', $first['last_used_ip']);

        $this->assertTrue($this->verify('tok'));
        $this->assertSame($first['last_used_at'], $this->stored()['S1']['last_used_at']);

        $first['last_used_at'] = now()->subMinutes(10)->toIso8601String();
        $this->writeTokens(['S1' => $first]);
        $this->assertTrue($this->verify('tok'));
        $this->assertNotSame($first['last_used_at'], $this->stored()['S1']['last_used_at']);
    }

    public function test_env_store_is_skipped_silently(): void
    {
        Config::set('inventory.site_tokens_json', json_encode(['S1' => 'tok']));
        $this->assertTrue($this->verify('tok'));
    }

    public function test_revoke_rejects_and_rotate_replaces_token(): void
    {
        $this->writeTokens(['S1' => ['collector' => 'old-collector', 'direct_runner' => 'old-direct']]);

        $this->assertSame(0, Artisan::call('inventory:revoke-site-token', ['site_id' => 'S1', '--type' => 'direct_runner']));
        $this->assertSame('old-collector', $this->stored()['S1']['collector']);
        $this->assertFalse($this->verifyTyped('old-direct', 'direct_runner'));

        $buf = new BufferedOutput();
        $this->assertSame(0, Artisan::call('inventory:rotate-site-token', ['site_id' => 'S1', '--type' => 'collector', '--reveal' => true], $buf));
        $new = $this->stored()['S1']['collector']['token'];
        $this->assertStringContainsString($new, $buf->fetch());
        $this->assertNotSame('old-collector', $new);
        $this->assertFalse($this->verify('old-collector'));
        $this->assertTrue($this->verify($new));

        $this->assertSame(1, Artisan::call('inventory:revoke-site-token', ['site_id' => 'NOPE']));
    }

    private function verifyTyped(string $token, string $type): bool
    {
        $request = Request::create('/x');
        $request->headers->set('X-Site-Id', 'S1');
        $request->headers->set('X-Site-Token', $token);
        try {
            app(SiteTokenVerifier::class)->verify($request, $type);

            return true;
        } catch (HttpException) {
            return false;
        }
    }

    public function test_rotate_without_reveal_and_listing_print_no_secret(): void
    {
        $this->writeTokens(['S1' => ['token' => 'listing-secret-9999', 'expires_at' => '2099-01-01T00:00:00+00:00']]);

        $buf = new BufferedOutput();

        Artisan::call('inventory:site-tokens', [], $buf);
        $out = $buf->fetch();
        $this->assertStringNotContainsString('listing-secret-9999', $out);
        $this->assertStringContainsString('2099-01-01', $out);

        $buf = new BufferedOutput();

        Artisan::call('inventory:rotate-site-token', ['site_id' => 'S1'], $buf);
        $this->assertStringNotContainsString($this->stored()['S1']['token'], $buf->fetch());
    }
}
