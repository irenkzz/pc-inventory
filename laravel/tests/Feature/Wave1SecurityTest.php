<?php

namespace Tests\Feature;

use App\Models\CollectorSite;
use App\Models\Runner;
use App\Models\RunnerCommand;
use App\Models\User;
use App\Services\Runner\CommandQueueService;
use App\Services\Security\SiteTokenStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Hash;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

class Wave1SecurityTest extends TestCase
{
    use RefreshDatabase;

    private function collectorTokens(): void
    {
        Config::set('inventory.require_site_tokens', true);
        Config::set('inventory.site_tokens_json', json_encode([
            'SITE-A' => ['token' => 'tok-a', 'token_type' => SiteTokenStore::TYPE_COLLECTOR],
            'SITE-B' => ['token' => 'tok-b', 'token_type' => SiteTokenStore::TYPE_COLLECTOR],
        ]));
    }

    private function headersA(): array
    {
        return ['X-Site-Id' => 'SITE-A', 'X-Site-Token' => 'tok-a'];
    }

    public function test_heartbeat_payload_site_id_mismatch_is_rejected_and_matching_uses_verified_site(): void
    {
        $this->collectorTokens();

        $this->postJson('/api/collector/heartbeat', ['runner_id' => 'PC-1', 'site_id' => 'SITE-B'], $this->headersA())
            ->assertForbidden();
        $this->assertDatabaseMissing('runners', ['runner_id' => 'PC-1']);

        $this->postJson('/api/collector/heartbeat', ['runner_id' => 'PC-1', 'site_id' => 'SITE-A'], $this->headersA())
            ->assertOk();
        $this->assertDatabaseHas('runners', ['runner_id' => 'PC-1', 'site_id' => 'SITE-A']);
    }

    public function test_collector_ack_is_scoped_to_site_whitelisted_and_not_overwritten(): void
    {
        $this->collectorTokens();
        foreach (['SITE-A', 'SITE-B'] as $s) {
            CollectorSite::query()->create(['site_id' => $s, 'site_name' => $s]);
            $r = 'PC-' . substr($s, -1);
            Runner::query()->create(['runner_id' => $r, 'hostname' => $r, 'site_id' => $s]);
        }
        $queue = app(CommandQueueService::class);
        $other = $queue->queue('PC-B', 'SITE-B', 'scan_now', 'test');
        $mine = $queue->queue('PC-A', 'SITE-A', 'scan_now', 'test');

        $this->postJson('/api/collector/command-ack', ['command_id' => $other->id, 'status' => 'completed'], $this->headersA())
            ->assertNotFound();
        $this->assertSame('pending', RunnerCommand::find($other->id)->status);

        $this->postJson('/api/collector/command-ack', ['command_id' => $mine->id, 'status' => 'bogus'], $this->headersA())
            ->assertStatus(422);

        $this->postJson('/api/collector/command-ack', ['command_id' => $mine->id, 'status' => 'completed', 'message' => 'first'], $this->headersA())
            ->assertOk();
        $this->postJson('/api/collector/command-ack', ['command_id' => $mine->id, 'status' => 'failed', 'message' => 'second'], $this->headersA())
            ->assertOk();

        $this->assertDatabaseHas('runner_commands', ['id' => $mine->id, 'status' => 'completed', 'completion_message' => 'first']);
    }

    public function test_direct_ack_does_not_overwrite_completed_but_identical_reack_succeeds(): void
    {
        Config::set('inventory.site_tokens_json', json_encode([
            'SITE-HQ' => ['token' => 'dtok', 'token_type' => SiteTokenStore::TYPE_DIRECT_RUNNER],
        ]));
        CollectorSite::query()->create(['site_id' => 'SITE-HQ', 'site_name' => 'SITE-HQ']);
        Runner::query()->create(['runner_id' => 'PC-D', 'runner_guid' => '8c2c8c70-1111-4f24-9b10-1f5a3e3d4d21', 'hostname' => 'PC-D', 'site_id' => 'SITE-HQ']);
        $command = app(CommandQueueService::class)->queue('PC-D', 'SITE-HQ', 'scan_now', 'test');
        $headers = ['X-Site-Id' => 'SITE-HQ', 'X-Site-Token' => 'dtok'];
        $payload = [
            'site_code' => 'SITE-HQ', 'runner_id' => 'PC-D', 'runner_guid' => '8c2c8c70-1111-4f24-9b10-1f5a3e3d4d21',
            'command_id' => $command->id, 'status' => 'succeeded', 'message' => 'done',
        ];

        $this->postJson('/api/direct-runner/commands/ack', $payload, $headers)->assertOk();
        $this->postJson('/api/direct-runner/commands/ack', $payload, $headers)->assertOk();

        $this->postJson('/api/direct-runner/commands/ack', ['status' => 'failed', 'message' => 'late'] + $payload, $headers)
            ->assertStatus(409);
        $this->assertDatabaseHas('runner_commands', ['id' => $command->id, 'status' => 'succeeded', 'completion_message' => 'done']);
    }

    public function test_read_apis_require_session_auth_and_q_is_grouped(): void
    {
        CollectorSite::query()->create(['site_id' => 'SITE-1', 'site_name' => 'SITE-1']);
        Runner::query()->create(['runner_id' => 'PC-X', 'hostname' => 'PC-X', 'site_id' => 'SITE-1']);

        $this->getJson('/api/runners')->assertUnauthorized();
        $this->getJson('/api/devices')->assertUnauthorized();

        $user = User::query()->create(['name' => 'a', 'email' => 'a@x.test', 'password' => Hash::make('pw')]);
        $this->actingAs($user)->getJson('/api/runners?q=PC-X')->assertOk()->assertJsonFragment(['runner_id' => 'PC-X']);
        $this->actingAs($user)->getJson('/api/devices')->assertOk();
    }

    public function test_login_is_throttled(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->post('/login', ['email' => 'no@x.test', 'password' => 'bad'])->assertStatus(302);
        }
        $this->post('/login', ['email' => 'no@x.test', 'password' => 'bad'])->assertStatus(429);
    }

    public function test_doctor_and_readiness_flag_fail_open_intake_auth(): void
    {
        Config::set('inventory.require_site_tokens', false);
        Config::set('inventory.central_intake_token', '');
        $this->withoutMockingConsoleOutput();

        $buffer = new BufferedOutput();
        Artisan::call('inventory:doctor', ['--json' => true], $buffer);
        $this->assertStringContainsString('INVENTORY_REQUIRE_SITE_TOKENS is false', $buffer->fetch());

        $buffer = new BufferedOutput();
        Artisan::call('inventory:production-readiness', [], $buffer);
        $out = $buffer->fetch();
        $this->assertStringContainsString('[WARN] INVENTORY_REQUIRE_SITE_TOKENS is false', $out);
        $this->assertStringContainsString('[WARN] INVENTORY_CENTRAL_INTAKE_TOKEN is empty', $out);

        Config::set('inventory.require_site_tokens', true);
        Config::set('inventory.central_intake_token', 'secret');
        $buffer = new BufferedOutput();
        Artisan::call('inventory:production-readiness', [], $buffer);
        $this->assertStringNotContainsString('INVENTORY_CENTRAL_INTAKE_TOKEN is empty', $buffer->fetch());
    }

    public function test_query_site_token_can_be_disabled(): void
    {
        $this->collectorTokens();
        $query = '?site_id=SITE-A&site_token=tok-a';

        Config::set('inventory.allow_query_site_token', true);
        $this->getJson('/api/collector/commands' . $query)->assertOk();

        Config::set('inventory.allow_query_site_token', false);
        $this->getJson('/api/collector/commands' . $query)->assertUnauthorized();
        $this->getJson('/api/collector/commands', $this->headersA())->assertOk();
    }

    public function test_oversized_uploads_are_rejected(): void
    {
        $this->collectorTokens();
        Config::set('inventory.central_intake_token', '');
        Config::set('inventory.max_upload_kb', 1);
        $big = UploadedFile::fake()->create('scan.csv', 10, 'text/csv');

        $this->post('/api/collector/intake/csv', ['file' => $big], $this->headersA() + ['Accept' => 'application/json'])->assertStatus(422);
        $this->post('/api/intake/csv', ['file' => $big], ['Accept' => 'application/json'])->assertStatus(422);

        Config::set('inventory.site_tokens_json', json_encode([
            'SITE-HQ' => ['token' => 'dtok', 'token_type' => SiteTokenStore::TYPE_DIRECT_RUNNER],
        ]));
        $this->post('/api/direct-runner/scans', ['metadata' => '{}', 'file' => $big], ['X-Site-Id' => 'SITE-HQ', 'X-Site-Token' => 'dtok', 'Accept' => 'application/json'])
            ->assertStatus(422);
    }
}
