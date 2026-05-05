<?php

namespace App\Console\Commands;

use App\Models\Device;
use App\Services\Inventory\DeviceAssignmentOverrideService;
use Illuminate\Console\Command;

class RebuildCurrentState extends Command
{
    protected $signature = 'inventory:rebuild-current-state {--dry-run : Show what would be updated without writing changes}';

    protected $description = 'Rebuild device current-state projection columns from the latest hardware snapshot';

    public function __construct(private readonly DeviceAssignmentOverrideService $assignmentOverrides)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $updated = 0;
        $skipped = 0;

        Device::query()
            ->with(['scans.snapshot'])
            ->orderBy('id')
            ->chunkById(100, function ($devices) use ($dryRun, &$updated, &$skipped): void {
                foreach ($devices as $device) {
                    $latestScan = $device->scans
                        ->filter(fn ($scan): bool => $scan->snapshot !== null)
                        ->sortByDesc(fn ($scan): string => sprintf('%s-%010d', optional($scan->scan_time)->format('YmdHis') ?? '', $scan->id))
                        ->first();

                    if ($latestScan === null || $latestScan->snapshot === null) {
                        $skipped++;
                        continue;
                    }

                    $payload = $latestScan->snapshot->snapshot_json ?? [];
                    $values = [
                        'last_seen_at' => $latestScan->scan_time,
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
                    ];

                    $dirty = collect($values)->contains(fn ($value, string $key): bool => (string) $device->{$key} !== (string) $value);
                    if (! $dirty) {
                        continue;
                    }

                    $updated++;
                    if (! $dryRun) {
                        $device->forceFill($values)->save();
                    }
                }
            });

        $prefix = $dryRun ? 'Would update' : 'Updated';
        $this->line("{$prefix} devices: {$updated}");
        $this->line("Skipped without snapshots: {$skipped}");

        return self::SUCCESS;
    }
}
