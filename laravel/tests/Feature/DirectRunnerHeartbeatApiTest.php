<?php

namespace Tests\Feature;

use App\Models\Runner;
use App\Services\Security\SiteTokenStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class DirectRunnerHeartbeatApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_valid_direct_runner_heartbeat_is_accepted(): void
    {
        Config::set('inventory.site_tokens_json', json_encode([
            'SITE-HQ' => [
                'token' => 'direct-runner-token',
                'token_type' => SiteTokenStore::TYPE_DIRECT_RUNNER,
            ],
        ]));

        $this->postJson('/api/direct-runner/heartbeat', [
            'site_code' => 'SITE-HQ',
            'runner_id' => 'PC-DIRECT-01',
            'runner_guid' => '8c2c8c70-1111-4f24-9b10-1f5a3e3d4d21',
            'hostname' => 'PC-DIRECT-01',
            'runner_version' => '1.0.21',
            'transport_mode' => 'direct_https',
            'local_time' => '2026-05-01T09:00:00+09:00',
            'last_scan_at' => '2026-05-01T08:55:00+09:00',
            'last_upload_status' => 'heartbeat',
            'last_error' => '',
        ], $this->headers('direct-runner-token'))->assertOk()->assertJson([
            'status' => 'ok',
            'site_code' => 'SITE-HQ',
            'runner_id' => 'PC-DIRECT-01',
        ]);

        $this->assertDatabaseHas('runners', [
            'runner_id' => 'PC-DIRECT-01',
            'runner_guid' => '8c2c8c70-1111-4f24-9b10-1f5a3e3d4d21',
            'hostname' => 'PC-DIRECT-01',
            'site_id' => 'SITE-HQ',
            'runner_version' => '1.0.21',
            'install_mode' => 'direct_https',
            'transport_mode' => 'direct_https',
            'last_upload_status' => 'heartbeat',
            'last_upload_error' => '',
        ]);

        $runner = Runner::query()->where('runner_id', 'PC-DIRECT-01')->firstOrFail();
        $this->assertNotNull($runner->last_direct_heartbeat_at);
        $this->assertSame('direct_https', $runner->raw_state_json['transport_mode']);
        $this->assertSame('2026-05-01T09:00:00+09:00', $runner->raw_state_json['local_time']);
    }

    public function test_collector_token_is_rejected_for_direct_runner_heartbeat(): void
    {
        Config::set('inventory.site_tokens_json', json_encode([
            'SITE-HQ' => [
                'token' => 'collector-token',
                'token_type' => SiteTokenStore::TYPE_COLLECTOR,
            ],
        ]));

        $this->postJson('/api/direct-runner/heartbeat', $this->payload(), $this->headers('collector-token'))
            ->assertUnauthorized();
    }

    public function test_revoked_direct_runner_token_is_rejected_for_direct_runner_heartbeat(): void
    {
        Config::set('inventory.site_tokens_json', json_encode([
            'SITE-HQ' => [
                'token' => 'revoked-direct-runner-token',
                'token_type' => SiteTokenStore::TYPE_DIRECT_RUNNER,
                'revoked_at' => '2026-05-01T00:00:00+00:00',
            ],
        ]));

        $this->postJson('/api/direct-runner/heartbeat', $this->payload(), $this->headers('revoked-direct-runner-token'))
            ->assertUnauthorized();
    }

    public function test_missing_runner_id_is_rejected(): void
    {
        Config::set('inventory.site_tokens_json', json_encode([
            'SITE-HQ' => [
                'token' => 'direct-runner-token',
                'token_type' => SiteTokenStore::TYPE_DIRECT_RUNNER,
            ],
        ]));

        $payload = $this->payload();
        unset($payload['runner_id']);

        $this->postJson('/api/direct-runner/heartbeat', $payload, $this->headers('direct-runner-token'))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('runner_id');
    }

    public function test_transport_mode_other_than_direct_https_is_rejected(): void
    {
        Config::set('inventory.site_tokens_json', json_encode([
            'SITE-HQ' => [
                'token' => 'direct-runner-token',
                'token_type' => SiteTokenStore::TYPE_DIRECT_RUNNER,
            ],
        ]));

        $payload = $this->payload();
        $payload['transport_mode'] = 'collector';

        $this->postJson('/api/direct-runner/heartbeat', $payload, $this->headers('direct-runner-token'))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('transport_mode');
    }

    private function payload(): array
    {
        return [
            'site_code' => 'SITE-HQ',
            'runner_id' => 'PC-DIRECT-01',
            'runner_guid' => '8c2c8c70-1111-4f24-9b10-1f5a3e3d4d21',
            'hostname' => 'PC-DIRECT-01',
            'runner_version' => '1.0.21',
            'transport_mode' => 'direct_https',
            'local_time' => '2026-05-01T09:00:00+09:00',
            'last_scan_at' => '2026-05-01T08:55:00+09:00',
            'last_upload_status' => 'heartbeat',
            'last_error' => '',
        ];
    }

    private function headers(string $token): array
    {
        return [
            'X-Site-Id' => 'SITE-HQ',
            'X-Site-Token' => $token,
        ];
    }
}
