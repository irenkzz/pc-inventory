<?php

namespace App\Services\Collector;

use App\Models\Collector;
use App\Models\CollectorSite;
use App\Services\Inventory\ClassificationRuleService;
use App\Services\Inventory\CsvNormalizer;
use App\Support\InventoryTime;
use Carbon\CarbonImmutable;

class CollectorStatusService
{
    public function __construct(
        private readonly CsvNormalizer $normalizer,
        private readonly ClassificationRuleService $classificationRules,
    )
    {
    }

    public function upsert(array $payload): Collector
    {
        $siteId = $this->normalizer->normalizeWhitespace($payload['site_id'] ?? '');
        $collectorName = $this->normalizer->normalizeWhitespace($payload['collector_name'] ?? 'collector') ?: 'collector';

        $this->ensureSite($siteId, $this->normalizer->normalizeWhitespace($payload['site_name'] ?? ''));

        return Collector::query()->updateOrCreate(
            [
                'site_id' => $siteId,
                'collector_name' => $collectorName,
            ],
            [
                'collector_version' => $this->normalizer->normalizeWhitespace($payload['collector_version'] ?? ''),
                'share_root_hint' => $this->normalizer->normalizeWhitespace($payload['share_root_hint'] ?? ''),
                'last_seen_at' => $this->nullableDate($payload['last_seen_at'] ?? null) ?? now(),
                'last_status' => $this->normalizer->normalizeWhitespace($payload['last_status'] ?? 'ok') ?: 'ok',
                'last_error' => $this->normalizer->normalizeWhitespace($payload['last_error'] ?? ''),
                'queue_depth_csv' => (int) ($payload['queue_depth_csv'] ?? 0),
                'queue_depth_heartbeat' => (int) ($payload['queue_depth_heartbeat'] ?? 0),
                'raw_status_json' => $payload,
            ],
        );
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
}
