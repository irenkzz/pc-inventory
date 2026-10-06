<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Runner;
use App\Models\RunnerCommand;
use App\Services\Runner\CommandQueueService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class RunnerController extends Controller
{
    public function index(Request $request): View
    {
        $query = trim((string) $request->query('q', ''));
        $status = trim((string) $request->query('status', ''));
        $freshSince = now()->subHours(24);
        $targetVersion = (string) config('inventory.runner_target_version');
        $olderVersionIds = [];

        if ($status === 'older-version') {
            $olderVersionIds = Runner::query()
                ->whereNotNull('runner_version')
                ->get(['id', 'runner_version'])
                ->filter(fn (Runner $runner): bool => version_compare((string) $runner->runner_version, $targetVersion, '<'))
                ->pluck('id')
                ->all();
        }

        $runners = Runner::query()
            ->with(['site', 'activeCommands'])
            ->withCount(['commands as pending_command_count' => fn ($builder) => $builder->whereIn('status', ['pending', 'dispatched'])])
            ->when($query !== '', function ($builder) use ($query): void {
                $like = "%{$query}%";
                $builder->where('runner_id', 'like', $like)
                    ->orWhere('hostname', 'like', $like)
                    ->orWhere('site_id', 'like', $like)
                    ->orWhere('runner_version', 'like', $like);
            })
            ->when($status !== '', function ($builder) use ($status, $freshSince, $olderVersionIds): void {
                if ($status === 'recent') {
                    $builder->where('last_seen_at', '>=', $freshSince);

                    return;
                }

                if ($status === 'stale') {
                    $builder->where(fn ($nested) => $nested->whereNull('last_seen_at')->orWhere('last_seen_at', '<', $freshSince));

                    return;
                }

                if ($status === 'older-version') {
                    $builder->whereIn('id', $olderVersionIds);

                    return;
                }

                if ($status === 'active-command') {
                    $builder->whereHas('commands', fn ($nested) => $nested->whereIn('status', ['pending', 'dispatched']));
                }
            })
            ->orderByDesc('last_seen_at')
            ->paginate(50)
            ->withQueryString();

        return view('admin.runners.index', compact('runners', 'query', 'status', 'targetVersion', 'freshSince'));
    }

    public function show(Runner $runner): View
    {
        $runner->load([
            'site',
            'activeCommands',
            'commands' => fn ($query) => $query->with('site')->orderByDesc('requested_at')->limit(100),
        ]);

        return view('admin.runners.show', compact('runner'));
    }

    public function manualScan(Runner $runner, CommandQueueService $commands): RedirectResponse
    {
        return $this->queueRunnerCommand($runner, $commands, 'scan_now', 'Manual scan');
    }

    public function repair(Runner $runner, CommandQueueService $commands): RedirectResponse
    {
        if ((string) $runner->transport_mode === 'direct_https') {
            return redirect()
                ->route('admin.runners.show', $runner)
                ->with('status', 'Repair/update is not supported for direct HTTPS runners yet.');
        }

        $targetVersion = (string) config('inventory.runner_target_version');
        if ($targetVersion !== '' && version_compare((string) $runner->runner_version, $targetVersion, '>=')) {
            RunnerCommand::query()->create([
                'runner_id' => $runner->runner_id,
                'site_id' => $runner->site_id,
                'command_type' => 'repair_update',
                'payload_json' => [],
                'status' => 'completed',
                'requested_by' => 'portal',
                'requested_at' => now(),
                'acknowledged_at' => now(),
                'completed_at' => now(),
                'completion_status' => 'completed',
                'completion_message' => "Runner already reports target version {$targetVersion}; no repair command dispatched.",
            ]);

            return redirect()
                ->route('admin.runners.show', $runner)
                ->with('status', "Repair/update skipped for {$runner->runner_id}; runner already reports {$targetVersion}.");
        }

        return $this->queueRunnerCommand($runner, $commands, 'repair_update', 'Repair/update');
    }

    private function queueRunnerCommand(
        Runner $runner,
        CommandQueueService $commands,
        string $commandType,
        string $label,
    ): RedirectResponse {
        $existingSameType = $commands->activeForRunner($runner->runner_id, $commandType);
        if ($existingSameType !== null) {
            return redirect()
                ->route('admin.runners.show', $runner)
                ->with('status', "{$label} is already queued for this runner.");
        }

        $existingAny = $commands->activeForRunner($runner->runner_id);
        $command = $commands->queue($runner->runner_id, $runner->site_id, $commandType, 'portal');

        $message = "{$label} queued for {$runner->runner_id}.";
        if ($existingAny !== null) {
            $message .= ' Previous active command was superseded.';
        }

        return redirect()
            ->route('admin.runners.show', $runner)
            ->with('status', "{$message} Command #{$command->id} is pending.");
    }
}
