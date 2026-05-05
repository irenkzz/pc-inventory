<?php

namespace Tests\Feature;

use App\Services\Security\SiteTokenStore;
use App\Services\Security\SiteTokenVerifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class ApiSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_collector_endpoints_reject_when_site_tokens_are_required_but_missing(): void
    {
        $tokenFile = storage_path('framework/testing/missing_site_tokens.json');
        if (is_file($tokenFile)) {
            unlink($tokenFile);
        }

        Config::set('inventory.require_site_tokens', true);
        Config::set('inventory.site_tokens_json', null);
        Config::set('inventory.site_tokens_file', $tokenFile);

        $this->postJson('/api/collector/status', [
            'collector_name' => 'hq-collector',
        ], [
            'X-Site-Id' => 'SITE-HQ',
            'X-Site-Token' => 'any-token',
        ])->assertUnauthorized();
    }

    public function test_collector_endpoints_reject_invalid_site_token_when_token_map_exists(): void
    {
        $tokenFile = storage_path('framework/testing/site_tokens.json');
        if (! is_dir(dirname($tokenFile))) {
            mkdir(dirname($tokenFile), 0777, true);
        }
        file_put_contents($tokenFile, json_encode(['SITE-HQ' => 'real-token']));
        Config::set('inventory.site_tokens_json', null);
        Config::set('inventory.site_tokens_file', $tokenFile);

        $this->postJson('/api/collector/status', [
            'collector_name' => 'hq-collector',
        ], [
            'X-Site-Id' => 'SITE-HQ',
            'X-Site-Token' => 'wrong-token',
        ])->assertUnauthorized();

        $this->postJson('/api/collector/status', [
            'collector_name' => 'hq-collector',
        ], [
            'X-Site-Id' => 'SITE-HQ',
            'X-Site-Token' => 'real-token',
        ])->assertOk();
    }

    public function test_collector_endpoints_reject_missing_site_id_when_token_map_exists(): void
    {
        Config::set('inventory.site_tokens_json', json_encode(['SITE-HQ' => 'real-token']));
        Config::set('inventory.require_site_tokens', true);

        $this->postJson('/api/collector/status', [
            'collector_name' => 'hq-collector',
        ], [
            'X-Site-Token' => 'real-token',
        ])->assertUnauthorized();
    }

    public function test_collector_endpoints_accept_config_backed_json_site_token(): void
    {
        Config::set('inventory.site_tokens_json', json_encode(['SITE-HQ' => 'json-token']));

        $this->postJson('/api/collector/status', [
            'collector_name' => 'hq-collector',
        ], [
            'X-Site-Id' => 'SITE-HQ',
            'X-Site-Token' => 'json-token',
        ])->assertOk();
    }

    public function test_collector_endpoint_accepts_typed_collector_token(): void
    {
        Config::set('inventory.site_tokens_json', json_encode([
            'SITE-HQ' => [
                'token' => 'typed-collector-token',
                'token_type' => SiteTokenStore::TYPE_COLLECTOR,
            ],
        ]));

        $this->postJson('/api/collector/status', [
            'collector_name' => 'hq-collector',
        ], [
            'X-Site-Id' => 'SITE-HQ',
            'X-Site-Token' => 'typed-collector-token',
        ])->assertOk();
    }

    public function test_collector_endpoint_rejects_direct_runner_token(): void
    {
        Config::set('inventory.site_tokens_json', json_encode([
            'SITE-HQ' => [
                [
                    'token' => 'collector-token',
                    'token_type' => SiteTokenStore::TYPE_COLLECTOR,
                ],
                [
                    'token' => 'direct-runner-token',
                    'token_type' => SiteTokenStore::TYPE_DIRECT_RUNNER,
                ],
            ],
        ]));

        $this->postJson('/api/collector/status', [
            'collector_name' => 'hq-collector',
        ], [
            'X-Site-Id' => 'SITE-HQ',
            'X-Site-Token' => 'direct-runner-token',
        ])->assertUnauthorized();
    }

    public function test_verifier_rejects_collector_token_when_direct_runner_type_is_required(): void
    {
        Config::set('inventory.site_tokens_json', json_encode([
            'SITE-HQ' => [
                'collector' => 'collector-token',
                'direct_runner' => 'direct-runner-token',
            ],
        ]));

        $request = Request::create('/api/future/direct-runner', 'POST', [], [], [], [
            'HTTP_X_SITE_ID' => 'SITE-HQ',
            'HTTP_X_SITE_TOKEN' => 'collector-token',
        ]);

        try {
            app(SiteTokenVerifier::class)->verify($request, SiteTokenStore::TYPE_DIRECT_RUNNER);
            $this->fail('Expected collector token to be rejected for direct_runner verification.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $exception) {
            $this->assertSame(Response::HTTP_UNAUTHORIZED, $exception->getStatusCode());
        }
    }

    public function test_legacy_token_without_type_is_accepted_as_collector(): void
    {
        Config::set('inventory.site_tokens_json', json_encode(['SITE-HQ' => 'legacy-token']));

        $this->postJson('/api/collector/status', [
            'collector_name' => 'hq-collector',
        ], [
            'X-Site-Id' => 'SITE-HQ',
            'X-Site-Token' => 'legacy-token',
        ])->assertOk();
    }

    public function test_revoked_token_is_rejected(): void
    {
        Config::set('inventory.site_tokens_json', json_encode([
            'SITE-HQ' => [
                'token' => 'revoked-token',
                'token_type' => SiteTokenStore::TYPE_COLLECTOR,
                'revoked_at' => '2026-05-01T00:00:00+00:00',
            ],
        ]));

        $this->postJson('/api/collector/status', [
            'collector_name' => 'hq-collector',
        ], [
            'X-Site-Id' => 'SITE-HQ',
            'X-Site-Token' => 'revoked-token',
        ])->assertUnauthorized();
    }

    public function test_collector_endpoints_allow_local_dev_when_no_token_map_and_tokens_not_required(): void
    {
        $tokenFile = storage_path('framework/testing/no_site_tokens_local_dev.json');
        if (is_file($tokenFile)) {
            unlink($tokenFile);
        }

        Config::set('inventory.site_tokens_json', null);
        Config::set('inventory.site_tokens_file', $tokenFile);
        Config::set('inventory.require_site_tokens', false);

        $this->postJson('/api/collector/status', [
            'collector_name' => 'hq-collector',
        ], [
            'X-Site-Id' => 'SITE-HQ',
            'X-Site-Token' => 'any-token',
        ])->assertOk();
    }

    public function test_collector_site_token_file_can_be_configured_as_relative_path(): void
    {
        $relativeTokenFile = 'storage/framework/testing/relative_site_tokens.json';
        $tokenFile = base_path($relativeTokenFile);
        if (! is_dir(dirname($tokenFile))) {
            mkdir(dirname($tokenFile), 0777, true);
        }
        file_put_contents($tokenFile, json_encode(['SITE-HQ' => 'relative-token']));
        Config::set('inventory.site_tokens_json', null);
        Config::set('inventory.site_tokens_file', $relativeTokenFile);

        $this->postJson('/api/collector/status', [
            'collector_name' => 'hq-collector',
        ], [
            'X-Site-Id' => 'SITE-HQ',
            'X-Site-Token' => 'relative-token',
        ])->assertOk();
    }

    public function test_direct_csv_intake_requires_central_token_when_configured(): void
    {
        Config::set('inventory.central_intake_token', 'central-secret');

        $file = UploadedFile::fake()->createWithContent('scan.csv', (string) $this->fixtureContents('sample_data/sample_scan_1.csv'));

        $this->post('/api/intake/csv', [
            'file' => $file,
        ])->assertUnauthorized();

        $file = UploadedFile::fake()->createWithContent('scan.csv', (string) $this->fixtureContents('sample_data/sample_scan_1.csv'));

        $this->post('/api/intake/csv', [
            'file' => $file,
        ], [
            'Authorization' => 'Bearer central-secret',
        ])->assertOk();
    }
}
