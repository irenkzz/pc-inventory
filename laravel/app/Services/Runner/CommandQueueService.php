<?php

namespace App\Services\Runner;

use App\Models\RunnerCommand;
use App\Services\Inventory\CsvNormalizer;
use Illuminate\Database\Eloquent\Collection;

class CommandQueueService
{
    public function __construct(private readonly CsvNormalizer $normalizer)
    {
    }

    public function queue(string $runnerId, ?string $siteId, string $commandType, string $requestedBy = 'portal', array $payload = []): RunnerCommand
    {
        $normalizedRunnerId = $this->normalizer->normalizeWhitespace($runnerId);
        $normalizedSiteId = $this->normalizer->normalizeWhitespace($siteId) ?: null;
        $normalizedCommandType = $this->normalizer->normalizeWhitespace($commandType);

        RunnerCommand::query()
            ->where('runner_id', $normalizedRunnerId)
            ->whereIn('status', ['pending', 'dispatched'])
            ->update([
                'status' => 'superseded',
                'completed_at' => now(),
                'completion_status' => 'superseded',
                'completion_message' => 'Superseded by a newer command for this runner. The branch share supports one active command file per runner.',
            ]);

        return RunnerCommand::query()->create([
            'runner_id' => $normalizedRunnerId,
            'site_id' => $normalizedSiteId,
            'command_type' => $normalizedCommandType,
            'payload_json' => $payload,
            'requested_by' => $this->normalizer->normalizeWhitespace($requestedBy) ?: 'portal',
            'status' => 'pending',
            'requested_at' => now(),
        ]);
    }

    public function activeForRunner(string $runnerId, ?string $commandType = null): ?RunnerCommand
    {
        $query = RunnerCommand::query()
            ->where('runner_id', $this->normalizer->normalizeWhitespace($runnerId))
            ->whereIn('status', ['pending', 'dispatched'])
            ->orderByDesc('requested_at')
            ->orderByDesc('id');

        $normalizedCommandType = $this->normalizer->normalizeWhitespace($commandType);
        if ($normalizedCommandType !== '') {
            $query->where('command_type', $normalizedCommandType);
        }

        return $query->first();
    }

    public function pendingForSite(string $siteId, int $limit = 100): Collection
    {
        return RunnerCommand::query()
            ->where('site_id', $this->normalizer->normalizeWhitespace($siteId))
            ->where('status', 'pending')
            ->orderBy('requested_at')
            ->orderBy('id')
            ->limit($limit)
            ->get();
    }

    public function markDispatched(RunnerCommand $command): RunnerCommand
    {
        if ($command->status === 'pending') {
            $command->forceFill([
                'status' => 'dispatched',
                'dispatched_at' => now(),
            ])->save();
        }

        return $command->refresh();
    }

    public function acknowledge(int $commandId, string $status, string $message = '', ?string $siteId = null): ?RunnerCommand
    {
        $command = RunnerCommand::query()
            ->when(($siteId ?? '') !== '', fn ($q) => $q->where('site_id', $siteId))
            ->find($commandId);
        if ($command === null) {
            return null;
        }

        // Already finished by a real ACK: keep the first result (superseded is not a runner result).
        if ($command->completed_at !== null && $command->status !== 'superseded') {
            return $command;
        }

        $completionStatus = $this->normalizer->normalizeWhitespace($status) ?: 'completed';
        $command->forceFill([
            'status' => $completionStatus,
            'acknowledged_at' => $command->acknowledged_at ?? now(),
            'completed_at' => now(),
            'completion_status' => $completionStatus,
            'completion_message' => $this->normalizer->normalizeWhitespace($message),
        ])->save();

        return $command->refresh();
    }
}
