<?php

namespace App\Services\Inventory;

use App\Models\Device;

class DeviceAssignmentOverrideService
{
    public function __construct(private readonly ClassificationRuleService $classificationRules)
    {
    }

    public function currentColumnsForManualValues(array $manual, array $scannerPayload): array
    {
        $manualDepartment = $this->nullable($manual['department'] ?? null);
        $manualSite = $this->nullable($manual['site'] ?? null);

        return [
            'current_department' => $manualDepartment ?? $this->departmentForAssetName($scannerPayload),
            'current_site' => $manualSite ?? $this->siteForScannerPayload($scannerPayload),
            'current_location' => $manual['location'] ?? '',
            'current_room' => $manual['room'] ?? '',
        ];
    }

    public function currentColumnsForDevice(Device $device, array $scannerPayload): array
    {
        return $this->currentColumnsForManualValues($this->manualValuesFromDevice($device), $scannerPayload);
    }

    public function manualColumns(array $manual): array
    {
        return [
            'manual_department' => $manual['department'] ?? null,
            'manual_site' => $manual['site'] ?? null,
            'manual_location' => $manual['location'] ?? null,
            'manual_room' => $manual['room'] ?? null,
        ];
    }

    public function manualValuesFromDevice(Device $device): array
    {
        return [
            'department' => $this->nullable($device->manual_department),
            'site' => $this->nullable($device->manual_site),
            'location' => $this->nullable($device->manual_location),
            'room' => $this->nullable($device->manual_room),
        ];
    }

    public function latestScannerPayload(Device $device): array
    {
        $scan = $device->scans()
            ->with('snapshot')
            ->orderByDesc('scan_time')
            ->orderByDesc('id')
            ->first();

        return $scan?->snapshot?->snapshot_json ?? [];
    }

    public function currentAssignmentValues(Device $device): array
    {
        return [
            'department' => $this->normalize($device->current_department),
            'site' => $this->normalize($device->current_site),
            'location' => $this->normalize($device->current_location),
            'room' => $this->normalize($device->current_room),
        ];
    }

    public function normalize(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        return trim((string) preg_replace('/\s+/', ' ', trim((string) $value)));
    }

    public function departmentForAssetName(array $scannerPayload): string
    {
        return $this->classificationRules->departmentForAssetName($scannerPayload['asset_code'] ?? '');
    }

    public function siteForScannerPayload(array $scannerPayload): string
    {
        return $this->classificationRules->siteForScannerValue($scannerPayload['site_estimated'] ?? ($scannerPayload['site_id'] ?? ''));
    }

    private function nullable(mixed $value): ?string
    {
        $normalized = $this->normalize($value);

        return $normalized === '' ? null : $normalized;
    }
}
