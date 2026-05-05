<?php

namespace App\Console\Commands;

use App\Models\Runner;
use App\Models\RunnerCommand;
use App\Services\Runner\RunnerIdentityReconciler;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RenameRunner extends Command
{
    protected $signature = 'inventory:rename-runner
        {old_runner_id : Existing stale runner ID}
        {new_runner_id : Current runner ID}
        {--hostname= : Optional hostname to store on the target runner}
        {--dry-run : Show what would change without writing}';

    protected $description = 'Rename or merge runner records after a Windows hostname/runner ID change';

    public function handle(RunnerIdentityReconciler $reconciler): int
    {
        $oldRunnerId = $this->normalizeRunnerId((string) $this->argument('old_runner_id'));
        $newRunnerId = $this->normalizeRunnerId((string) $this->argument('new_runner_id'));
        $hostname = $this->normalizeRunnerId((string) ($this->option('hostname') ?: $newRunnerId));
        $dryRun = (bool) $this->option('dry-run');

        if ($oldRunnerId === '' || $newRunnerId === '') {
            $this->error('Both old_runner_id and new_runner_id are required.');

            return self::FAILURE;
        }

        if ($oldRunnerId === $newRunnerId) {
            $this->error('Old and new runner IDs are the same.');

            return self::FAILURE;
        }

        $old = Runner::query()->where('runner_id', $oldRunnerId)->first();
        if ($old === null) {
            $this->error("Old runner not found: {$oldRunnerId}");

            return self::FAILURE;
        }

        $target = Runner::query()->where('runner_id', $newRunnerId)->first();
        $oldCommandCount = RunnerCommand::query()->where('runner_id', $oldRunnerId)->count();

        $plan = [
            'mode' => $target ? 'merge' : 'rename',
            'old_runner_id' => $oldRunnerId,
            'new_runner_id' => $newRunnerId,
            'target_exists' => $target !== null,
            'commands_to_move' => $oldCommandCount,
            'hostname' => $hostname,
            'dry_run' => $dryRun,
        ];

        if ($dryRun) {
            $this->line(json_encode($plan, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        DB::transaction(function () use ($old, $target, $oldRunnerId, $newRunnerId, $hostname): void {
            if ($target === null) {
                $rawState = $this->withAlias($old->raw_state_json ?? [], $oldRunnerId);
                $rawState['runner_id'] = $newRunnerId;
                $rawState['hostname'] = $hostname;
                $runnerGuid = $old->runner_guid;

                if ($runnerGuid !== null && $runnerGuid !== '') {
                    $old->forceFill(['runner_guid' => null])->save();
                }

                $target = Runner::query()->create([
                    'runner_id' => $newRunnerId,
                    'runner_guid' => $runnerGuid,
                    'hostname' => $hostname,
                    'site_id' => $old->site_id,
                    'collector_name' => $old->collector_name,
                    'runner_version' => $old->runner_version,
                    'install_mode' => $old->install_mode,
                    'last_seen_at' => $old->last_seen_at,
                    'last_successful_inventory_at' => $old->last_successful_inventory_at,
                    'last_inventory_status' => $old->last_inventory_status,
                    'last_upload_status' => $old->last_upload_status,
                    'last_error' => $old->last_error,
                    'last_command_seen_at' => $old->last_command_seen_at,
                    'last_command_type' => $old->last_command_type,
                    'raw_state_json' => $rawState,
                ]);

                RunnerCommand::query()
                    ->where('runner_id', $oldRunnerId)
                    ->update(['runner_id' => $newRunnerId]);

                $old->delete();

                return;
            }

            $target->forceFill([
                'hostname' => $hostname,
                'site_id' => $target->site_id ?: $old->site_id,
                'collector_name' => $target->collector_name ?: $old->collector_name,
                'runner_version' => $target->runner_version ?: $old->runner_version,
                'install_mode' => $target->install_mode ?: $old->install_mode,
            ])->save();
        });

        if ($target !== null) {
            $reconciler->reconcileHostnameChange($oldRunnerId, $newRunnerId);
        }

        $this->line(json_encode($plan + ['status' => 'done'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }

    private function normalizeRunnerId(string $value): string
    {
        return strtoupper(trim($value));
    }

    private function withAlias(array $rawState, string $oldRunnerId): array
    {
        $aliases = $rawState['previous_runner_ids'] ?? [];
        if (! is_array($aliases)) {
            $aliases = [];
        }

        $aliases[] = $oldRunnerId;
        $rawState['previous_runner_ids'] = array_values(array_unique(array_filter($aliases)));

        return $rawState;
    }
}
