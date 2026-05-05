<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Collector;
use App\Models\CollectorSite;
use App\Models\Runner;
use App\Models\RunnerCommand;
use Illuminate\Http\Request;
use Illuminate\View\View;

class CollectorController extends Controller
{
    public function index(Request $request): View
    {
        $query = trim((string) $request->query('q', ''));
        $status = trim((string) $request->query('status', ''));
        $freshSince = now()->subHours(24);

        $sites = CollectorSite::query()
            ->with([
                'collectors' => fn ($collectorQuery) => $collectorQuery->orderByDesc('last_seen_at')->orderBy('collector_name'),
                'runners' => fn ($runnerQuery) => $runnerQuery
                    ->with('activeCommands')
                    ->orderByDesc('last_seen_at')
                    ->orderBy('runner_id'),
            ])
            ->when($query !== '', function ($builder) use ($query): void {
                $like = "%{$query}%";
                $builder->where(function ($nested) use ($like): void {
                    $nested->where('site_id', 'like', $like)
                        ->orWhere('site_name', 'like', $like)
                        ->orWhereHas('collectors', function ($collectorQuery) use ($like): void {
                            $collectorQuery->where('collector_name', 'like', $like)
                                ->orWhere('collector_version', 'like', $like)
                                ->orWhere('share_root_hint', 'like', $like)
                                ->orWhere('last_status', 'like', $like);
                        })
                        ->orWhereHas('runners', function ($runnerQuery) use ($like): void {
                            $runnerQuery->where('runner_id', 'like', $like)
                                ->orWhere('hostname', 'like', $like)
                                ->orWhere('runner_version', 'like', $like)
                                ->orWhere('last_inventory_status', 'like', $like)
                                ->orWhere('last_upload_status', 'like', $like);
                        });
                });
            })
            ->orderBy('site_id')
            ->get()
            ->each(function (CollectorSite $site) use ($freshSince): void {
                $activeCollectorCount = $site->collectors
                    ->filter(fn (Collector $collector): bool => $this->isFresh($collector->last_seen_at, $freshSince))
                    ->count();
                $activeRunnerCount = $site->runners
                    ->filter(fn (Runner $runner): bool => $this->isFresh($runner->last_seen_at, $freshSince))
                    ->count();
                $collectorStatusAttention = $site->collectors->contains(
                    fn (Collector $collector): bool => ! in_array(strtolower((string) $collector->last_status), ['', 'ok', 'success', 'healthy'], true),
                );

                $site->setAttribute('collectors_count', $site->collectors->count());
                $site->setAttribute('runners_count', $site->runners->count());
                $site->setAttribute('active_collectors_count', $activeCollectorCount);
                $site->setAttribute('active_runners_count', $activeRunnerCount);
                $site->setAttribute('queue_depth_csv_total', $site->collectors->sum('queue_depth_csv'));
                $site->setAttribute('queue_depth_heartbeat_total', $site->collectors->sum('queue_depth_heartbeat'));
                $site->setAttribute('needs_attention', $site->collectors->isEmpty()
                    || $activeCollectorCount < $site->collectors->count()
                    || $activeRunnerCount < $site->runners->count()
                    || $collectorStatusAttention);
            })
            ->filter(function (CollectorSite $site) use ($status): bool {
                return match ($status) {
                    'active' => ! $site->needs_attention,
                    'attention' => (bool) $site->needs_attention,
                    default => true,
                };
            })
            ->values();

        $activeCommandsBySite = RunnerCommand::query()
            ->with('runner')
            ->whereIn('status', ['pending', 'dispatched'])
            ->orderByDesc('requested_at')
            ->get()
            ->groupBy(fn (RunnerCommand $command): string => $command->site_id ?: 'unassigned');

        return view('admin.collectors.index', [
            'sites' => $sites,
            'query' => $query,
            'status' => $status,
            'freshSince' => $freshSince,
            'activeCommandsBySite' => $activeCommandsBySite,
            'summary' => [
                'total_sites' => CollectorSite::query()->count(),
                'total_collectors' => Collector::query()->count(),
                'active_collectors' => Collector::query()->where('last_seen_at', '>=', $freshSince)->count(),
                'total_runners' => Runner::query()->count(),
                'active_runners' => Runner::query()->where('last_seen_at', '>=', $freshSince)->count(),
                'queued_files' => Collector::query()->sum('queue_depth_csv') + Collector::query()->sum('queue_depth_heartbeat'),
                'active_commands' => RunnerCommand::query()->whereIn('status', ['pending', 'dispatched'])->count(),
            ],
        ]);
    }

    private function isFresh(mixed $date, mixed $freshSince): bool
    {
        return $date !== null && $date !== '' && $date->gte($freshSince);
    }
}
