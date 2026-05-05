<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ChangeLog;
use App\Models\Collector;
use App\Models\Device;
use App\Models\DeviceAssignment;
use App\Models\DeviceScan;
use App\Models\Runner;
use App\Models\RunnerCommand;
use App\Models\StorageHealthObservation;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(): View
    {
        $now = now();
        $freshRunnerSince = $now->copy()->subHours(24);
        $freshCollectorSince = $now->copy()->subHours(24);
        $recentChangeSince = $now->copy()->subDays(7);
        $hasStorageHealthTable = Schema::hasTable('storage_health_observations');
        $latestStorageObservationIds = $hasStorageHealthTable
            ? StorageHealthObservation::query()
                ->selectRaw('MAX(id)')
                ->groupBy('device_id', 'disk_key')
            : null;

        $totalRunners = Runner::query()->count();
        $activeRunners = Runner::query()->where('last_seen_at', '>=', $freshRunnerSince)->count();
        $totalCollectors = Collector::query()->count();
        $activeCollectors = Collector::query()->where('last_seen_at', '>=', $freshCollectorSince)->count();

        return view('admin.dashboard', [
            'counts' => [
                'total_devices' => Device::query()->count(),
                'total_scans' => DeviceScan::query()->count(),
                'total_changes' => ChangeLog::query()->count(),
                'active_assignments' => DeviceAssignment::query()->whereNull('ended_at')->count(),
                'total_runners' => $totalRunners,
                'active_runners' => $activeRunners,
                'runner_attention' => max(0, $totalRunners - $activeRunners),
                'total_collectors' => $totalCollectors,
                'active_collectors' => $activeCollectors,
                'collector_attention' => max(0, $totalCollectors - $activeCollectors),
                'pending_commands' => RunnerCommand::query()->whereIn('status', ['pending', 'dispatched'])->count(),
                'storage_critical' => $hasStorageHealthTable
                    ? StorageHealthObservation::query()
                        ->whereIn('id', $latestStorageObservationIds)
                        ->where('risk_level', 'critical')
                        ->count()
                    : 0,
                'storage_unknown' => $hasStorageHealthTable
                    ? StorageHealthObservation::query()
                        ->whereIn('id', $latestStorageObservationIds)
                        ->where('risk_level', 'unknown')
                        ->count()
                    : 0,
                'scans_today' => DeviceScan::query()->where('scan_time', '>=', $now->copy()->startOfDay())->count(),
                'changes_7d' => ChangeLog::query()->where('observed_at', '>=', $recentChangeSince)->count(),
                'critical_changes_7d' => ChangeLog::query()
                    ->where('observed_at', '>=', $recentChangeSince)
                    ->where('severity', 'Critical')
                    ->count(),
            ],
            'latestScanAt' => DeviceScan::query()->max('scan_time'),
            'recentScans' => DeviceScan::query()->with('device')->orderByDesc('scan_time')->limit(10)->get(),
            'recentChanges' => ChangeLog::query()->with('device')->orderByDesc('observed_at')->limit(10)->get(),
            'recentRunners' => Runner::query()->with('site')->orderByDesc('last_seen_at')->limit(10)->get(),
            'recentCollectors' => Collector::query()->with('site')->orderByDesc('last_seen_at')->limit(6)->get(),
            'storageRisks' => $hasStorageHealthTable
                ? StorageHealthObservation::query()
                    ->with(['device', 'scan'])
                    ->whereIn('id', $latestStorageObservationIds)
                    ->whereIn('risk_level', ['critical', 'warning', 'unknown'])
                    ->orderByRaw("CASE risk_level WHEN 'critical' THEN 1 WHEN 'warning' THEN 2 WHEN 'unknown' THEN 3 ELSE 4 END")
                    ->orderByDesc('risk_score')
                    ->limit(6)
                    ->get()
                : collect(),
            'siteSummaries' => Device::query()
                ->selectRaw("COALESCE(NULLIF(current_site, ''), 'Unassigned') as site_name")
                ->selectRaw('COUNT(*) as device_count')
                ->groupByRaw("COALESCE(NULLIF(current_site, ''), 'Unassigned')")
                ->orderByDesc('device_count')
                ->limit(6)
                ->get(),
        ]);
    }
}
