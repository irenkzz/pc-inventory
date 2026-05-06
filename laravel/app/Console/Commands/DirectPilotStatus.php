<?php

namespace App\Console\Commands;

use App\Models\Runner;
use App\Models\RunnerCommand;
use App\Services\Security\SiteTokenStore;
use Carbon\CarbonInterface;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

class DirectPilotStatus extends Command
{
    protected $signature = 'inventory:direct-pilot-status';

    protected $description = 'Read-only Direct HTTPS pilot health summary';

    public function handle(SiteTokenStore $siteTokens): int
    {
        $generatedAt = now();
        $appUrl = (string) config('app.url', '');
        $appUrlStatus = str_starts_with(strtolower($appUrl), 'https://')
            ? 'OK'
            : 'Warning: APP_URL is not HTTPS. Confirm Direct HTTPS site profile/serverBaseUrl uses the pilot HTTPS endpoint.';

        $this->info('Direct HTTPS pilot status');
        $this->table(['Field', 'Value'], [
            ['generated_at', $generatedAt->toDateTimeString()],
            ['APP_URL', $appUrl],
            ['APP_URL HTTPS status', $appUrlStatus],
            ['Direct repair_update status', 'blocked for Direct HTTPS MVP'],
        ]);

        $directRunners = Runner::query()
            ->with('site')
            ->where('transport_mode', 'direct_https')
            ->orderBy('site_id')
            ->orderBy('runner_id')
            ->get();

        $this->newLine();
        $this->info('Direct HTTPS runners');
        if ($directRunners->isEmpty()) {
            $this->line('No Direct HTTPS runners found.');
        } else {
            $this->table([
                'Site',
                'Runner',
                'Hostname',
                'Version',
                'GUID',
                'Heartbeat',
                'Direct poll',
                'Last upload/inventory',
                'Last ACK',
                'Health',
                'Pending',
                'Delivered awaiting ACK',
                'Stale dispatched',
                'Failed',
                'Latest command',
            ], $directRunners->map(fn (Runner $runner): array => $this->runnerRow($runner, $generatedAt))->all());
        }

        $this->newLine();
        $this->info('Direct runner token metadata');
        $tokenRecords = $siteTokens->records(SiteTokenStore::TYPE_DIRECT_RUNNER);
        if ($tokenRecords === []) {
            $this->line('No direct_runner token metadata found.');
        } else {
            $this->table([
                'Site',
                'Token type',
                'Status',
                'Last used at',
                'Last used IP',
            ], array_map(fn (array $record): array => [
                $record['site_id'],
                $record['token_type'],
                $record['revoked_at'] ? 'revoked' : 'active',
                $record['last_used_at'] ?: 'Token has not been used yet',
                $record['last_used_ip'] ?: 'Token has not been used yet',
            ], $tokenRecords));
        }

        $collectorShareCount = Runner::query()
            ->where(fn ($query) => $query->whereNull('transport_mode')->orWhere('transport_mode', '!=', 'direct_https'))
            ->count();

        $this->newLine();
        $this->info('Collector-share summary');
        $this->table(['Field', 'Value'], [
            ['collector_share runner count', (string) $collectorShareCount],
            ['direct poll status', 'Not applicable for collector-share mode'],
            ['note', 'Collector-share mode is not modified by this report.'],
        ]);

        $this->newLine();
        $this->line('Command wording: polling means delivery, not execution. ACK is the execution result.');
        $this->line('Direct repair_update remains blocked.');

        return self::SUCCESS;
    }

    private function runnerRow(Runner $runner, CarbonInterface $generatedAt): array
    {
        $commands = RunnerCommand::query()
            ->where('runner_id', $runner->runner_id)
            ->orderByDesc('requested_at')
            ->get();

        $pendingCount = $commands->where('status', 'pending')->count();
        $deliveredAwaitingAckCount = $commands
            ->filter(fn (RunnerCommand $command): bool => $command->status === 'dispatched' && $command->acknowledged_at === null)
            ->count();
        $staleDispatchedCount = $commands
            ->filter(fn (RunnerCommand $command): bool => $this->isStaleDispatched($command, $generatedAt))
            ->count();
        $failedCount = $commands
            ->filter(fn (RunnerCommand $command): bool => $command->status === 'failed' || $command->completion_status === 'failed')
            ->count();
        $latestCommand = $commands->first();
        $latestAckAt = $commands
            ->filter(fn (RunnerCommand $command): bool => $command->acknowledged_at !== null)
            ->max('acknowledged_at');

        $lastUploadAt = $runner->last_direct_upload_at ?: $runner->last_successful_inventory_at;

        return [
            $this->siteLabel($runner),
            $runner->runner_id,
            $runner->hostname ?: '-',
            $runner->runner_version ?: 'Version not reported',
            $this->maskedGuid($runner->runner_guid),
            $this->timeWithAge($runner->last_direct_heartbeat_at ?: $runner->last_seen_at, 'No heartbeat yet'),
            $this->timeWithAge($runner->last_direct_poll_at, 'No direct poll yet'),
            $this->timeWithAge($lastUploadAt, 'No upload yet'),
            $this->timeWithAge($latestAckAt, 'No ACK yet'),
            $this->healthLabel($runner, $commands, $generatedAt),
            (string) $pendingCount,
            (string) $deliveredAwaitingAckCount,
            (string) $staleDispatchedCount,
            (string) $failedCount,
            $latestCommand ? $this->commandSummary($latestCommand) : 'No direct commands yet',
        ];
    }

    private function siteLabel(Runner $runner): string
    {
        $siteId = (string) ($runner->site_id ?: '-');
        $siteName = (string) ($runner->site?->site_name ?: '');

        return $siteName !== '' ? "{$siteId} / {$siteName}" : $siteId;
    }

    private function maskedGuid(mixed $guid): string
    {
        $guid = trim((string) $guid);

        return $guid === '' ? 'Not present' : 'Present ending ' . substr($guid, -4);
    }

    private function timeWithAge(mixed $value, string $missing): string
    {
        if ($value === null || $value === '') {
            return $missing;
        }

        $time = $value instanceof CarbonInterface ? $value : rescue(fn () => now()->parse($value), null, false);
        if (! $time instanceof CarbonInterface) {
            return $missing;
        }

        return $time->toDateTimeString() . ' (' . $time->diffForHumans(now(), [
            'parts' => 2,
            'short' => true,
            'syntax' => CarbonInterface::DIFF_RELATIVE_TO_NOW,
        ]) . ')';
    }

    private function healthLabel(Runner $runner, Collection $commands, CarbonInterface $generatedAt): string
    {
        $heartbeatAt = $runner->last_direct_heartbeat_at ?: $runner->last_seen_at;
        $pollAt = $runner->last_direct_poll_at;

        if ($heartbeatAt === null && $pollAt === null && $runner->last_direct_upload_at === null && $commands->isEmpty()) {
            return 'No data yet';
        }

        if ($commands->contains(fn (RunnerCommand $command): bool => $this->isStaleDispatched($command, $generatedAt))) {
            return 'Stale';
        }

        if ($this->olderThan($heartbeatAt, $generatedAt, 60) || $this->olderThan($pollAt, $generatedAt, 60)) {
            return 'Stale';
        }

        if ($heartbeatAt === null || $pollAt === null || $runner->last_direct_upload_at === null) {
            return 'Warning';
        }

        return 'OK';
    }

    private function olderThan(mixed $value, CarbonInterface $now, int $minutes): bool
    {
        if ($value === null || $value === '') {
            return false;
        }

        $time = $value instanceof CarbonInterface ? $value : rescue(fn () => now()->parse($value), null, false);

        return $time instanceof CarbonInterface && $time->lt($now->copy()->subMinutes($minutes));
    }

    private function isStaleDispatched(RunnerCommand $command, CarbonInterface $generatedAt): bool
    {
        if ($command->status !== 'dispatched' || $command->acknowledged_at !== null) {
            return false;
        }

        $reference = $command->delivered_to_runner_at ?: $command->dispatched_at ?: $command->requested_at;
        if ($reference === null) {
            return false;
        }

        $staleMinutes = max(1, (int) config('inventory.direct_command_redelivery_minutes', 3));

        return $reference->lt($generatedAt->copy()->subMinutes($staleMinutes));
    }

    private function commandSummary(RunnerCommand $command): string
    {
        return implode(' / ', array_filter([
            $command->command_type,
            $command->status,
            'requested ' . $this->timeWithAge($command->requested_at, 'unknown'),
            $command->delivered_to_runner_at ? 'delivered ' . $this->timeWithAge($command->delivered_to_runner_at, 'unknown') : null,
            $command->acknowledged_at ? 'ACK ' . $this->timeWithAge($command->acknowledged_at, 'unknown') : null,
        ]));
    }
}
