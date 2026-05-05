<?php

namespace App\Services\Inventory;

use App\Models\ChangeLog;
use App\Models\Device;
use App\Models\DeviceAssignment;
use App\Models\DeviceIdentity;
use App\Models\DeviceScan;
use App\Models\NetworkObservation;
use App\Models\Peripheral;
use App\Models\RawFile;
use App\Models\StorageHealthObservation;
use App\Services\Runner\RunnerIdentityReconciler;
use App\Services\Runner\RunnerStatusService;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class InventoryIngestService
{
    public function __construct(
        private readonly CsvNormalizer $normalizer,
        private readonly DeviceIdentityMatcher $matcher,
        private readonly ChangeDetectionService $changeDetection,
        private readonly RawFileArchive $rawFileArchive,
        private readonly RunnerStatusService $runnerStatus,
        private readonly RunnerIdentityReconciler $runnerIdentityReconciler,
        private readonly DeviceAssignmentOverrideService $assignmentOverrides,
        private readonly StorageHealthRiskService $storageHealthRisk,
    ) {
    }

    public function ingestJsonPayload(array $payload, string $rawFilename = 'scan.json', string $scanSource = 'api_json', ?array $rawMetadata = null): array
    {
        $rawText = $this->normalizer->payloadToJson($payload);

        return $this->ingestPayload(
            $payload,
            $rawText,
            'json',
            $rawFilename,
            $scanSource,
            $rawMetadata,
        );
    }

    public function ingestCsvText(string $text, string $rawFilename = 'scan.csv', string $scanSource = 'api_csv', ?array $rawMetadata = null): array
    {
        $results = [];
        foreach ($this->normalizer->csvTextToPayloads($text) as $payload) {
            $results[] = $this->ingestPayload(
                $payload,
                $text,
                'csv',
                $rawFilename,
                $scanSource,
                $rawMetadata,
            );
        }

        return $results;
    }

    public function ingestPayload(
        array $payload,
        string $rawText,
        string $rawFormat,
        string $rawFilename,
        string $scanSource,
        ?array $rawMetadata = null,
    ): array {
        $normalized = $this->normalizer->normalizePayload($payload);
        $rawHash = $this->normalizer->computeRawHash($rawText, $rawFormat);
        $archivedPath = $this->rawFileArchive->archive($rawHash, $rawText, $rawFormat, $rawFilename);

        return DB::transaction(function () use ($normalized, $rawHash, $archivedPath, $rawFormat, $rawFilename, $scanSource, $rawMetadata): array {
            $existingScan = DeviceScan::query()->where('raw_hash', $rawHash)->first();
            if ($existingScan !== null) {
                $this->saveRawFile($rawHash, $rawFilename, $archivedPath, $rawFormat, $rawMetadata);
                $this->upsertRunnerFromMetadata($rawMetadata, $normalized);

                return [
                    'status' => 'duplicate',
                    'raw_hash' => $rawHash,
                    'scan_id' => $existingScan->id,
                ];
            }

            $match = $this->matcher->findBestDeviceMatch($normalized);
            $device = $match['is_new_device']
                ? $this->createDevice($normalized)
                : Device::query()->findOrFail($match['device_id']);

            $previousSnapshot = $this->latestSnapshotForDevice($device);
            $previousAssetCode = $this->normalizer->normalizeWhitespace($previousSnapshot['asset_code'] ?? $device->current_asset_code ?? '');

            $this->updateDeviceCurrentState($device, $normalized);
            $this->upsertIdentities($device, $normalized);

            $scan = DeviceScan::query()->create([
                'device_id' => $device->id,
                'scan_time' => $normalized['scan_time'],
                'ingested_at' => now(),
                'scan_source' => $scanSource,
                'raw_hash' => $rawHash,
                'raw_filename' => $rawFilename,
                'raw_format' => $rawFormat,
            ]);

            $this->createSnapshot($scan, $normalized);
            $this->createNetworkObservation($device, $scan, $normalized);
            $this->createPeripherals($device, $scan, $normalized);
            $this->createStorageHealthObservations($device, $scan, $normalized);

            $changes = $this->changeDetection->detectChanges($previousSnapshot, $normalized);
            $this->insertChanges($device, $scan, $normalized, $changes);
            $this->updateAssignmentHistory($device, $scan, $normalized);
            $this->saveRawFile($rawHash, $rawFilename, $archivedPath, $rawFormat, $rawMetadata);
            $this->upsertRunnerFromMetadata($rawMetadata, $normalized);
            if (! $match['is_new_device']) {
                $this->reconcileRunnerHostnameChange($previousAssetCode, $rawMetadata);
            }

            return [
                'status' => $match['is_new_device'] ? 'created' : 'updated',
                'device_id' => $device->id,
                'scan_id' => $scan->id,
                'raw_hash' => $rawHash,
                'match_reason' => $match['reason'],
                'match_score' => $match['score'],
                'change_count' => count($changes),
            ];
        });
    }

    private function createDevice(array $payload): Device
    {
        $deviceUid = $this->normalizer->sha256Hex(implode('|', [
            $payload['canonical_device_id'] ?? '',
            $payload['manufacturer'] ?? '',
            $payload['model'] ?? '',
            $payload['asset_code'] ?? '',
            $payload['scan_time'] ?? '',
        ]));

        return Device::query()->create([
            'device_uid' => $deviceUid,
            'first_seen_at' => $payload['scan_time'],
            'last_seen_at' => $payload['scan_time'],
            'current_asset_code' => $payload['asset_code'] ?? '',
            'current_user_name' => $payload['user_name'] ?? '',
            ...$this->assignmentOverrides->currentColumnsForManualValues([], $payload),
            'manufacturer' => $payload['manufacturer'] ?? '',
            'model' => $payload['model'] ?? '',
            'system_type' => $payload['system_type'] ?? '',
            'serial_no' => $payload['serial_no'] ?? '',
            'motherboard_serial' => $payload['motherboard_serial'] ?? '',
            'system_uuid' => $payload['system_uuid'] ?? '',
            'mac_address' => $payload['mac_address'] ?? '',
            'hardware_hash' => $payload['hardware_hash'] ?? '',
        ]);
    }

    private function updateDeviceCurrentState(Device $device, array $payload): void
    {
        $device->forceFill([
            'last_seen_at' => $payload['scan_time'],
            'current_asset_code' => $payload['asset_code'] ?? '',
            'current_user_name' => $payload['user_name'] ?? '',
            ...$this->assignmentOverrides->currentColumnsForDevice($device, $payload),
            'manufacturer' => $payload['manufacturer'] ?? '',
            'model' => $payload['model'] ?? '',
            'system_type' => $payload['system_type'] ?? '',
            'serial_no' => $payload['serial_no'] ?? '',
            'motherboard_serial' => $payload['motherboard_serial'] ?? '',
            'system_uuid' => $payload['system_uuid'] ?? '',
            'mac_address' => $payload['mac_address'] ?? '',
            'hardware_hash' => $payload['hardware_hash'] ?? '',
        ])->save();
    }

    private function upsertIdentities(Device $device, array $payload): void
    {
        foreach ($this->normalizer->extractIdentityMap($payload) as $identityType => $identity) {
            $existing = DeviceIdentity::query()
                ->where('identity_type', $identityType)
                ->where('identity_value', $identity['value'])
                ->first();

            if ($existing !== null) {
                $existing->forceFill([
                    'last_seen_at' => $payload['scan_time'],
                    'weight' => $identity['weight'],
                ])->save();
                continue;
            }

            DeviceIdentity::query()->create([
                'device_id' => $device->id,
                'identity_type' => $identityType,
                'identity_value' => $identity['value'],
                'weight' => $identity['weight'],
                'first_seen_at' => $payload['scan_time'],
                'last_seen_at' => $payload['scan_time'],
            ]);
        }
    }

    private function latestSnapshotForDevice(Device $device): ?array
    {
        $scan = $device->scans()
            ->with('snapshot')
            ->orderByDesc('scan_time')
            ->orderByDesc('id')
            ->first();

        return $scan?->snapshot?->snapshot_json;
    }

    private function createSnapshot(DeviceScan $scan, array $payload): void
    {
        $snapshotJson = $payload;
        unset($snapshotJson['storage_health_json']);

        $scan->snapshot()->create([
            ...Arr::only($payload, CsvNormalizer::SNAPSHOT_FIELDS),
            'snapshot_json' => $snapshotJson,
        ]);
    }

    private function createNetworkObservation(Device $device, DeviceScan $scan, array $payload): void
    {
        NetworkObservation::query()->create([
            'device_id' => $device->id,
            'device_scan_id' => $scan->id,
            'mac_address' => $payload['mac_address'] ?? '',
            'ip_address' => $payload['ip_address'] ?? '',
            'ip_prefix' => $payload['ip_prefix'] ?? '',
            'prefix_length' => $payload['prefix_length'] ?? '',
            'default_gateway' => $payload['default_gateway'] ?? '',
            'dns_suffix' => $payload['dns_suffix'] ?? '',
            'network_interface' => $payload['network_interface'] ?? '',
            'wifi_ssid' => $payload['wifi_ssid'] ?? '',
            'observed_at' => $payload['scan_time'],
        ]);
    }

    private function createPeripherals(Device $device, DeviceScan $scan, array $payload): void
    {
        foreach ($this->splitSemicolonList($payload['installed_printers'] ?? '') as $printer) {
            Peripheral::query()->create([
                'device_id' => $device->id,
                'device_scan_id' => $scan->id,
                'peripheral_type' => 'printer',
                'name' => $printer,
                'detail' => $printer,
                'source' => 'installed_printers',
                'observed_at' => $payload['scan_time'],
            ]);
        }

        foreach ($this->splitSemicolonList($payload['present_peripherals'] ?? '') as $peripheral) {
            [$type, $name] = str_contains($peripheral, ':')
                ? array_map('trim', explode(':', $peripheral, 2))
                : ['peripheral', $peripheral];

            Peripheral::query()->create([
                'device_id' => $device->id,
                'device_scan_id' => $scan->id,
                'peripheral_type' => strtolower($type ?: 'peripheral'),
                'name' => $name ?: $peripheral,
                'detail' => $peripheral,
                'source' => 'present_peripherals',
                'observed_at' => $payload['scan_time'],
            ]);
        }
    }

    private function createStorageHealthObservations(Device $device, DeviceScan $scan, array $payload): void
    {
        $currentDiskKeys = [];
        foreach ($this->storageHealthRisk->fromPayload($payload) as $row) {
            $diskKey = (string) ($row['disk_key'] ?? '');
            $previous = null;
            if ($diskKey !== '') {
                $previous = StorageHealthObservation::query()
                    ->where('device_id', $device->id)
                    ->where('disk_key', $diskKey)
                    ->orderByDesc('observed_at')
                    ->orderByDesc('id')
                    ->first();
            }

            $normalized = $this->storageHealthRisk->normalizeObservation($row, $previous);

            if ($previous === null && $diskKey === '') {
                $previous = StorageHealthObservation::query()
                    ->where('device_id', $device->id)
                    ->where('disk_key', $normalized['disk_key'])
                    ->orderByDesc('observed_at')
                    ->orderByDesc('id')
                    ->first();

                $normalized = $this->storageHealthRisk->normalizeObservation($row, $previous);
            }

            StorageHealthObservation::query()->create([
                'device_id' => $device->id,
                'device_scan_id' => $scan->id,
                ...$normalized,
                'observed_at' => $payload['scan_time'],
            ]);
            $currentDiskKeys[] = $normalized['disk_key'];
        }

        if ($currentDiskKeys === []) {
            return;
        }

        $previousByDisk = StorageHealthObservation::query()
            ->where('device_id', $device->id)
            ->where('device_scan_id', '!=', $scan->id)
            ->orderByDesc('observed_at')
            ->orderByDesc('id')
            ->get()
            ->unique('disk_key');

        foreach ($previousByDisk as $previous) {
            if (in_array($previous->disk_key, $currentDiskKeys, true) || $previous->disk_key === 'legacy-ssd-summary') {
                continue;
            }

            if ($this->isRemovableStorageObservation($previous)) {
                continue;
            }

            StorageHealthObservation::query()->create([
                'device_id' => $device->id,
                'device_scan_id' => $scan->id,
                'disk_key' => $previous->disk_key,
                'disk_model' => $previous->disk_model,
                'disk_serial' => $previous->disk_serial,
                'disk_type' => $previous->disk_type,
                'interface_type' => $previous->interface_type,
                'capacity_gb' => $previous->capacity_gb,
                'risk_level' => 'critical',
                'risk_score' => 94,
                'risk_reasons' => ['Disk was present in the previous scan but is missing from the current scan.'],
                'recommended_action' => 'Backup immediately and prepare replacement',
                'source_method' => 'inventory_trend',
                'storage_health_detail' => 'Disk disappeared unexpectedly from latest runner storage inventory.',
                'observed_at' => $payload['scan_time'],
            ]);
        }
    }

    private function isRemovableStorageObservation(StorageHealthObservation $observation): bool
    {
        $text = strtolower(implode(' ', [
            (string) $observation->disk_model,
            (string) $observation->disk_type,
            (string) $observation->interface_type,
            (string) $observation->source_method,
        ]));

        foreach (['usb', 'external', 'removable', 'portable', 'card reader'] as $marker) {
            if (str_contains($text, $marker)) {
                return true;
            }
        }

        return false;
    }

    private function insertChanges(Device $device, DeviceScan $scan, array $payload, array $changes): void
    {
        foreach ($changes as $change) {
            ChangeLog::query()->create([
                'device_id' => $device->id,
                'device_scan_id' => $scan->id,
                'change_group' => $change['change_group'],
                'severity' => $change['severity'],
                'field_name' => $change['field_name'],
                'old_value' => $change['old_value'] ?? '',
                'new_value' => $change['new_value'] ?? '',
                'observed_at' => $payload['scan_time'],
                'observed_site' => $device->current_site ?? '',
                'observed_department' => $device->current_department ?? '',
                'observed_room' => $payload['room'] ?? '',
            ]);
        }
    }

    private function updateAssignmentHistory(Device $device, DeviceScan $scan, array $payload): void
    {
        $current = [
            'user_name' => $this->normalizer->normalizeWhitespace($payload['user_name'] ?? ''),
            'department' => $this->normalizer->normalizeWhitespace($device->current_department),
            'location' => $this->normalizer->normalizeWhitespace($device->current_location),
            'room' => $this->normalizer->normalizeWhitespace($device->current_room),
            'site_estimated' => $this->normalizer->normalizeWhitespace($device->current_site),
        ];

        $open = DeviceAssignment::query()
            ->where('device_id', $device->id)
            ->whereNull('ended_at')
            ->orderByDesc('started_at')
            ->first();

        if ($open !== null) {
            $same = collect($current)->every(
                fn (string $value, string $key): bool => $this->normalizer->normalizeWhitespace($open->{$key}) === $value,
            );

            if ($same) {
                return;
            }

            $open->forceFill([
                'ended_at' => $payload['scan_time'],
                'end_scan_id' => $scan->id,
            ])->save();
        }

        DeviceAssignment::query()->create([
            'device_id' => $device->id,
            ...$current,
            'started_at' => $payload['scan_time'],
            'start_scan_id' => $scan->id,
        ]);
    }

    private function saveRawFile(string $rawHash, string $rawFilename, string $archivedPath, string $rawFormat, ?array $rawMetadata): void
    {
        RawFile::query()->firstOrCreate(
            ['raw_hash' => $rawHash],
            [
                'original_filename' => $rawFilename,
                'upload_id' => $this->normalizer->normalizeWhitespace($rawMetadata['upload_id'] ?? '') ?: null,
                'saved_path' => $archivedPath,
                'raw_format' => $rawFormat,
                'received_at' => now(),
                'metadata_json' => $rawMetadata ?? [],
            ],
        );
    }

    private function upsertRunnerFromMetadata(?array $rawMetadata, array $payload): void
    {
        if (($rawMetadata['runner_id'] ?? '') === '') {
            return;
        }

        $state = $rawMetadata;
        $state['last_successful_inventory_at'] ??= $payload['scan_time'] ?? '';
        $state['last_inventory_status'] ??= 'success';
        $state['last_upload_status'] ??= 'uploaded';

        $this->runnerStatus->upsert($state);
    }

    private function reconcileRunnerHostnameChange(string $previousAssetCode, ?array $rawMetadata): void
    {
        $newRunnerId = $this->normalizer->normalizeWhitespace($rawMetadata['runner_id'] ?? '');
        if ($previousAssetCode === '' || $newRunnerId === '') {
            return;
        }

        $this->runnerIdentityReconciler->reconcileHostnameChange($previousAssetCode, $newRunnerId);
    }

    private function splitSemicolonList(string $value): array
    {
        return array_values(array_filter(array_map(
            fn (string $item): string => $this->normalizer->normalizeWhitespace($item),
            explode(';', $value),
        )));
    }
}
