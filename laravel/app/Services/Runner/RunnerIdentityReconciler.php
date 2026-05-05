<?php

namespace App\Services\Runner;

use App\Models\Runner;
use App\Models\RunnerCommand;
use App\Services\Inventory\CsvNormalizer;
use Illuminate\Support\Facades\DB;

class RunnerIdentityReconciler
{
    public function __construct(private readonly CsvNormalizer $normalizer)
    {
    }

    public function reconcileHostnameChange(string $oldRunnerId, string $newRunnerId): bool
    {
        $oldRunnerId = $this->normalizeRunnerId($oldRunnerId);
        $newRunnerId = $this->normalizeRunnerId($newRunnerId);

        if ($oldRunnerId === '' || $newRunnerId === '' || $oldRunnerId === $newRunnerId) {
            return false;
        }

        $old = Runner::query()->where('runner_id', $oldRunnerId)->first();
        $target = Runner::query()->where('runner_id', $newRunnerId)->first();

        if ($old === null || $target === null) {
            return false;
        }

        if ($old->last_seen_at !== null && $target->last_seen_at !== null && $old->last_seen_at->gt($target->last_seen_at)) {
            return false;
        }

        return $this->renameOrMerge($oldRunnerId, $newRunnerId, ['hostname' => $newRunnerId], false);
    }

    public function renameOrMerge(string $oldRunnerId, string $newRunnerId, array $attributes = [], bool $allowActiveCommands = false): bool
    {
        $oldRunnerId = $this->normalizeRunnerId($oldRunnerId);
        $newRunnerId = $this->normalizeRunnerId($newRunnerId);

        if ($oldRunnerId === '' || $newRunnerId === '' || $oldRunnerId === $newRunnerId) {
            return false;
        }

        $old = Runner::query()->where('runner_id', $oldRunnerId)->first();
        if ($old === null) {
            return false;
        }

        if (! $allowActiveCommands && RunnerCommand::query()->where('runner_id', $oldRunnerId)->whereIn('status', ['pending', 'dispatched'])->exists()) {
            return false;
        }

        $target = Runner::query()->where('runner_id', $newRunnerId)->first();
        $hostname = $this->normalizeRunnerId((string) ($attributes['hostname'] ?? $newRunnerId));

        DB::transaction(function () use ($old, $target, $oldRunnerId, $newRunnerId, $hostname): void {
            if ($target === null) {
                $rawState = $this->withAlias($old->raw_state_json ?? [], $oldRunnerId);
                $rawState['runner_id'] = $newRunnerId;
                $rawState['hostname'] = $hostname;
                $runnerGuid = $old->runner_guid;

                if ($runnerGuid !== null && $runnerGuid !== '') {
                    $old->forceFill(['runner_guid' => null])->save();
                }

                Runner::query()->create([
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

            RunnerCommand::query()
                ->where('runner_id', $oldRunnerId)
                ->update(['runner_id' => $newRunnerId]);

            $rawState = $this->withAlias($target->raw_state_json ?? [], $oldRunnerId);

            $target->forceFill([
                'runner_guid' => $target->runner_guid ?: $old->runner_guid,
                'hostname' => $target->hostname ?: $hostname,
                'site_id' => $target->site_id ?: $old->site_id,
                'collector_name' => $target->collector_name ?: $old->collector_name,
                'runner_version' => $target->runner_version ?: $old->runner_version,
                'install_mode' => $target->install_mode ?: $old->install_mode,
                'raw_state_json' => $rawState,
            ])->save();

            $old->delete();
        });

        return true;
    }

    private function normalizeRunnerId(string $value): string
    {
        return $this->normalizer->safeUpper($value);
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
