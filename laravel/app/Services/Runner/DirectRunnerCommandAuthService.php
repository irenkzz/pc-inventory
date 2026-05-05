<?php

namespace App\Services\Runner;

use App\Models\Runner;
use App\Services\Inventory\CsvNormalizer;
use App\Services\Security\DirectRunnerAuthService;
use Illuminate\Http\Request;

class DirectRunnerCommandAuthService
{
    public function __construct(
        private readonly DirectRunnerAuthService $auth,
        private readonly RunnerStatusService $runnerStatus,
        private readonly CsvNormalizer $normalizer,
    ) {
    }

    public function authenticateRunner(Request $request, array $payload, array $statusPayload = []): array
    {
        $context = $this->auth->authenticate($request);
        $siteCode = (string) $context['site_code'];
        $runnerId = $this->normalizer->normalizeWhitespace($payload['runner_id'] ?? '');
        $runnerGuid = $this->normalizeGuid($payload['runner_guid'] ?? '');

        $knownRunner = Runner::query()->where('runner_id', $runnerId)->first();
        abort_if($knownRunner !== null && (string) $knownRunner->site_id !== $siteCode, 401);
        abort_if($this->knownGuidMismatch($knownRunner, $runnerGuid), 401);

        $runner = $this->runnerStatus->upsert([
            'site_id' => $siteCode,
            'runner_id' => $runnerId,
            'runner_guid' => $runnerGuid,
            'hostname' => (string) ($payload['hostname'] ?? ''),
            'runner_version' => (string) ($payload['runner_version'] ?? ''),
            'install_mode' => 'direct_https',
            'transport_mode' => 'direct_https',
            'last_seen_at' => now()->toIso8601String(),
            ...$statusPayload,
        ]);

        return [
            'site_code' => $siteCode,
            'runner_id' => (string) $runner->runner_id,
            'runner' => $runner,
        ];
    }

    private function knownGuidMismatch(?Runner $runner, string $runnerGuid): bool
    {
        if ($runner === null || (string) $runner->runner_guid === '') {
            return false;
        }

        return $runnerGuid === '' || (string) $runner->runner_guid !== $runnerGuid;
    }

    private function normalizeGuid(mixed $value): string
    {
        $value = strtolower($this->normalizer->normalizeWhitespace($value));
        if ($value === '') {
            return '';
        }

        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $value) === 1
            ? $value
            : '';
    }
}
