<?php

namespace App\Console\Commands;

use App\Services\Collector\CollectorStatusService;
use App\Services\Inventory\InventoryIngestService;
use App\Services\Runner\CommandQueueService;
use App\Services\Runner\RunnerStatusService;
use Illuminate\Console\Command;

class SimulateCollectorCycle extends Command
{
    protected $signature = 'inventory:simulate-collector
        {csv=../../inventaris_py/sample_data/sample_scan_1.csv : CSV file to submit as collector intake}
        {--site-id=SITE-HQ}
        {--site-name=Bhayangkara}
        {--collector-name=local-sim-collector}
        {--runner-id=PC-ACCOUNTING-01}
        {--runner-version=1.0.0}
        {--queue-scan : Queue a scan_now command before polling}
        {--ack : Acknowledge the first dispatched command}';

    protected $description = 'Simulate one collector relay cycle against Laravel services';

    public function handle(
        CollectorStatusService $collectorStatus,
        RunnerStatusService $runnerStatus,
        InventoryIngestService $ingest,
        CommandQueueService $commands,
    ): int {
        $csvPath = $this->resolvePath((string) $this->argument('csv'));
        if (! is_file($csvPath)) {
            $this->error("CSV file not found: {$csvPath}");
            return self::FAILURE;
        }

        $siteId = (string) $this->option('site-id');
        $siteName = (string) $this->option('site-name');
        $collectorName = (string) $this->option('collector-name');
        $runnerId = (string) $this->option('runner-id');
        $runnerVersion = (string) $this->option('runner-version');

        $collectorStatus->upsert([
            'site_id' => $siteId,
            'site_name' => $siteName,
            'collector_name' => $collectorName,
            'collector_version' => $runnerVersion,
            'share_root_hint' => 'simulated-local-cycle',
            'queue_depth_csv' => 1,
            'queue_depth_heartbeat' => 1,
            'last_status' => 'ok',
        ]);

        $runnerStatus->upsert([
            'runner_id' => $runnerId,
            'hostname' => $runnerId,
            'site_id' => $siteId,
            'site_name' => $siteName,
            'collector_name' => $collectorName,
            'runner_version' => $runnerVersion,
            'last_inventory_status' => 'success',
            'last_upload_status' => 'queued',
        ]);

        $results = $ingest->ingestCsvText((string) file_get_contents($csvPath), basename($csvPath), 'collector_simulation', [
            'site_id' => $siteId,
            'site_name' => $siteName,
            'collector_name' => $collectorName,
            'runner_id' => $runnerId,
            'hostname' => $runnerId,
            'runner_version' => $runnerVersion,
            'last_inventory_status' => 'success',
            'last_upload_status' => 'uploaded',
        ]);

        if ($this->option('queue-scan')) {
            $commands->queue($runnerId, $siteId, 'scan_now', 'simulation');
        }

        $pending = $commands->pendingForSite($siteId, 100);
        $dispatched = $pending->map(fn ($command) => $commands->markDispatched($command));
        $acknowledged = null;

        if ($this->option('ack') && $dispatched->isNotEmpty()) {
            $acknowledged = $commands->acknowledge((int) $dispatched->first()->id, 'completed', 'Simulated command acknowledgement.');
        }

        $this->line(json_encode([
            'collector' => $collectorName,
            'runner_id' => $runnerId,
            'intake_results' => $results,
            'commands_dispatched' => $dispatched->pluck('id')->all(),
            'command_acknowledged' => $acknowledged?->id,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }

    private function resolvePath(string $path): string
    {
        $resolved = realpath($path);
        if ($resolved !== false) {
            return $resolved;
        }

        $resolved = realpath(base_path($path));
        if ($resolved !== false) {
            return $resolved;
        }

        return base_path($path);
    }
}
