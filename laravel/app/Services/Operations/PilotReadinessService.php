<?php

namespace App\Services\Operations;

use App\Models\Collector;
use App\Models\DeviceScan;
use App\Models\RawFile;
use App\Models\Runner;
use App\Models\RunnerCommand;
use App\Models\StorageHealthObservation;
use Illuminate\Support\Facades\File;

class PilotReadinessService
{
    public function report(int $staleMinutes = 1440, int $commandStaleMinutes = 60): array
    {
        $staleMinutes = max(1, $staleMinutes);
        $commandStaleMinutes = max(1, $commandStaleMinutes);

        $summary = [
            'generated_at' => now()->toIso8601String(),
            'thresholds' => [
                'stale_minutes' => $staleMinutes,
                'command_stale_minutes' => $commandStaleMinutes,
            ],
            'counts' => $this->counts($staleMinutes, $commandStaleMinutes),
            'runner_versions' => $this->runnerVersions(),
            'latest' => $this->latest(),
        ];

        $checks = $this->checks($summary);
        $status = collect($checks)->contains(fn (array $check): bool => $check['status'] === 'fail') ? 'fail' : 'ok';

        return [
            'status' => $status,
            'summary' => $summary,
            'checks' => $checks,
        ];
    }

    private function counts(int $staleMinutes, int $commandStaleMinutes): array
    {
        $freshCutoff = now()->subMinutes($staleMinutes);
        $commandCutoff = now()->subMinutes($commandStaleMinutes);
        $targetVersion = (string) config('inventory.runner_target_version', '1.0.19');

        return [
            'collectors_total' => Collector::query()->count(),
            'collectors_recent' => Collector::query()->where('last_seen_at', '>=', $freshCutoff)->count(),
            'collectors_with_errors' => Collector::query()->whereNotNull('last_error')->where('last_error', '<>', '')->count(),
            'runners_total' => Runner::query()->count(),
            'runners_recent' => Runner::query()->where('last_seen_at', '>=', $freshCutoff)->count(),
            'runners_stale' => Runner::query()->where(fn ($query) => $query->whereNull('last_seen_at')->orWhere('last_seen_at', '<', $freshCutoff))->count(),
            'runners_below_target_version' => Runner::query()
                ->whereNotNull('runner_version')
                ->get(['runner_version'])
                ->filter(fn (Runner $runner): bool => version_compare((string) $runner->runner_version, $targetVersion, '<'))
                ->count(),
            'commands_active' => RunnerCommand::query()->whereIn('status', ['pending', 'dispatched'])->count(),
            'commands_stuck' => RunnerCommand::query()
                ->whereIn('status', ['pending', 'dispatched'])
                ->where('requested_at', '<', $commandCutoff)
                ->count(),
            'commands_failed' => RunnerCommand::query()
                ->where(fn ($query) => $query
                    ->where('status', 'failed')
                    ->orWhere('completion_status', 'failed')
                    ->orWhere('completion_status', 'error'))
                ->count(),
            'raw_files_total' => RawFile::query()->count(),
            'device_scans_total' => DeviceScan::query()->count(),
            'storage_critical' => StorageHealthObservation::query()->where('risk_level', 'critical')->count(),
            'storage_unknown' => StorageHealthObservation::query()->where('risk_level', 'unknown')->count(),
            'runner_target_version' => $targetVersion,
        ];
    }

    private function runnerVersions(): array
    {
        return Runner::query()
            ->get(['runner_version'])
            ->map(fn (Runner $runner): string => trim((string) $runner->runner_version) !== '' ? (string) $runner->runner_version : 'unknown')
            ->countBy()
            ->sortKeys()
            ->all();
    }

    private function latest(): array
    {
        $latestBackup = $this->latestBackupManifest();

        return [
            'collector_seen_at' => Collector::query()->max('last_seen_at'),
            'runner_seen_at' => Runner::query()->max('last_seen_at'),
            'successful_inventory_at' => Runner::query()->max('last_successful_inventory_at'),
            'raw_file_received_at' => RawFile::query()->max('received_at'),
            'device_scan_ingested_at' => DeviceScan::query()->max('ingested_at'),
            'backup_manifest' => $latestBackup['path'],
            'backup_manifest_modified_at' => $latestBackup['modified_at'],
        ];
    }

    private function latestBackupManifest(): array
    {
        $backupRoot = storage_path('app/' . trim((string) config('inventory.backups_path'), '/'));
        $manifests = is_dir($backupRoot) ? File::glob($backupRoot . DIRECTORY_SEPARATOR . '*' . DIRECTORY_SEPARATOR . 'manifest.json') : [];

        if ($manifests === []) {
            return ['path' => null, 'modified_at' => null];
        }

        usort($manifests, fn (string $left, string $right): int => File::lastModified($right) <=> File::lastModified($left));

        return [
            'path' => $manifests[0],
            'modified_at' => date(DATE_ATOM, File::lastModified($manifests[0])),
        ];
    }

    private function checks(array $summary): array
    {
        $counts = $summary['counts'];
        $latest = $summary['latest'];

        return [
            $this->check('Recent collector heartbeat', $counts['collectors_recent'] > 0 ? 'pass' : 'fail', $counts['collectors_recent'], 'At least one collector should report within the freshness window.'),
            $this->check('Recent runner heartbeat', $counts['runners_recent'] > 0 ? 'pass' : 'fail', $counts['runners_recent'], 'At least one runner should report within the freshness window.'),
            $this->check('Latest raw intake', $latest['raw_file_received_at'] !== null || $latest['device_scan_ingested_at'] !== null ? 'pass' : 'fail', $latest['raw_file_received_at'] ?? $latest['device_scan_ingested_at'] ?? 'none', 'Pilot needs at least one successful inventory intake.'),
            $this->check('Stale runners', $counts['runners_stale'] === 0 ? 'pass' : 'warn', $counts['runners_stale'], 'Stale runners may indicate broken tasks, offline PCs, or share access problems.'),
            $this->check('Older runner versions', $counts['runners_below_target_version'] === 0 ? 'pass' : 'warn', $counts['runners_below_target_version'], 'Runners below ' . $counts['runner_target_version'] . ' should be repaired or reinstalled before wider rollout.'),
            $this->check('Stuck active commands', $counts['commands_stuck'] === 0 ? 'pass' : 'warn', $counts['commands_stuck'], 'Pending/dispatched commands older than the threshold need collector or runner follow-up.'),
            $this->check('Failed commands', $counts['commands_failed'] === 0 ? 'pass' : 'warn', $counts['commands_failed'], 'Failed acknowledgements should be reviewed before pilot expansion.'),
            $this->check('Collector errors', $counts['collectors_with_errors'] === 0 ? 'pass' : 'warn', $counts['collectors_with_errors'], 'Collector errors usually point to branch share, token, or central API issues.'),
            $this->check('Latest backup', $latest['backup_manifest'] !== null ? 'pass' : 'warn', $latest['backup_manifest'] ?? 'none', 'Run inventory:backup before cutover or destructive pilot changes.'),
            $this->check('Critical storage risk', $counts['storage_critical'] === 0 ? 'pass' : 'warn', $counts['storage_critical'], 'Critical disk observations should be handled outside the rollout workflow.'),
            $this->check('Unknown storage telemetry', $counts['storage_unknown'] === 0 ? 'pass' : 'warn', $counts['storage_unknown'], 'Unknown storage telemetry is expected on some controllers but should be tracked.'),
        ];
    }

    private function check(string $name, string $status, mixed $value, string $message): array
    {
        return [
            'status' => $status,
            'name' => $name,
            'value' => $value,
            'message' => $message,
        ];
    }
}
