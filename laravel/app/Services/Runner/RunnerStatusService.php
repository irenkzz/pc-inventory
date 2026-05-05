<?php

namespace App\Services\Runner;

use App\Models\CollectorSite;
use App\Models\Runner;
use App\Services\Inventory\ClassificationRuleService;
use App\Services\Inventory\CsvNormalizer;
use App\Support\InventoryTime;
use Carbon\CarbonImmutable;

class RunnerStatusService
{
    public function __construct(
        private readonly CsvNormalizer $normalizer,
        private readonly ClassificationRuleService $classificationRules,
        private readonly RunnerIdentityReconciler $runnerIdentityReconciler,
    )
    {
    }

    public function upsert(array $payload): Runner
    {
        $runnerId = $this->normalizer->normalizeWhitespace($payload['runner_id'] ?? '');
        if ($runnerId === '') {
            throw new \InvalidArgumentException('runner_id is required');
        }

        $runnerGuid = $this->normalizeGuid($payload['runner_guid'] ?? ($payload['runnerGuid'] ?? ''));
        if ($runnerGuid !== '') {
            $existingByGuid = Runner::query()->where('runner_guid', $runnerGuid)->first();
            if ($existingByGuid !== null && $existingByGuid->runner_id !== $runnerId) {
                $merged = $this->runnerIdentityReconciler->renameOrMerge(
                    (string) $existingByGuid->runner_id,
                    $runnerId,
                    ['hostname' => $this->normalizer->normalizeWhitespace($payload['hostname'] ?? ($payload['computer_name'] ?? $runnerId))],
                );

                if (! $merged) {
                    $runnerGuid = '';
                } else {
                    $payload = $this->withPreviousRunnerId($payload, (string) $existingByGuid->runner_id);
                }
            }
        }

        $siteId = $this->normalizer->normalizeWhitespace($payload['site_id'] ?? '');
        $this->ensureSite($siteId, $this->normalizer->normalizeWhitespace($payload['site_name'] ?? ''));

        $attributes = [
            'runner_guid' => $runnerGuid !== '' ? $runnerGuid : null,
            'hostname' => $this->normalizer->normalizeWhitespace($payload['hostname'] ?? ($payload['computer_name'] ?? '')),
            'site_id' => $siteId !== '' ? $siteId : null,
            'collector_name' => $this->normalizer->normalizeWhitespace($payload['collector_name'] ?? ''),
            'runner_version' => $this->normalizer->normalizeWhitespace($payload['runner_version'] ?? ($payload['version'] ?? '')),
            'install_mode' => $this->normalizer->normalizeWhitespace($payload['install_mode'] ?? 'scheduled_task') ?: 'scheduled_task',
            'last_seen_at' => $this->nullableDate($payload['last_seen_at'] ?? null) ?? now(),
            'last_successful_inventory_at' => $this->nullableDate($payload['last_successful_inventory_at'] ?? null),
            'last_inventory_status' => $this->normalizer->normalizeWhitespace($payload['last_inventory_status'] ?? ''),
            'last_upload_status' => $this->normalizer->normalizeWhitespace($payload['last_upload_status'] ?? ''),
            'last_error' => $this->normalizer->normalizeWhitespace($payload['last_error'] ?? ''),
            'last_command_seen_at' => $this->nullableDate($payload['last_command_seen_at'] ?? null),
            'last_command_type' => $this->normalizer->normalizeWhitespace($payload['last_command_type'] ?? ''),
            'raw_state_json' => $payload,
        ];

        if (array_key_exists('transport_mode', $payload)) {
            $attributes['transport_mode'] = $this->normalizer->normalizeWhitespace($payload['transport_mode'] ?? '');
        }

        if (array_key_exists('last_direct_heartbeat_at', $payload)) {
            $attributes['last_direct_heartbeat_at'] = $this->nullableDate($payload['last_direct_heartbeat_at'] ?? null);
        }

        if (array_key_exists('last_direct_upload_at', $payload)) {
            $attributes['last_direct_upload_at'] = $this->nullableDate($payload['last_direct_upload_at'] ?? null);
        }

        if (array_key_exists('last_direct_poll_at', $payload)) {
            $attributes['last_direct_poll_at'] = $this->nullableDate($payload['last_direct_poll_at'] ?? null);
        }

        if (array_key_exists('last_upload_error', $payload)) {
            $attributes['last_upload_error'] = $this->normalizer->normalizeWhitespace($payload['last_upload_error'] ?? '');
        }

        return Runner::query()->updateOrCreate(['runner_id' => $runnerId], $attributes);
    }

    private function ensureSite(string $siteId, string $siteName = ''): void
    {
        if ($siteId === '') {
            return;
        }

        $displayName = $this->classificationRules->siteForScannerValue($siteName !== '' ? $siteName : $siteId);

        CollectorSite::query()->updateOrCreate(
            ['site_id' => $siteId],
            ['site_name' => $displayName],
        );
    }

    private function nullableDate(mixed $value): ?CarbonImmutable
    {
        $value = $this->normalizer->normalizeWhitespace($value);
        if ($value === '') {
            return null;
        }

        return InventoryTime::parseForStorage($value);
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

    private function withPreviousRunnerId(array $payload, string $oldRunnerId): array
    {
        $aliases = $payload['previous_runner_ids'] ?? [];
        if (! is_array($aliases)) {
            $aliases = [];
        }

        $aliases[] = $oldRunnerId;
        $payload['previous_runner_ids'] = array_values(array_unique(array_filter($aliases)));

        return $payload;
    }
}
