<?php

namespace App\Console\Commands;

use App\Services\Operations\PilotReadinessService;
use Illuminate\Console\Command;

class PilotStatus extends Command
{
    protected $signature = 'inventory:pilot-status
        {--json : Output machine-readable JSON}
        {--stale-minutes=1440 : Collector/runner freshness threshold in minutes}
        {--command-stale-minutes=60 : Pending/dispatched command age threshold in minutes}';

    protected $description = 'Summarize collector, runner, command, intake, backup, and storage-health readiness for pilot rollout';

    public function handle(PilotReadinessService $readiness): int
    {
        $staleMinutes = max(1, (int) $this->option('stale-minutes'));
        $commandStaleMinutes = max(1, (int) $this->option('command-stale-minutes'));
        $report = $readiness->report($staleMinutes, $commandStaleMinutes);
        $status = $report['status'];
        $summary = $report['summary'];
        $checks = $report['checks'];

        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return $status === 'ok' ? self::SUCCESS : self::FAILURE;
        }

        $this->info('Pilot readiness: ' . strtoupper($status));
        $this->table(['Status', 'Check', 'Value', 'Message'], array_map(
            fn (array $check): array => [strtoupper($check['status']), $check['name'], (string) $check['value'], $check['message']],
            $checks,
        ));

        if ($summary['runner_versions'] !== []) {
            $this->newLine();
            $this->table(['Runner version', 'Count'], collect($summary['runner_versions'])->map(
                fn (int $count, string $version): array => [$version, $count],
            )->values()->all());
        }

        return $status === 'ok' ? self::SUCCESS : self::FAILURE;
    }
}
