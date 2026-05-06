<?php

namespace App\Console\Commands;

use App\Models\Runner;
use App\Models\RunnerCommand;
use Carbon\CarbonInterface;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

class DirectRunnerTriage extends Command
{
    private const HEARTBEAT_STALE_MINUTES = 30;
    private const POLL_STALE_MINUTES = 30;
    private const UPLOAD_STALE_HOURS = 24;
    private const ACK_STALE_MINUTES = 30;

    protected $signature = 'inventory:direct-runner-triage {runnerId}';

    protected $description = 'Read-only Direct HTTPS runner triage from Laravel database state';

    public function handle(): int
    {
        $searchedRunnerId = trim((string) $this->argument('runnerId'));
        $runner = Runner::query()
            ->with('site')
            ->where('runner_id', $searchedRunnerId)
            ->orWhere('hostname', $searchedRunnerId)
            ->orderByRaw('CASE WHEN runner_id = ? THEN 0 ELSE 1 END', [$searchedRunnerId])
            ->first();

        $this->line('Direct HTTPS Runner Triage');
        $this->line('Generated at: ' . now()->toDateTimeString());
        $this->newLine();
        $this->line('Environment');
        $this->line('[INFO] Laravel path: ' . base_path());
        $this->line('[INFO] APP_URL: ' . (string) config('app.url'));
        $this->line('[INFO] Direct HTTPS endpoint: https://inventory-pilot.internal.lan');
        $this->line('[INFO] Thresholds: heartbeat stale > ' . self::HEARTBEAT_STALE_MINUTES . ' minutes; direct poll stale > ' . self::POLL_STALE_MINUTES . ' minutes; upload stale > ' . self::UPLOAD_STALE_HOURS . ' hours; delivered awaiting ACK stale > ' . self::ACK_STALE_MINUTES . ' minutes.');
        $this->newLine();

        if ($runner === null) {
            $this->line('Runner');
            $this->line('[FAIL] Runner not found: ' . $searchedRunnerId);
            $this->line('Result: FAIL');

            return self::FAILURE;
        }

        if ((string) $runner->transport_mode !== 'direct_https') {
            $this->line('Runner');
            $this->line('[INFO] Searched runner ID: ' . $searchedRunnerId);
            $this->line('[INFO] Resolved runner ID: ' . $runner->runner_id);
            $this->line('[INFO] Transport mode: ' . ((string) $runner->transport_mode !== '' ? $runner->transport_mode : 'collector_share'));
            $this->line('[INFO] This command is Direct HTTPS-only.');
            $this->line('[INFO] Not applicable for collector-share mode.');
            $this->line('Result: SKIPPED');

            return self::SUCCESS;
        }

        $commands = RunnerCommand::query()
            ->where('runner_id', $runner->runner_id)
            ->orderByDesc('requested_at')
            ->orderByDesc('id')
            ->get();

        $latestCommand = $commands->first();
        $lastAckAt = $commands
            ->filter(fn (RunnerCommand $command): bool => $command->acknowledged_at !== null)
            ->max('acknowledged_at');
        $lastUploadAt = $runner->last_direct_upload_at ?: $runner->last_successful_inventory_at;
        $pendingCount = $commands->where('status', 'pending')->count();
        $awaitingAckCount = $commands->filter(fn (RunnerCommand $command): bool => $this->isAwaitingAck($command))->count();
        $staleNoAckCount = $commands->filter(fn (RunnerCommand $command): bool => $this->isStaleNoAck($command))->count();
        $failedCount = $commands->filter(fn (RunnerCommand $command): bool => $this->isFailedCommand($command))->count();
        $likelyStatus = $this->likelyStatus($runner, $commands, $lastUploadAt);

        $this->line('Runner');
        $this->line('[INFO] Searched runner ID: ' . $searchedRunnerId);
        $this->line('[INFO] Resolved runner ID: ' . $runner->runner_id);
        $this->line('[INFO] Hostname: ' . ($runner->hostname ?: 'not reported'));
        $this->line('[INFO] Site: ' . $this->siteLabel($runner));
        $this->line('[INFO] Transport mode: ' . $runner->transport_mode);
        $this->line('[INFO] Runner version: ' . ($runner->runner_version ?: 'not reported'));
        $this->line('[INFO] Runner GUID: ' . $this->maskedGuid($runner->runner_guid));
        $this->newLine();

        $this->line('Direct HTTPS timestamps');
        $this->line('[INFO] Last heartbeat: ' . $this->timeWithAge($runner->last_direct_heartbeat_at ?: $runner->last_seen_at, 'No heartbeat yet'));
        $this->line('[INFO] Last direct command poll: ' . $this->timeWithAge($runner->last_direct_poll_at, 'No direct poll yet'));
        $this->line('[INFO] Last direct upload/inventory: ' . $this->timeWithAge($lastUploadAt, 'No upload yet'));
        $this->line('[INFO] Last ACK: ' . $this->timeWithAge($lastAckAt, 'No ACK yet'));
        $this->newLine();

        $this->line('Command summary');
        $this->line('[INFO] Pending queued command count: ' . $pendingCount);
        $this->line('[INFO] Delivered awaiting ACK count: ' . $awaitingAckCount);
        $this->line('[INFO] Stale dispatched/no ACK count: ' . $staleNoAckCount);
        $this->line('[INFO] Failed command count: ' . $failedCount);
        $this->line('[INFO] Latest command: ' . ($latestCommand ? $this->latestCommandSummary($latestCommand) : 'No direct commands yet'));
        $this->newLine();

        $this->line('Likely status');
        $this->line('[STATUS] ' . $likelyStatus);
        $this->newLine();

        $this->line('Safe next checks');
        foreach ($this->hints($likelyStatus) as $hint) {
            $this->line('- ' . $hint);
        }
        $this->line('- Do not use Direct repair_update; it remains blocked for Direct HTTPS MVP.');
        $this->line('- Do not paste tokens, Authorization headers, full configs, raw CSV, or command payloads.');
        $this->line('- This command is read-only: it does not create, retry, redeliver, or update runner commands.');
        $this->line('- Polling means delivery, not execution. ACK is the execution result.');
        $this->newLine();

        $this->line('Result: ' . ($likelyStatus === 'OK' ? 'OK' : 'ATTENTION'));

        return self::SUCCESS;
    }

    private function siteLabel(Runner $runner): string
    {
        $siteId = (string) ($runner->site_id ?: 'not reported');
        $siteName = (string) ($runner->site?->site_name ?: '');

        return $siteName !== '' ? "{$siteId} / {$siteName}" : $siteId;
    }

    private function maskedGuid(mixed $guid): string
    {
        $guid = trim((string) $guid);

        return $guid === '' ? 'not present' : 'present ending ' . strtoupper(substr($guid, -4));
    }

    private function timeWithAge(mixed $value, string $missing): string
    {
        $time = $this->asTime($value);
        if ($time === null) {
            return $missing;
        }

        return $time->toDateTimeString() . ' (' . $time->diffForHumans(now(), [
            'parts' => 2,
            'short' => true,
            'syntax' => CarbonInterface::DIFF_RELATIVE_TO_NOW,
        ]) . ')';
    }

    private function latestCommandSummary(RunnerCommand $command): string
    {
        return implode('; ', [
            'command ID ' . $command->id,
            'type ' . $command->command_type,
            'status ' . $command->status,
            'requested ' . $this->timeWithAge($command->requested_at, 'missing'),
            'dispatched/delivered ' . $this->timeWithAge($command->delivered_to_runner_at ?: $command->dispatched_at, 'missing'),
            'acknowledged ' . $this->timeWithAge($command->acknowledged_at, 'missing'),
            'completed ' . $this->timeWithAge($command->completed_at, 'missing'),
            'completion_status ' . ($command->completion_status ?: 'missing'),
            'result_upload_id ' . ($command->result_upload_id ? 'present' : 'missing'),
        ]);
    }

    private function likelyStatus(Runner $runner, Collection $commands, mixed $lastUploadAt): string
    {
        if ($commands->contains(fn (RunnerCommand $command): bool => $this->isFailedCommand($command))) {
            return 'failed command present';
        }

        if ($commands->contains(fn (RunnerCommand $command): bool => $this->isStaleNoAck($command))) {
            return 'stale dispatched/no ACK';
        }

        if ($commands->contains(fn (RunnerCommand $command): bool => $this->isAwaitingAck($command))) {
            return 'command awaiting ACK';
        }

        $heartbeatAt = $runner->last_direct_heartbeat_at ?: $runner->last_seen_at;
        if ($heartbeatAt === null || $this->olderThanMinutes($heartbeatAt, self::HEARTBEAT_STALE_MINUTES)) {
            return 'stale/offline';
        }

        if ($runner->last_direct_poll_at === null || $this->olderThanMinutes($runner->last_direct_poll_at, self::POLL_STALE_MINUTES)) {
            return 'no command poll yet';
        }

        if ($lastUploadAt === null || $this->olderThanHours($lastUploadAt, self::UPLOAD_STALE_HOURS)) {
            return 'upload missing';
        }

        return 'OK';
    }

    private function hints(string $status): array
    {
        return match ($status) {
            'failed command present' => [
                'Review latest command status and runner logs without payloads or secrets.',
            ],
            'stale dispatched/no ACK' => [
                'Polling means delivery, ACK is execution result.',
                'Check runner execution logs and state\\direct-acks.',
            ],
            'command awaiting ACK' => [
                'Polling means delivery, ACK is execution result.',
                'Check runner execution logs and state\\direct-acks.',
            ],
            'stale/offline' => [
                'Get-ScheduledTaskInfo -TaskName "InternalInventoryRunner"',
                'Get-ScheduledTaskInfo -TaskName "InternalInventoryRunner-Startup"',
            ],
            'no command poll yet' => [
                'Resolve-DnsName inventory-pilot.internal.lan',
                'Test-NetConnection inventory-pilot.internal.lan -Port 443',
                'Invoke-WebRequest https://inventory-pilot.internal.lan/health -UseBasicParsing',
            ],
            'upload missing' => [
                'Check runner logs, outbox pending/failed counts, and Devices page.',
            ],
            default => [
                'Continue normal monitoring in the portal and with inventory:direct-pilot-status.',
            ],
        };
    }

    private function isAwaitingAck(RunnerCommand $command): bool
    {
        return $command->status === 'dispatched' && $command->acknowledged_at === null;
    }

    private function isStaleNoAck(RunnerCommand $command): bool
    {
        if (! $this->isAwaitingAck($command)) {
            return false;
        }

        $reference = $command->delivered_to_runner_at ?: $command->dispatched_at ?: $command->requested_at;

        return $this->olderThanMinutes($reference, self::ACK_STALE_MINUTES);
    }

    private function isFailedCommand(RunnerCommand $command): bool
    {
        return $command->status === 'failed' || $command->completion_status === 'failed';
    }

    private function olderThanMinutes(mixed $value, int $minutes): bool
    {
        $time = $this->asTime($value);

        return $time === null || $time->lt(now()->subMinutes($minutes));
    }

    private function olderThanHours(mixed $value, int $hours): bool
    {
        $time = $this->asTime($value);

        return $time === null || $time->lt(now()->subHours($hours));
    }

    private function asTime(mixed $value): ?CarbonInterface
    {
        if ($value instanceof CarbonInterface) {
            return $value;
        }

        if ($value === null || $value === '') {
            return null;
        }

        return rescue(fn () => now()->parse($value), null, false);
    }
}
