<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CollectorSite;
use App\Models\RunnerCommand;
use Illuminate\Http\Request;
use Illuminate\View\View;

class CommandController extends Controller
{
    public function index(Request $request): View
    {
        $query = trim((string) $request->query('q', ''));
        $status = trim((string) $request->query('status', ''));
        $type = trim((string) $request->query('type', ''));
        $siteId = trim((string) $request->query('site_id', ''));

        $commands = RunnerCommand::query()
            ->with(['site', 'runner'])
            ->when($query !== '', function ($builder) use ($query): void {
                $like = "%{$query}%";
                $builder->where(function ($nested) use ($like): void {
                    $nested->where('runner_id', 'like', $like)
                        ->orWhere('site_id', 'like', $like)
                        ->orWhere('command_type', 'like', $like)
                        ->orWhere('status', 'like', $like)
                        ->orWhere('requested_by', 'like', $like)
                        ->orWhere('completion_status', 'like', $like)
                        ->orWhere('completion_message', 'like', $like)
                        ->orWhereHas('runner', function ($runnerQuery) use ($like): void {
                            $runnerQuery->where('hostname', 'like', $like)
                                ->orWhere('runner_version', 'like', $like);
                        })
                        ->orWhereHas('site', function ($siteQuery) use ($like): void {
                            $siteQuery->where('site_name', 'like', $like);
                        });
                });
            })
            ->when($status !== '', function ($builder) use ($status): void {
                if ($status === 'active') {
                    $builder->whereIn('status', ['pending', 'dispatched']);

                    return;
                }

                if ($status === 'attention') {
                    $builder->where(function ($nested): void {
                        $nested->whereIn('status', ['failed', 'superseded'])
                            ->orWhere(function ($stale): void {
                                $stale->where('status', 'dispatched')
                                    ->where('requested_at', '<', now()->subHours(24));
                            });
                    });

                    return;
                }

                $builder->where('status', $status);
            })
            ->when($type !== '', fn ($builder) => $builder->where('command_type', $type))
            ->when($siteId !== '', fn ($builder) => $builder->where('site_id', $siteId))
            ->orderByDesc('requested_at')
            ->orderByDesc('id')
            ->paginate(50)
            ->withQueryString();

        return view('admin.commands.index', [
            'commands' => $commands,
            'query' => $query,
            'status' => $status,
            'type' => $type,
            'siteId' => $siteId,
            'sites' => CollectorSite::query()->orderBy('site_id')->get(),
            'commandTypes' => RunnerCommand::query()
                ->select('command_type')
                ->distinct()
                ->orderBy('command_type')
                ->pluck('command_type'),
            'summary' => [
                'total' => RunnerCommand::query()->count(),
                'pending' => RunnerCommand::query()->where('status', 'pending')->count(),
                'dispatched' => RunnerCommand::query()->where('status', 'dispatched')->count(),
                'completed' => RunnerCommand::query()->where('status', 'completed')->count(),
                'failed' => RunnerCommand::query()->where('status', 'failed')->count(),
                'superseded' => RunnerCommand::query()->where('status', 'superseded')->count(),
                'stale_dispatched' => RunnerCommand::query()
                    ->where('status', 'dispatched')
                    ->where('requested_at', '<', now()->subHours(24))
                    ->count(),
            ],
        ]);
    }
}
