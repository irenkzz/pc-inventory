<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Device;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DeviceReadController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = trim((string) $request->query('q', ''));
        $devices = Device::query()
            ->when($query !== '', function ($builder) use ($query): void {
                $like = "%{$query}%";
                $builder->where('current_asset_code', 'like', $like)
                    ->orWhere('current_user_name', 'like', $like)
                    ->orWhere('manufacturer', 'like', $like)
                    ->orWhere('model', 'like', $like)
                    ->orWhere('current_location', 'like', $like)
                    ->orWhere('current_site', 'like', $like);
            })
            ->orderByDesc('last_seen_at')
            ->orderByDesc('id')
            ->limit(500)
            ->get();

        return response()->json($devices);
    }

    public function show(Device $device): JsonResponse
    {
        $device->load([
            'identities' => fn ($query) => $query->orderByDesc('weight'),
            'scans.snapshot',
            'changes' => fn ($query) => $query->orderByDesc('observed_at'),
            'assignments' => fn ($query) => $query->orderByDesc('started_at'),
            'networkObservations' => fn ($query) => $query->orderByDesc('observed_at'),
            'peripherals' => fn ($query) => $query->orderByDesc('observed_at'),
        ]);

        return response()->json($device);
    }
}
