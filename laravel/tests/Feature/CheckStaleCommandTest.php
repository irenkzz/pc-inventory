<?php

namespace Tests\Feature;

use App\Models\Runner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CheckStaleCommandTest extends TestCase
{
    use RefreshDatabase;

    private function seedRunners(): void
    {
        Runner::query()->create(['runner_id' => 'OLD-PC', 'hostname' => 'OLD-PC', 'last_seen_at' => now()->subHours(48)]);
        Runner::query()->create(['runner_id' => 'NEW-PC', 'hostname' => 'NEW-PC', 'last_seen_at' => now()->subHour()]);
    }

    public function test_classifies_stale_vs_fresh_and_exits_zero_unless_flagged(): void
    {
        $this->seedRunners();

        $this->artisan('inventory:check-stale')->expectsOutputToContain('Stale runners: 1')->assertExitCode(0);
        $this->artisan('inventory:check-stale --fail-on-stale')->assertExitCode(1);
        $this->artisan('inventory:check-stale --hours=100 --fail-on-stale')->assertExitCode(0);
    }

    public function test_webhook_is_posted_without_secrets_and_cooldown_suppresses_repeat(): void
    {
        Cache::flush();
        config(['inventory.alert_webhook_url' => 'https://hooks.example.test/x']);
        Http::fake();
        $this->seedRunners();

        $this->artisan('inventory:check-stale')->assertExitCode(0);
        $this->artisan('inventory:check-stale')->assertExitCode(0);

        Http::assertSentCount(1);
        Http::assertSent(function ($request): bool {
            $body = $request->data();

            return $body['stale_runner_count'] === 1
                && $body['runners'][0]['runner_id'] === 'OLD-PC'
                && ! str_contains(json_encode($body), 'token');
        });
    }

    public function test_webhook_failure_does_not_crash(): void
    {
        Cache::flush();
        config(['inventory.alert_webhook_url' => 'https://hooks.example.test/x']);
        Http::fake(['*' => Http::response('boom', 500)]);
        $this->seedRunners();

        $this->artisan('inventory:check-stale')->assertExitCode(0);
    }

    public function test_schedule_flag_defaults_to_false(): void
    {
        $this->assertFalse((bool) config('inventory.schedule_stale_check'));
        $this->assertSame(json_decode(file_get_contents(base_path('../runner/manifest/runner-manifest.json')), true)['runner_version'], config('inventory.runner_target_version'));
    }
}
