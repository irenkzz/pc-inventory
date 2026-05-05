<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

class CollectorDiagnosticCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_collector_diagnostic_reports_success_for_reachable_collector_api(): void
    {
        Http::fake([
            'https://inventory.example.local/health' => Http::response(['status' => 'ok'], 200),
            'https://inventory.example.local/api/collector/status' => Http::response(['status' => 'ok'], 200),
            'https://inventory.example.local/api/collector/heartbeat' => Http::response(['status' => 'ok'], 200),
            'https://inventory.example.local/api/collector/commands' => Http::response([], 200),
        ]);

        $this->withoutMockingConsoleOutput();

        $buffer = new BufferedOutput();
        $exitCode = Artisan::call('inventory:collector-diagnostic', [
            'base_url' => 'https://inventory.example.local/',
            'site_id' => 'SITE-HQ',
            'site_token' => 'real-token',
            '--collector-name' => 'hq-collector',
            '--runner-id' => 'PC-DIAGNOSTIC',
        ], $buffer);
        $output = $buffer->fetch();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('"status": "ok"', $output);
        $this->assertStringContainsString('"name": "Health endpoint"', $output);
        $this->assertStringContainsString('"name": "Collector status"', $output);
        $this->assertStringContainsString('"name": "Runner heartbeat"', $output);
        $this->assertStringContainsString('"name": "Command polling"', $output);

        Http::assertSent(fn ($request): bool => $request->hasHeader('X-Site-Id', 'SITE-HQ')
            && $request->hasHeader('X-Site-Token', 'real-token'));
    }

    public function test_collector_diagnostic_fails_when_collector_auth_is_rejected(): void
    {
        Http::fake([
            'https://inventory.example.local/health' => Http::response(['status' => 'ok'], 200),
            'https://inventory.example.local/api/collector/status' => Http::response(['message' => 'Unauthenticated.'], 401),
            'https://inventory.example.local/api/collector/heartbeat' => Http::response(['message' => 'Unauthenticated.'], 401),
            'https://inventory.example.local/api/collector/commands' => Http::response(['message' => 'Unauthenticated.'], 401),
        ]);

        $this->withoutMockingConsoleOutput();

        $buffer = new BufferedOutput();
        $exitCode = Artisan::call('inventory:collector-diagnostic', [
            'base_url' => 'https://inventory.example.local',
            'site_id' => 'SITE-HQ',
            'site_token' => 'wrong-token',
        ], $buffer);
        $output = $buffer->fetch();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('"status": "fail"', $output);
        $this->assertStringContainsString('HTTP 401', $output);
    }
}
