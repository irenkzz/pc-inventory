<?php

namespace App\Services\Inventory;

use App\Models\Device;
use App\Models\DeviceIdentity;

class DeviceIdentityMatcher
{
    public function __construct(private readonly CsvNormalizer $normalizer)
    {
    }

    public function findBestDeviceMatch(array $payload): array
    {
        $scores = $this->scoreCandidates($payload);

        if ($scores === []) {
            return [
                'device_id' => null,
                'score' => 0,
                'reason' => 'no_identity_match',
                'is_new_device' => true,
            ];
        }

        arsort($scores);
        $deviceId = (int) array_key_first($scores);
        $score = (int) $scores[$deviceId];

        if ($score >= 90) {
            return [
                'device_id' => $deviceId,
                'score' => $score,
                'reason' => 'strong_identity_match',
                'is_new_device' => false,
            ];
        }

        if ($score >= 70) {
            return [
                'device_id' => $deviceId,
                'score' => $score,
                'reason' => 'probable_identity_match',
                'is_new_device' => false,
            ];
        }

        return [
            'device_id' => null,
            'score' => $score,
            'reason' => 'below_threshold',
            'is_new_device' => true,
        ];
    }

    private function scoreCandidates(array $payload): array
    {
        $scores = [];

        foreach ($this->normalizer->extractIdentityMap($payload) as $identityType => $identity) {
            $deviceIds = DeviceIdentity::query()
                ->where('identity_type', $identityType)
                ->where('identity_value', $identity['value'])
                ->pluck('device_id');

            foreach ($deviceIds as $deviceId) {
                $scores[(int) $deviceId] = ($scores[(int) $deviceId] ?? 0) + (int) $identity['weight'];
            }
        }

        $assetCode = $this->normalizer->normalizeWhitespace($payload['asset_code'] ?? '');
        $manufacturer = $this->normalizer->normalizeWhitespace($payload['manufacturer'] ?? '');
        $model = $this->normalizer->normalizeWhitespace($payload['model'] ?? '');
        $hardwareHash = $this->normalizer->normalizeWhitespace($payload['hardware_hash'] ?? '');

        if ($hardwareHash !== '') {
            Device::query()
                ->where('hardware_hash', $hardwareHash)
                ->get(['id', 'manufacturer', 'model', 'hardware_hash'])
                ->each(function (Device $device) use (&$scores, $manufacturer, $model): void {
                    $bonus = 80;
                    if ($manufacturer !== '' && $manufacturer === $this->normalizer->normalizeWhitespace($device->manufacturer)) {
                        $bonus += 5;
                    }
                    if ($model !== '' && $model === $this->normalizer->normalizeWhitespace($device->model)) {
                        $bonus += 5;
                    }

                    $scores[(int) $device->id] = ($scores[(int) $device->id] ?? 0) + $bonus;
                });
        }

        if ($assetCode === '') {
            return $scores;
        }

        Device::query()
            ->where('current_asset_code', $assetCode)
            ->get(['id', 'manufacturer', 'model', 'hardware_hash'])
            ->each(function (Device $device) use (&$scores, $manufacturer, $model, $hardwareHash): void {
                $bonus = 20;
                if ($manufacturer !== '' && $manufacturer === $this->normalizer->normalizeWhitespace($device->manufacturer)) {
                    $bonus += 8;
                }
                if ($model !== '' && $model === $this->normalizer->normalizeWhitespace($device->model)) {
                    $bonus += 8;
                }
                if ($hardwareHash !== '' && $hardwareHash === $this->normalizer->normalizeWhitespace($device->hardware_hash)) {
                    $bonus += 15;
                }

                $scores[(int) $device->id] = ($scores[(int) $device->id] ?? 0) + $bonus;
            });

        return $scores;
    }
}
