<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ChangeLog;
use App\Models\CollectorSite;
use App\Models\Device;
use App\Support\InventoryTime;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Illuminate\View\View;

class ReportController extends Controller
{
    public function index(): View
    {
        return view('admin.reports.index');
    }

    public function devices(Request $request): View
    {
        $filters = $this->filters($request);

        return view('admin.reports.devices', [
            'devices' => Device::query()
                ->when($filters['site'] !== '', fn ($query) => $query->where('current_site', $filters['site']))
                ->when($filters['department'] !== '', fn ($query) => $query->where('current_department', $filters['department']))
                ->orderBy('current_site')
                ->orderBy('current_department')
                ->orderBy('current_asset_code')
                ->paginate(100)
                ->withQueryString(),
            'filters' => $filters,
            'sites' => $this->deviceSites(),
            'departments' => $this->departments(),
        ]);
    }

    public function changes(Request $request): View
    {
        $filters = $this->filters($request);

        return view('admin.reports.changes', [
            'changes' => ChangeLog::query()
                ->with('device')
                ->when($filters['site'] !== '', fn ($query) => $query->where('observed_site', $filters['site']))
                ->when($filters['department'] !== '', fn ($query) => $query->where('observed_department', $filters['department']))
                ->when($filters['date_from'] !== '', fn ($query) => $query->whereDate('observed_at', '>=', $filters['date_from']))
                ->when($filters['date_to'] !== '', fn ($query) => $query->whereDate('observed_at', '<=', $filters['date_to']))
                ->orderByDesc('observed_at')
                ->paginate(100)
                ->withQueryString(),
            'filters' => $filters,
            'sites' => $this->changeSites(),
            'departments' => $this->changeDepartments(),
        ]);
    }

    public function sites(): View
    {
        $sites = $this->siteSummaryRows();

        return view('admin.reports.sites', compact('sites'));
    }

    public function exportDevices(Request $request): StreamedResponse
    {
        $filters = $this->filters($request);
        $devices = Device::query()
            ->when($filters['site'] !== '', fn ($query) => $query->where('current_site', $filters['site']))
            ->when($filters['department'] !== '', fn ($query) => $query->where('current_department', $filters['department']))
            ->orderBy('current_site')
            ->orderBy('current_department')
            ->orderBy('current_asset_code')
            ->cursor();

        return $this->streamCsv('inventory-devices.csv', [
            'site',
            'department',
            'asset_code',
            'user_name',
            'location',
            'room',
            'manufacturer',
            'model',
            'last_seen_at',
        ], function ($handle) use ($devices): void {
            foreach ($devices as $device) {
                fputcsv($handle, [
                    $device->current_site,
                    $device->current_department,
                    $device->current_asset_code,
                    $device->current_user_name,
                    $device->current_location,
                    $device->current_room,
                    $device->manufacturer,
                    $device->model,
                    InventoryTime::format($device->last_seen_at),
                ]);
            }
        });
    }

    public function exportChanges(Request $request): StreamedResponse
    {
        $filters = $this->filters($request);
        $changes = ChangeLog::query()
            ->with('device')
            ->when($filters['site'] !== '', fn ($query) => $query->where('observed_site', $filters['site']))
            ->when($filters['department'] !== '', fn ($query) => $query->where('observed_department', $filters['department']))
            ->when($filters['date_from'] !== '', fn ($query) => $query->whereDate('observed_at', '>=', $filters['date_from']))
            ->when($filters['date_to'] !== '', fn ($query) => $query->whereDate('observed_at', '<=', $filters['date_to']))
            ->orderByDesc('observed_at')
            ->orderByDesc('id')
            ->lazy(1000); // lazy (chunked) so with('device') eager loading applies, unlike cursor()

        return $this->streamCsv('inventory-changes.csv', [
            'observed_at',
            'asset_code',
            'site',
            'department',
            'room',
            'severity',
            'group',
            'field',
            'old_value',
            'new_value',
        ], function ($handle) use ($changes): void {
            foreach ($changes as $change) {
                fputcsv($handle, [
                    InventoryTime::format($change->observed_at),
                    $change->device?->current_asset_code,
                    $change->observed_site,
                    $change->observed_department,
                    $change->observed_room,
                    $change->severity,
                    $change->change_group,
                    $change->field_name,
                    $change->old_value,
                    $change->new_value,
                ]);
            }
        });
    }

    public function exportSites(): StreamedResponse
    {
        return $this->streamCsv('inventory-sites.csv', [
            'site_id',
            'site_name',
            'collectors',
            'runners',
            'devices',
            'changes',
        ], function ($handle): void {
            foreach ($this->siteSummaryRows() as $row) {
                fputcsv($handle, [
                    $row['site']->site_id,
                    $row['site']->site_name,
                    $row['site']->collectors_count,
                    $row['site']->runners_count,
                    $row['device_count'],
                    $row['change_count'],
                ]);
            }
        });
    }

    private function siteSummaryRows()
    {
        $deviceCounts = Device::query()->selectRaw('current_site as name, count(*) as total')->groupBy('current_site')->pluck('total', 'name');
        $changeCounts = ChangeLog::query()->selectRaw('observed_site as name, count(*) as total')->groupBy('observed_site')->pluck('total', 'name');

        return CollectorSite::query()
            ->withCount(['runners', 'collectors'])
            ->orderBy('site_id')
            ->get()
            ->map(function (CollectorSite $site) use ($deviceCounts, $changeCounts): array {
                // unique names, matching the old whereIn semantics when site_name === site_id
                $siteNames = array_unique(array_filter([$site->site_name, $site->site_id]));

                return [
                    'site' => $site,
                    'device_count' => (int) collect($siteNames)->sum(fn ($n) => $deviceCounts[$n] ?? 0),
                    'change_count' => (int) collect($siteNames)->sum(fn ($n) => $changeCounts[$n] ?? 0),
                ];
            });
    }

    private function streamCsv(string $filename, array $headers, callable $writeRows): StreamedResponse
    {
        return response()->streamDownload(function () use ($headers, $writeRows): void {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, $headers);
            $writeRows($handle);
            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    private function filters(Request $request): array
    {
        return [
            'site' => trim((string) $request->query('site', '')),
            'department' => trim((string) $request->query('department', '')),
            'date_from' => trim((string) $request->query('date_from', '')),
            'date_to' => trim((string) $request->query('date_to', '')),
        ];
    }

    private function deviceSites(): array
    {
        return Device::query()->whereNotNull('current_site')->where('current_site', '<>', '')->distinct()->orderBy('current_site')->pluck('current_site')->all();
    }

    private function departments(): array
    {
        return Device::query()->whereNotNull('current_department')->where('current_department', '<>', '')->distinct()->orderBy('current_department')->pluck('current_department')->all();
    }

    private function changeSites(): array
    {
        return ChangeLog::query()->whereNotNull('observed_site')->where('observed_site', '<>', '')->distinct()->orderBy('observed_site')->pluck('observed_site')->all();
    }

    private function changeDepartments(): array
    {
        return ChangeLog::query()->whereNotNull('observed_department')->where('observed_department', '<>', '')->distinct()->orderBy('observed_department')->pluck('observed_department')->all();
    }
}
