<?php

namespace App\Services\Inventory;

use App\Support\InventoryTime;
use Carbon\CarbonImmutable;

class CsvNormalizer
{
    public const BAD_IDENTITY_VALUES = [
        'DEFAULT STRING',
        'TO BE FILLED BY O.E.M.',
        'TO BE FILLED BY OEM',
        'SYSTEM SERIAL NUMBER',
        'SERIALNUMBER',
        'SERIAL NUMBER',
        'UNKNOWN',
        'NONE',
        'NOT APPLICABLE',
        'NOT AVAILABLE',
        'NO SERIAL',
        'N/A',
        'INVALID',
        'OEM',
        'DEFAULT',
        '123456789',
        '1234567890',
        '0123456789',
        '01234567890',
        'FFFFFFFF',
        '0000000000',
        '00000000',
        'DEFAULTSTRING',
    ];

    public const CSV_TO_INTERNAL = [
        'Asset_Code' => 'asset_code',
        'Department' => 'department',
        'Location' => 'location',
        'Room' => 'room',
        'Site_Estimated' => 'site_estimated',
        'Site_Method' => 'site_method',
        'Site_Confidence' => 'site_confidence',
        'User' => 'user_name',
        'User_Raw' => 'user_raw',
        'Manufacturer' => 'manufacturer',
        'Model' => 'model',
        'System_Type' => 'system_type',
        'Motherboard' => 'motherboard',
        'Motherboard_Manufacturer' => 'motherboard_manufacturer',
        'Motherboard_Product' => 'motherboard_product',
        'Motherboard_Version' => 'motherboard_version',
        'Motherboard_Serial' => 'motherboard_serial',
        'CPU' => 'cpu',
        'GPU' => 'gpu',
        'GPU_Detail' => 'gpu_detail',
        'RAM_GB' => 'ram_gb',
        'RAM_Slots_Used' => 'ram_slots_used',
        'RAM_Manufacturers' => 'ram_manufacturers',
        'RAM_Part_Numbers' => 'ram_part_numbers',
        'RAM_Serial_Numbers' => 'ram_serial_numbers',
        'RAM_Speeds_MHz' => 'ram_speeds_mhz',
        'RAM_Types' => 'ram_types',
        'RAM_Slots' => 'ram_slots',
        'RAM_Detail' => 'ram_detail',
        'Disk' => 'disk',
        'Disk_Detail' => 'disk_detail',
        'SSD_TBW_Bytes' => 'ssd_tbw_bytes',
        'SSD_TBW_GB' => 'ssd_tbw_gb',
        'SSD_Percentage_Used' => 'ssd_percentage_used',
        'SSD_Power_On_Hours' => 'ssd_power_on_hours',
        'SSD_Health_Source' => 'ssd_health_source',
        'SSD_Health_Detail' => 'ssd_health_detail',
        'Storage_Health_JSON' => 'storage_health_json',
        'OS' => 'os',
        'OS_Version' => 'os_version',
        'OS_Build' => 'os_build',
        'OS_Install_Date' => 'os_install_date',
        'BIOS_Serial_Raw' => 'bios_serial_raw',
        'Serial_No' => 'serial_no',
        'BIOS_Version' => 'bios_version',
        'System_UUID' => 'system_uuid',
        'MAC_Address' => 'mac_address',
        'IP_Address' => 'ip_address',
        'IP_Prefix' => 'ip_prefix',
        'Prefix_Length' => 'prefix_length',
        'Default_Gateway' => 'default_gateway',
        'DNS_Suffix' => 'dns_suffix',
        'Network_Interface' => 'network_interface',
        'WiFi_SSID' => 'wifi_ssid',
        'Installed_Printers' => 'installed_printers',
        'Present_Peripherals' => 'present_peripherals',
        'Peripheral_Count' => 'peripheral_count',
        'Canonical_Device_ID' => 'canonical_device_id',
        'HardwareHash' => 'hardware_hash',
        'Scan_Time' => 'scan_time',
        'Scan_Time_Display' => 'scan_time_display',
        'Battery_Present' => 'battery_present',
        'Battery_Health_Percent' => 'battery_health_percent',
        'Hotfix_Count' => 'hotfix_count',
        'Last_Hotfix_Date' => 'last_hotfix_date',
        'BitLocker_System_Drive' => 'bitlocker_system_drive',
        'TPM_Enabled' => 'tpm_enabled',
        'TPM_Activated' => 'tpm_activated',
        'TPM_Spec_Version' => 'tpm_spec_version',
        'Installed_Software_Count' => 'installed_software_count',
    ];

    /** Optional security/health fields; kept in snapshot_json only, absent in old CSVs. */
    public const EXTRA_FIELDS = [
        'battery_present',
        'battery_health_percent',
        'hotfix_count',
        'last_hotfix_date',
        'bitlocker_system_drive',
        'tpm_enabled',
        'tpm_activated',
        'tpm_spec_version',
        'installed_software_count',
    ];

    public const SNAPSHOT_FIELDS = [
        'asset_code',
        'department',
        'location',
        'room',
        'site_estimated',
        'site_method',
        'site_confidence',
        'user_name',
        'user_raw',
        'manufacturer',
        'model',
        'system_type',
        'motherboard',
        'motherboard_manufacturer',
        'motherboard_product',
        'motherboard_version',
        'motherboard_serial',
        'cpu',
        'gpu',
        'gpu_detail',
        'ram_gb',
        'ram_slots_used',
        'ram_manufacturers',
        'ram_part_numbers',
        'ram_serial_numbers',
        'ram_speeds_mhz',
        'ram_types',
        'ram_slots',
        'ram_detail',
        'disk',
        'disk_detail',
        'ssd_tbw_bytes',
        'ssd_tbw_gb',
        'ssd_percentage_used',
        'ssd_power_on_hours',
        'ssd_health_source',
        'ssd_health_detail',
        'os',
        'os_version',
        'os_build',
        'os_install_date',
        'bios_serial_raw',
        'serial_no',
        'bios_version',
        'system_uuid',
        'mac_address',
        'ip_address',
        'ip_prefix',
        'prefix_length',
        'default_gateway',
        'dns_suffix',
        'network_interface',
        'wifi_ssid',
        'installed_printers',
        'present_peripherals',
        'peripheral_count',
        'canonical_device_id',
        'hardware_hash',
        'scan_time_display',
    ];

    public const IDENTITY_WEIGHTS = [
        'system_uuid' => 100,
        'motherboard_serial' => 90,
        'serial_no' => 75,
        'mac_address' => 70,
        'asset_code' => 35,
    ];

    public function normalizeWhitespace(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        return trim((string) preg_replace('/\s+/', ' ', trim((string) $value)));
    }

    public function safeUpper(mixed $value): string
    {
        return mb_strtoupper($this->normalizeWhitespace($value));
    }

    public function isGenericIdentityValue(mixed $value): bool
    {
        $upper = $this->safeUpper($value);

        if ($upper === '') {
            return true;
        }

        if (in_array($upper, self::BAD_IDENTITY_VALUES, true)) {
            return true;
        }

        if (preg_match('/^(DEFAULT|UNKNOWN|OEM|NONE|SERIAL)/', $upper) === 1) {
            return true;
        }

        if (preg_match('/^0+$/', $upper) === 1 || preg_match('/^F+$/', $upper) === 1) {
            return true;
        }

        if (str_contains($upper, 'SMARTCTL')
            || str_contains($upper, 'PLATFORM_INFO')
            || str_contains($upper, '"ARGV"')
            || str_contains($upper, 'NVME_SMART_HEALTH_INFORMATION_LOG')) {
            return true;
        }

        return false;
    }

    public function normalizeIdentityValue(mixed $value): string
    {
        $normalized = $this->normalizeWhitespace($value);

        return $this->isGenericIdentityValue($normalized) ? '' : $normalized;
    }

    public function sha256Hex(string $text): string
    {
        return hash('sha256', $text);
    }

    public function parseScanTime(?string $value): string
    {
        $raw = $this->normalizeWhitespace($value);

        if ($raw === '') {
            return CarbonImmutable::now(config('app.timezone'))->format('Y-m-d H:i:s');
        }

        $parsed = InventoryTime::parseForStorage($raw);
        if ($parsed !== null) {
            return $parsed->format('Y-m-d H:i:s');
        }

        return CarbonImmutable::now(config('app.timezone'))->format('Y-m-d H:i:s');
    }

    /**
     * Generic serial placeholders are intentionally nulled before identity scoring.
     */
    public function normalizePayload(array $raw): array
    {
        $data = [];
        foreach ($raw as $key => $value) {
            $data[(string) $key] = $this->normalizeWhitespace($value);
        }

        $data['scan_time'] = $this->parseScanTime($data['scan_time'] ?? ($data['scan_time_display'] ?? null));

        foreach (['serial_no', 'motherboard_serial', 'system_uuid', 'mac_address'] as $key) {
            $data[$key] = $this->normalizeIdentityValue($data[$key] ?? '');
        }

        if (($data['canonical_device_id'] ?? '') === '') {
            $parts = [];
            foreach ([
                'serial_no' => 'SERIAL',
                'motherboard_serial' => 'MBSN',
                'system_uuid' => 'UUID',
                'mac_address' => 'MAC',
                'manufacturer' => 'MFG',
                'model' => 'MODEL',
                'motherboard' => 'MB',
                'cpu' => 'CPU',
                'ram_detail' => 'RAM',
                'disk_detail' => 'DISK',
                'gpu_detail' => 'GPU',
            ] as $field => $label) {
                $value = $this->normalizeWhitespace($data[$field] ?? '');
                if ($value !== '') {
                    $parts[] = "{$label}:{$value}";
                }
            }

            $data['canonical_device_id'] = $this->sha256Hex(implode('|', $parts));
        }

        if (($data['hardware_hash'] ?? '') === '') {
            $data['hardware_hash'] = $this->sha256Hex(implode('|', [
                $data['manufacturer'] ?? '',
                $data['model'] ?? '',
                $data['cpu'] ?? '',
                $data['ram_gb'] ?? '',
                $data['disk'] ?? '',
                $data['gpu'] ?? '',
                $data['mac_address'] ?? '',
                $data['serial_no'] ?? '',
            ]));
        }

        foreach (self::SNAPSHOT_FIELDS as $field) {
            $data[$field] ??= '';
        }

        return $data;
    }

    public function csvTextToPayloads(string $text): array
    {
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, preg_replace('/^\xEF\xBB\xBF/', '', $text) ?? $text);
        rewind($stream);

        $headers = fgetcsv($stream);
        if ($headers === false) {
            fclose($stream);
            return [];
        }

        $payloads = [];
        while (($row = fgetcsv($stream)) !== false) {
            $mapped = [];
            foreach ($headers as $index => $header) {
                $key = self::CSV_TO_INTERNAL[$header] ?? $header;
                $mapped[$key] = $this->normalizeWhitespace($row[$index] ?? '');
            }

            $payloads[] = $this->normalizePayload($mapped);
        }

        fclose($stream);

        return $payloads;
    }

    public function payloadToJson(array $payload): string
    {
        ksort($payload);

        return json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public function computeRawHash(string $rawText, string $rawFormat): string
    {
        return $this->sha256Hex("{$rawFormat}|{$rawText}");
    }

    public function extractIdentityMap(array $payload): array
    {
        $identityMap = [];
        foreach (self::IDENTITY_WEIGHTS as $field => $weight) {
            $value = $this->normalizeWhitespace($payload[$field] ?? '');
            if ($value === '') {
                continue;
            }

            if (in_array($field, ['serial_no', 'motherboard_serial', 'system_uuid', 'mac_address'], true)
                && $this->isGenericIdentityValue($value)) {
                continue;
            }

            $identityMap[$field] = [
                'value' => $value,
                'weight' => $weight,
            ];
        }

        return $identityMap;
    }
}
