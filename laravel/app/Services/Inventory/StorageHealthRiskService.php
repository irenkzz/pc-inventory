<?php

namespace App\Services\Inventory;

use App\Models\StorageHealthObservation;

class StorageHealthRiskService
{
    public function fromPayload(array $payload): array
    {
        $rawJson = trim((string) ($payload['storage_health_json'] ?? ''));
        if ($rawJson !== '') {
            $decoded = json_decode($rawJson, true);
            if (is_array($decoded)) {
                $rows = array_is_list($decoded) ? $decoded : ($decoded['disks'] ?? []);

                return array_values(array_filter(
                    array_map(fn (mixed $row): ?array => is_array($row) ? $row : null, $rows),
                ));
            }
        }

        if ($this->hasLegacySsdTelemetry($payload)) {
            return [[
                'disk_key' => 'legacy-ssd-summary',
                'disk_model' => '',
                'disk_serial' => '',
                'disk_type' => 'ssd',
                'smart_available' => null,
                'tbw_bytes' => $this->firstMetricValue($payload['ssd_tbw_bytes'] ?? ''),
                'tbw_gb' => $this->firstMetricValue($payload['ssd_tbw_gb'] ?? ''),
                'percentage_used' => $this->firstMetricValue($payload['ssd_percentage_used'] ?? ''),
                'power_on_hours' => $this->firstMetricValue($payload['ssd_power_on_hours'] ?? ''),
                'source_method' => $payload['ssd_health_source'] ?? '',
                'storage_health_detail' => $payload['ssd_health_detail'] ?? '',
            ]];
        }

        return [];
    }

    public function normalizeObservation(array $row, ?StorageHealthObservation $previous = null): array
    {
        $normalized = [
            'disk_key' => $this->string($row, 'disk_key') ?: $this->diskKey($row),
            'disk_model' => $this->string($row, 'disk_model', 'model'),
            'disk_serial' => $this->string($row, 'disk_serial', 'serial'),
            'disk_type' => strtolower($this->string($row, 'disk_type', 'media_type')),
            'interface_type' => $this->string($row, 'interface_type'),
            'capacity_gb' => $this->numberOrNull($row['capacity_gb'] ?? $row['size_gb'] ?? null),
            'smart_available' => $this->boolOrNull($row['smart_available'] ?? null),
            'smart_health_status' => $this->string($row, 'smart_health_status', 'health_status'),
            'tbw_bytes' => $this->integerOrNull($row['tbw_bytes'] ?? $row['bytes_written'] ?? null),
            'tbw_gb' => $this->numberOrNull($row['tbw_gb'] ?? null),
            'percentage_used' => $this->integerOrNull($row['percentage_used'] ?? null),
            'power_on_hours' => $this->integerOrNull($row['power_on_hours'] ?? null),
            'temperature_c' => $this->integerOrNull($row['temperature_c'] ?? null),
            'available_spare' => $this->integerOrNull($row['available_spare'] ?? null),
            'media_errors' => $this->integerOrNull($row['media_errors'] ?? null),
            'reallocated_sector_count' => $this->integerOrNull($row['reallocated_sector_count'] ?? null),
            'current_pending_sector' => $this->integerOrNull($row['current_pending_sector'] ?? null),
            'offline_uncorrectable' => $this->integerOrNull($row['offline_uncorrectable'] ?? null),
            'source_method' => $this->string($row, 'source_method', 'health_source'),
            'storage_health_detail' => $this->string($row, 'storage_health_detail', 'health_detail'),
            'raw_smart_json' => $this->rawJson($row['raw_smart_json'] ?? null),
        ];

        $nvmeDataUnitsWritten = $this->integerOrNull($row['data_units_written'] ?? null);
        if ($normalized['tbw_bytes'] === null && $nvmeDataUnitsWritten !== null) {
            $normalized['tbw_bytes'] = $nvmeDataUnitsWritten * 512000;
            $normalized['source_method'] = $normalized['source_method'] ?: 'smartctl_nvme_data_units_written';
        }

        if ($normalized['tbw_bytes'] !== null && $normalized['tbw_gb'] === null) {
            $normalized['tbw_gb'] = round($normalized['tbw_bytes'] / 1000000000, 2);
        }

        return [
            ...$normalized,
            ...$this->score($normalized, $previous),
        ];
    }

    public function score(array $row, ?StorageHealthObservation $previous = null): array
    {
        $level = 'healthy';
        $score = 0;
        $reasons = [];
        $type = strtolower((string) ($row['disk_type'] ?? ''));
        $isSsd = str_contains($type, 'ssd') || str_contains($type, 'nvme') || ($row['percentage_used'] ?? null) !== null;
        $isHdd = str_contains($type, 'hdd') || str_contains($type, 'hard') || ($row['current_pending_sector'] ?? null) !== null;
        $health = strtolower((string) ($row['smart_health_status'] ?? ''));

        if (($row['smart_available'] ?? null) === false) {
            $level = 'unknown';
            $score = max($score, 10);
            $reasons[] = $row['storage_health_detail'] ?: 'SMART data is not readable through the current controller or driver.';
        }

        if ($health !== '' && (str_contains($health, 'fail') || str_contains($health, 'bad'))) {
            [$level, $score] = $this->raise($level, $score, 'critical', 100);
            $reasons[] = 'SMART overall health reports failure.';
        }

        if (($row['current_pending_sector'] ?? 0) > 0) {
            [$level, $score] = $this->raise($level, $score, 'critical', 95);
            $reasons[] = 'HDD current pending sectors are present.';
        }

        if (($row['offline_uncorrectable'] ?? 0) > 0) {
            [$level, $score] = $this->raise($level, $score, 'critical', 95);
            $reasons[] = 'HDD offline uncorrectable sectors are present.';
        }

        if ($this->increased($row['reallocated_sector_count'] ?? null, $previous?->reallocated_sector_count)) {
            [$level, $score] = $this->raise($level, $score, 'critical', 90);
            $reasons[] = 'HDD reallocated sector count increased since the previous scan.';
        } elseif (($row['reallocated_sector_count'] ?? 0) > 0) {
            [$level, $score] = $this->raise($level, $score, 'warning', 70);
            $reasons[] = 'HDD reallocated sectors exist but have not increased.';
        }

        if (($row['percentage_used'] ?? null) !== null) {
            if ($row['percentage_used'] >= 90) {
                [$level, $score] = $this->raise($level, $score, 'critical', 90);
                $reasons[] = 'SSD lifetime used is 90% or higher.';
            } elseif ($row['percentage_used'] >= 70) {
                [$level, $score] = $this->raise($level, $score, 'warning', 72);
                $reasons[] = 'SSD lifetime used is between 70% and 89%.';
            } elseif ($row['percentage_used'] >= 50) {
                [$level, $score] = $this->raise($level, $score, 'watch', 45);
                $reasons[] = 'SSD lifetime used is between 50% and 69%.';
            }
        }

        if ($this->increased($row['media_errors'] ?? null, $previous?->media_errors)) {
            [$level, $score] = $this->raise($level, $score, 'critical', 92);
            $reasons[] = 'SSD media/data integrity errors increased since the previous scan.';
        }

        if (($row['temperature_c'] ?? null) !== null) {
            if ($row['temperature_c'] >= 65) {
                [$level, $score] = $this->raise($level, $score, 'critical', 88);
                $reasons[] = 'Disk temperature is critically high.';
            } elseif ($row['temperature_c'] >= 55) {
                [$level, $score] = $this->raise($level, $score, 'warning', 68);
                $reasons[] = 'Disk temperature is high.';
            } elseif ($row['temperature_c'] >= 48) {
                [$level, $score] = $this->raise($level, $score, 'watch', 38);
                $reasons[] = 'Disk temperature is slightly high.';
            }
        }

        if (($row['power_on_hours'] ?? null) !== null) {
            if ($row['power_on_hours'] >= 50000) {
                [$level, $score] = $this->raise($level, $score, 'warning', 62);
                $reasons[] = 'Disk power-on hours are very high.';
            } elseif ($row['power_on_hours'] >= 30000) {
                [$level, $score] = $this->raise($level, $score, 'watch', 32);
                $reasons[] = 'Disk is old based on power-on hours.';
            }
        }

        if ($level === 'healthy' && $row['smart_available'] === null && ! $this->hasAnySmartSignal($row)) {
            $level = 'unknown';
            $score = max($score, 10);
            $reasons[] = 'SMART health data is not available.';
        }

        if ($level === 'healthy') {
            $reasons[] = 'No storage health risk indicators were reported.';
        }

        return [
            'risk_level' => $level,
            'risk_score' => $score,
            'risk_reasons' => array_values(array_unique($reasons)),
            'recommended_action' => $this->recommendedAction($level),
        ];
    }

    private function hasLegacySsdTelemetry(array $payload): bool
    {
        foreach (['ssd_tbw_bytes', 'ssd_tbw_gb', 'ssd_percentage_used', 'ssd_power_on_hours', 'ssd_health_detail'] as $key) {
            if (trim((string) ($payload[$key] ?? '')) !== '') {
                return true;
            }
        }

        return false;
    }

    private function firstMetricValue(mixed $value): ?string
    {
        $text = trim((string) $value);
        if ($text === '') {
            return null;
        }

        $part = trim(explode(';', $text)[0] ?? '');
        if (str_contains($part, '=')) {
            return trim(explode('=', $part, 2)[1] ?? '');
        }

        return $part;
    }

    private function diskKey(array $row): string
    {
        $serial = trim((string) ($row['disk_serial'] ?? $row['serial'] ?? ''));
        if ($serial !== '' && ! (new CsvNormalizer())->isGenericIdentityValue($serial)) {
            return 'serial:' . $serial;
        }

        return 'fallback:' . sha1(implode('|', [
            $row['disk_model'] ?? $row['model'] ?? '',
            $row['capacity_gb'] ?? $row['size_gb'] ?? '',
            $row['physical_index'] ?? $row['index'] ?? '',
        ]));
    }

    private function string(array $row, string ...$keys): string
    {
        foreach ($keys as $key) {
            $value = trim((string) ($row[$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    private function integerOrNull(mixed $value): ?int
    {
        $number = $this->numberOrNull($value);

        return $number === null ? null : (int) $number;
    }

    private function numberOrNull(mixed $value): ?float
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        if (! is_numeric($value)) {
            return null;
        }

        return (float) $value;
    }

    private function boolOrNull(mixed $value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    }

    private function rawJson(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return is_string($value) ? $value : json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private function increased(mixed $current, mixed $previous): bool
    {
        return $current !== null && $previous !== null && (int) $current > (int) $previous;
    }

    private function hasAnySmartSignal(array $row): bool
    {
        foreach (['smart_health_status', 'percentage_used', 'power_on_hours', 'temperature_c', 'media_errors', 'reallocated_sector_count', 'current_pending_sector', 'offline_uncorrectable'] as $key) {
            if (($row[$key] ?? null) !== null && $row[$key] !== '') {
                return true;
            }
        }

        return false;
    }

    private function raise(string $level, int $score, string $candidate, int $candidateScore): array
    {
        $rank = ['unknown' => 0, 'healthy' => 1, 'watch' => 2, 'warning' => 3, 'critical' => 4];

        return [
            ($rank[$candidate] ?? 0) > ($rank[$level] ?? 0) ? $candidate : $level,
            max($score, $candidateScore),
        ];
    }

    private function recommendedAction(string $level): string
    {
        return match ($level) {
            'healthy' => 'Normal monitoring',
            'watch' => 'Monitor weekly',
            'warning' => 'Schedule backup and replacement review',
            'critical' => 'Backup immediately and prepare replacement',
            default => 'Manual check required if device stores important data',
        };
    }
}
