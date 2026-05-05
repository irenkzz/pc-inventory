<?php

namespace Tests\Unit;

use App\Services\Security\DirectRunnerAuthService;
use App\Services\Security\SiteTokenStore;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Config;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class DirectRunnerAuthServiceTest extends TestCase
{
    public function test_valid_direct_runner_token_is_accepted(): void
    {
        Config::set('inventory.site_tokens_json', json_encode([
            'SITE-HQ' => [
                'token' => 'direct-runner-token',
                'token_type' => SiteTokenStore::TYPE_DIRECT_RUNNER,
                'last_used_at' => '2026-05-01T00:00:00+00:00',
                'last_used_ip' => '192.168.100.10',
            ],
        ]));

        $context = app(DirectRunnerAuthService::class)->authenticate($this->request('SITE-HQ', 'direct-runner-token'));

        $this->assertSame('SITE-HQ', $context['site_code']);
        $this->assertSame(SiteTokenStore::TYPE_DIRECT_RUNNER, $context['token_type']);
        $this->assertSame('2026-05-01T00:00:00+00:00', $context['token_metadata']['last_used_at']);
        $this->assertSame('192.168.100.10', $context['token_metadata']['last_used_ip']);
        $this->assertArrayNotHasKey('token', $context['token_metadata']);
    }

    public function test_collector_token_is_rejected(): void
    {
        Config::set('inventory.site_tokens_json', json_encode([
            'SITE-HQ' => [
                'token' => 'collector-token',
                'token_type' => SiteTokenStore::TYPE_COLLECTOR,
            ],
        ]));

        $this->assertUnauthorized(fn () => app(DirectRunnerAuthService::class)
            ->authenticate($this->request('SITE-HQ', 'collector-token')));
    }

    public function test_revoked_direct_runner_token_is_rejected(): void
    {
        Config::set('inventory.site_tokens_json', json_encode([
            'SITE-HQ' => [
                'token' => 'revoked-direct-runner-token',
                'token_type' => SiteTokenStore::TYPE_DIRECT_RUNNER,
                'revoked_at' => '2026-05-01T00:00:00+00:00',
            ],
        ]));

        $this->assertUnauthorized(fn () => app(DirectRunnerAuthService::class)
            ->authenticate($this->request('SITE-HQ', 'revoked-direct-runner-token')));
    }

    public function test_wrong_site_is_rejected(): void
    {
        Config::set('inventory.site_tokens_json', json_encode([
            'SITE-HQ' => [
                'token' => 'direct-runner-token',
                'token_type' => SiteTokenStore::TYPE_DIRECT_RUNNER,
            ],
        ]));

        $this->assertUnauthorized(fn () => app(DirectRunnerAuthService::class)
            ->authenticate($this->request('SITE-BRANCH', 'direct-runner-token')));
    }

    public function test_missing_site_is_rejected(): void
    {
        Config::set('inventory.site_tokens_json', json_encode([
            'SITE-HQ' => [
                'token' => 'direct-runner-token',
                'token_type' => SiteTokenStore::TYPE_DIRECT_RUNNER,
            ],
        ]));

        $request = Request::create('/api/future/direct-runner', 'POST', [], [], [], [
            'HTTP_X_SITE_TOKEN' => 'direct-runner-token',
        ]);

        $this->assertUnauthorized(fn () => app(DirectRunnerAuthService::class)->authenticate($request));
    }

    private function request(string $siteCode, string $token): Request
    {
        return Request::create('/api/future/direct-runner', 'POST', [], [], [], [
            'HTTP_X_SITE_ID' => $siteCode,
            'HTTP_X_SITE_TOKEN' => $token,
        ]);
    }

    private function assertUnauthorized(callable $callback): void
    {
        try {
            $callback();
            $this->fail('Expected request to be rejected.');
        } catch (HttpException $exception) {
            $this->assertSame(Response::HTTP_UNAUTHORIZED, $exception->getStatusCode());
        }
    }
}
