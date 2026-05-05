<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class CollectorDiagnostic extends Command
{
    protected $signature = 'inventory:collector-diagnostic
        {base_url : Laravel base URL, for example https://inventory.example.local}
        {site_id : Collector site id}
        {site_token : Collector site token}
        {--collector-name=diagnostic-collector : Collector name to report during the diagnostic}
        {--runner-id=diagnostic-runner : Runner id to report during the diagnostic}
        {--timeout=10 : HTTP timeout in seconds}';

    protected $description = 'Test collector API connectivity and site-token authentication against a Laravel inventory URL';

    public function handle(): int
    {
        $baseUrl = rtrim((string) $this->argument('base_url'), '/');
        $siteId = trim((string) $this->argument('site_id'));
        $siteToken = trim((string) $this->argument('site_token'));
        $collectorName = trim((string) $this->option('collector-name')) ?: 'diagnostic-collector';
        $runnerId = trim((string) $this->option('runner-id')) ?: 'diagnostic-runner';
        $timeout = max(1, (int) $this->option('timeout'));

        $client = Http::acceptJson()
            ->asJson()
            ->timeout($timeout)
            ->withHeaders([
                'X-Site-Id' => $siteId,
                'X-Site-Token' => $siteToken,
            ]);

        $checks = [
            $this->checkHealth($client, $baseUrl),
            $this->checkCollectorStatus($client, $baseUrl, $siteId, $collectorName),
            $this->checkRunnerHeartbeat($client, $baseUrl, $siteId, $collectorName, $runnerId),
            $this->checkCommandPolling($client, $baseUrl),
        ];

        $failed = collect($checks)->where('status', 'fail')->count();

        $this->line(json_encode([
            'status' => $failed === 0 ? 'ok' : 'fail',
            'base_url' => $baseUrl,
            'site_id' => $siteId,
            'checks' => $checks,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function checkHealth(mixed $client, string $baseUrl): array
    {
        return $this->requestCheck('Health endpoint', fn (): Response => $client->get("{$baseUrl}/health"));
    }

    private function checkCollectorStatus(mixed $client, string $baseUrl, string $siteId, string $collectorName): array
    {
        return $this->requestCheck('Collector status', fn (): Response => $client->post("{$baseUrl}/api/collector/status", [
            'site_id' => $siteId,
            'site_name' => 'Diagnostic Site',
            'collector_name' => $collectorName,
            'collector_version' => 'diagnostic',
            'last_status' => 'diagnostic',
            'last_seen_at' => now()->toIso8601String(),
            'queue_depth_csv' => 0,
            'queue_depth_heartbeat' => 0,
        ]));
    }

    private function checkRunnerHeartbeat(mixed $client, string $baseUrl, string $siteId, string $collectorName, string $runnerId): array
    {
        return $this->requestCheck('Runner heartbeat', fn (): Response => $client->post("{$baseUrl}/api/collector/heartbeat", [
            'site_id' => $siteId,
            'site_name' => 'Diagnostic Site',
            'collector_name' => $collectorName,
            'runner_id' => $runnerId,
            'hostname' => $runnerId,
            'runner_version' => 'diagnostic',
            'install_mode' => 'diagnostic',
            'last_seen_at' => now()->toIso8601String(),
            'last_inventory_status' => 'diagnostic',
            'last_upload_status' => 'diagnostic',
        ]));
    }

    private function checkCommandPolling(mixed $client, string $baseUrl): array
    {
        return $this->requestCheck('Command polling', fn (): Response => $client->get("{$baseUrl}/api/collector/commands"));
    }

    private function requestCheck(string $name, callable $request): array
    {
        try {
            $response = $request();
        } catch (\Throwable $exception) {
            return [
                'status' => 'fail',
                'name' => $name,
                'message' => $exception->getMessage(),
            ];
        }

        if ($response->successful()) {
            return [
                'status' => 'pass',
                'name' => $name,
                'message' => 'HTTP ' . $response->status(),
            ];
        }

        return [
            'status' => 'fail',
            'name' => $name,
            'message' => 'HTTP ' . $response->status() . $this->responseHint($response),
        ];
    }

    private function responseHint(Response $response): string
    {
        $body = trim($response->body());
        if ($body === '') {
            return '';
        }

        return ': ' . Str::limit($body, 300);
    }
}
