<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateDeviceAssignmentRequest;
use App\Models\ChangeLog;
use App\Models\Device;
use App\Models\DeviceAssignment;
use App\Models\DeviceScan;
use App\Models\HardwareSnapshot;
use App\Services\Inventory\DeviceAssignmentOverrideService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;

class DeviceController extends Controller
{
    public function index(Request $request): View
    {
        $query = trim((string) $request->query('q', ''));
        $cpu = trim((string) $request->query('cpu', ''));
        $gpu = trim((string) $request->query('gpu', ''));
        $disk = trim((string) $request->query('disk', ''));
        $ramGb = trim((string) $request->query('ram_gb', ''));
        $ramMinGb = trim((string) $request->query('ram_min_gb', ''));
        $sort = trim((string) $request->query('sort', 'last_seen_at'));
        $direction = strtolower(trim((string) $request->query('direction', 'desc')));
        if (! in_array($direction, ['asc', 'desc'], true)) {
            $direction = 'desc';
        }

        $devices = Device::query()
            ->select('devices.*')
            ->selectSub($this->latestSnapshotFieldSubquery('cpu'), 'latest_cpu')
            ->selectSub($this->latestSnapshotFieldSubquery('gpu'), 'latest_gpu')
            ->selectSub($this->latestSnapshotFieldSubquery('disk'), 'latest_disk')
            ->selectSub($this->latestSnapshotFieldSubquery('ram_gb'), 'latest_ram_gb')
            ->with(['latestNetworkObservation', 'latestScan.snapshot'])
            ->when($query !== '', function ($builder) use ($query): void {
                $builder->where(function ($nested) use ($query): void {
                    $like = "%{$query}%";
                    $nested->where('current_asset_code', 'like', $like)
                        ->orWhere('current_user_name', 'like', $like)
                        ->orWhere('current_department', 'like', $like)
                        ->orWhere('manufacturer', 'like', $like)
                        ->orWhere('model', 'like', $like)
                        ->orWhere('current_location', 'like', $like)
                        ->orWhere('current_site', 'like', $like);
                });
            })
            ->when($cpu !== '', function ($builder) use ($cpu): void {
                $builder->whereHas('latestScan.snapshot', function ($snapshotQuery) use ($cpu): void {
                    $snapshotQuery->where('cpu', 'like', "%{$cpu}%");
                });
            })
            ->when($gpu !== '', function ($builder) use ($gpu): void {
                $builder->whereHas('latestScan.snapshot', function ($snapshotQuery) use ($gpu): void {
                    $snapshotQuery->where(function ($nested) use ($gpu): void {
                        $like = "%{$gpu}%";
                        $nested->where('gpu', 'like', $like)
                            ->orWhere('gpu_detail', 'like', $like);
                    });
                });
            })
            ->when($disk !== '', function ($builder) use ($disk): void {
                $builder->whereHas('latestScan.snapshot', function ($snapshotQuery) use ($disk): void {
                    $snapshotQuery->where(function ($nested) use ($disk): void {
                        $like = "%{$disk}%";
                        $nested->where('disk', 'like', $like)
                            ->orWhere('disk_detail', 'like', $like);
                    });
                });
            })
            ->when($ramGb !== '', function ($builder) use ($ramGb): void {
                $builder->whereHas('latestScan.snapshot', function ($snapshotQuery) use ($ramGb): void {
                    $snapshotQuery->where('ram_gb', $ramGb);
                });
            })
            ->when($ramMinGb !== '' && is_numeric($ramMinGb), function ($builder) use ($ramMinGb): void {
                $builder->whereHas('latestScan.snapshot', function ($snapshotQuery) use ($ramMinGb): void {
                    $snapshotQuery->whereRaw('CAST(ram_gb AS INTEGER) >= ?', [(int) $ramMinGb]);
                });
            })
            ->tap(fn ($builder) => $this->applySort($builder, $sort, $direction))
            ->paginate(50)
            ->withQueryString();

        return view('admin.devices.index', compact('devices', 'query', 'cpu', 'gpu', 'disk', 'ramGb', 'ramMinGb', 'sort', 'direction'));
    }

    public function show(Device $device): View
    {
        $relations = [
            'identities' => fn ($query) => $query->orderByDesc('weight'),
            'scans.snapshot',
            'scans.rawFile',
            'changes' => fn ($query) => $query->orderByDesc('observed_at'),
            'assignments' => fn ($query) => $query->orderByDesc('started_at'),
            'networkObservations' => fn ($query) => $query->orderByDesc('observed_at'),
            'peripherals' => fn ($query) => $query->orderBy('peripheral_type')->orderBy('name'),
        ];

        if (Schema::hasTable('storage_health_observations')) {
            $relations['storageHealthObservations'] = fn ($query) => $query->orderByDesc('observed_at')->orderBy('disk_key');
        }

        $device->load($relations);

        return view('admin.devices.show', compact('device'));
    }

    public function updateAssignment(
        UpdateDeviceAssignmentRequest $request,
        Device $device,
        DeviceAssignmentOverrideService $assignmentOverrides,
    ): RedirectResponse {
        $latestScan = $device->scans()
            ->with('snapshot')
            ->orderByDesc('scan_time')
            ->orderByDesc('id')
            ->first();

        $scannerPayload = $latestScan?->snapshot?->snapshot_json ?? [];
        $oldValues = $assignmentOverrides->currentAssignmentValues($device);
        $manualValues = $request->normalizedAssignment();
        $currentColumns = $assignmentOverrides->currentColumnsForManualValues($manualValues, $scannerPayload);

        $device->forceFill([
            ...$assignmentOverrides->manualColumns($manualValues),
            ...$currentColumns,
            'manual_assignment_updated_at' => now(),
        ])->save();

        $device->refresh();
        $newValues = $assignmentOverrides->currentAssignmentValues($device);

        if ($latestScan !== null) {
            $this->recordManualAssignmentChanges($device, $latestScan, $oldValues, $newValues);
            $this->syncAssignmentTimeline($device, $latestScan, $newValues);
        }

        return redirect()
            ->route('admin.devices.show', $device)
            ->with('status', 'Device department/site/location/room updated.');
    }

    private function recordManualAssignmentChanges(Device $device, DeviceScan $scan, array $oldValues, array $newValues): void
    {
        $fields = [
            'department' => ['field_name' => 'department', 'change_group' => 'assignment'],
            'site' => ['field_name' => 'site_estimated', 'change_group' => 'location'],
            'location' => ['field_name' => 'location', 'change_group' => 'location'],
            'room' => ['field_name' => 'room', 'change_group' => 'location'],
        ];

        foreach ($fields as $key => $meta) {
            $old = (string) ($oldValues[$key] ?? '');
            $new = (string) ($newValues[$key] ?? '');

            if ($old === $new) {
                continue;
            }

            ChangeLog::query()->create([
                'device_id' => $device->id,
                'device_scan_id' => $scan->id,
                'change_group' => $meta['change_group'],
                'severity' => 'minor',
                'field_name' => $meta['field_name'],
                'old_value' => $old,
                'new_value' => $new,
                'observed_at' => now(),
                'observed_site' => $newValues['site'] ?? '',
                'observed_department' => $newValues['department'] ?? '',
                'observed_room' => $newValues['room'] ?? '',
            ]);
        }
    }

    private function syncAssignmentTimeline(Device $device, DeviceScan $scan, array $assignmentValues): void
    {
        $current = [
            'user_name' => trim((string) $device->current_user_name),
            'department' => $assignmentValues['department'] ?? '',
            'location' => $assignmentValues['location'] ?? '',
            'room' => $assignmentValues['room'] ?? '',
            'site_estimated' => $assignmentValues['site'] ?? '',
        ];

        $open = DeviceAssignment::query()
            ->where('device_id', $device->id)
            ->whereNull('ended_at')
            ->orderByDesc('started_at')
            ->first();

        if ($open !== null) {
            $same = collect($current)->every(
                fn (string $value, string $key): bool => trim((string) $open->{$key}) === $value,
            );

            if ($same) {
                return;
            }

            $open->forceFill([
                'ended_at' => now(),
                'end_scan_id' => $scan->id,
            ])->save();
        }

        DeviceAssignment::query()->create([
            'device_id' => $device->id,
            ...$current,
            'started_at' => now(),
            'start_scan_id' => $scan->id,
        ]);
    }

    private function latestSnapshotFieldSubquery(string $field): \Illuminate\Database\Query\Builder
    {
        return HardwareSnapshot::query()
            ->select($field)
            ->join('device_scans', 'device_scans.id', '=', 'hardware_snapshots.device_scan_id')
            ->whereColumn('device_scans.device_id', 'devices.id')
            ->orderByDesc('device_scans.scan_time')
            ->orderByDesc('device_scans.id')
            ->limit(1)
            ->getQuery();
    }

    private function applySort(mixed $builder, string $sort, string $direction): void
    {
        match ($sort) {
            'asset' => $builder->orderBy('current_asset_code', $direction),
            'user' => $builder->orderBy('current_user_name', $direction),
            'department' => $builder->orderBy('current_department', $direction),
            'site' => $builder->orderBy('current_site', $direction),
            'cpu' => $builder->orderBy('latest_cpu', $direction),
            'gpu' => $builder->orderBy('latest_gpu', $direction),
            'disk' => $builder->orderBy('latest_disk', $direction),
            'ram' => $builder->orderByRaw('CAST(latest_ram_gb AS INTEGER) ' . strtoupper($direction)),
            'hardware' => $builder->orderBy('manufacturer', $direction)->orderBy('model', $direction),
            default => $builder->orderBy('last_seen_at', $direction),
        };

        $builder->orderByDesc('last_seen_at')->orderBy('id');
    }
}
