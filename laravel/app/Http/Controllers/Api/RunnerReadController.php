<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Runner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RunnerReadController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = trim((string) $request->query('q', ''));
        $runners = Runner::query()
            ->withCount(['commands as pending_command_count' => fn ($builder) => $builder->whereIn('status', ['pending', 'dispatched'])])
            ->when($query !== '', function ($builder) use ($query): void {
                $like = "%{$query}%";
                $builder->where('runner_id', 'like', $like)
                    ->orWhere('hostname', 'like', $like)
                    ->orWhere('site_id', 'like', $like)
                    ->orWhere('runner_version', 'like', $like);
            })
            ->orderByDesc('last_seen_at')
            ->limit(500)
            ->get();

        return response()->json($runners);
    }

    public function show(Runner $runner): JsonResponse
    {
        $runner->load(['commands' => fn ($query) => $query->orderByDesc('requested_at')->limit(100)]);

        return response()->json($runner);
    }
}
