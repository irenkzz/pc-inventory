<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\StorageHealthObservation;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;

class StorageHealthController extends Controller
{
    public function index(Request $request): View
    {
        $risk = strtolower(trim((string) $request->query('risk', '')));

        if (! Schema::hasTable('storage_health_observations')) {
            $observations = new LengthAwarePaginator([], 0, 50);
            $counts = collect();

            return view('admin.storage-health.index', compact('observations', 'counts', 'risk'));
        }

        $latestIds = StorageHealthObservation::query()
            ->selectRaw('MAX(id)')
            ->groupBy('device_id', 'disk_key');

        $observations = StorageHealthObservation::query()
            ->with(['device', 'scan'])
            ->whereIn('id', $latestIds)
            ->when($risk !== '', fn ($query) => $query->where('risk_level', $risk))
            ->orderByRaw("CASE risk_level WHEN 'critical' THEN 1 WHEN 'warning' THEN 2 WHEN 'watch' THEN 3 WHEN 'unknown' THEN 4 ELSE 5 END")
            ->orderByDesc('risk_score')
            ->orderByDesc('observed_at')
            ->paginate(50)
            ->withQueryString();

        $counts = StorageHealthObservation::query()
            ->whereIn('id', $latestIds)
            ->selectRaw('risk_level, COUNT(*) as total')
            ->groupBy('risk_level')
            ->pluck('total', 'risk_level');

        return view('admin.storage-health.index', compact('observations', 'counts', 'risk'));
    }
}
