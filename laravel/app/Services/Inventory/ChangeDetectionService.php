<?php

namespace App\Services\Inventory;

class ChangeDetectionService
{
    public const TRACKED_FIELDS = [
        'asset_code' => 'assignment',
        'user_name' => 'assignment',
        'cpu' => 'hardware',
        'gpu' => 'hardware',
        'gpu_detail' => 'hardware',
        'ram_gb' => 'hardware',
        'ram_manufacturers' => 'hardware',
        'ram_part_numbers' => 'hardware',
        'ram_serial_numbers' => 'hardware',
        'ram_speeds_mhz' => 'hardware',
        'ram_types' => 'hardware',
        'ram_slots' => 'hardware',
        'ram_detail' => 'hardware',
        'disk' => 'hardware',
        'disk_detail' => 'hardware',
        'ssd_tbw_bytes' => 'hardware',
        'ssd_tbw_gb' => 'hardware',
        'ssd_percentage_used' => 'hardware',
        'ssd_power_on_hours' => 'hardware',
        'ssd_health_source' => 'hardware',
        'ssd_health_detail' => 'hardware',
        'motherboard' => 'hardware',
        'motherboard_serial' => 'hardware',
        'serial_no' => 'hardware',
        'system_uuid' => 'hardware',
        'mac_address' => 'network',
        'ip_prefix' => 'network',
        'default_gateway' => 'network',
        'dns_suffix' => 'network',
        'wifi_ssid' => 'network',
        'installed_printers' => 'peripheral',
        'present_peripherals' => 'peripheral',
    ];

    public function __construct(private readonly CsvNormalizer $normalizer)
    {
    }

    public function detectChanges(?array $previous, array $current): array
    {
        if ($previous === null) {
            return [];
        }

        $changes = [];
        foreach (self::TRACKED_FIELDS as $field => $group) {
            $oldValue = $this->normalizer->normalizeWhitespace($previous[$field] ?? '');
            $newValue = $this->normalizer->normalizeWhitespace($current[$field] ?? '');

            if ($oldValue === $newValue) {
                continue;
            }

            $changes[] = [
                'change_group' => $group,
                'severity' => $this->severityFor($group, $field),
                'field_name' => $field,
                'old_value' => $oldValue,
                'new_value' => $newValue,
            ];
        }

        return $changes;
    }

    private function severityFor(string $group, string $field): string
    {
        if (in_array($field, ['motherboard', 'motherboard_serial', 'serial_no', 'system_uuid'], true)) {
            return 'critical';
        }

        if ($group === 'hardware') {
            return 'medium';
        }

        if ($group === 'assignment' && in_array($field, ['asset_code', 'user_name'], true)) {
            return 'medium';
        }

        return 'minor';
    }
}
