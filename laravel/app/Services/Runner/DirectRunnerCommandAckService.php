<?php

namespace App\Services\Runner;

use App\Models\RunnerCommand;
use App\Services\Inventory\CsvNormalizer;
use Illuminate\Http\Request;

class DirectRunnerCommandAckService
{
    public function __construct(
        private readonly DirectRunnerCommandAuthService $auth,
        private readonly CsvNormalizer $normalizer,
    ) {
    }

    public function acknowledge(Request $request, array $payload): array
    {
        $context = $this->auth->authenticateRunner($request, $payload);

        $command = RunnerCommand::query()
            ->where('id', (int) $payload['command_id'])
            ->where('site_id', $context['site_code'])
            ->where('runner_id', $context['runner_id'])
            ->first();

        abort_if($command === null, 401);

        $completionStatus = (string) $payload['status'];

        if ($command->completed_at !== null && $command->status !== 'superseded') {
            // At-least-once delivery: identical re-ACK is a no-op, a conflicting one is refused.
            abort_if($command->completion_status !== $completionStatus, 409);

            return [
                'status' => 'ok',
                'site_code' => $context['site_code'],
                'runner_id' => $context['runner_id'],
                'command_id' => $command->id,
                'completion_status' => $completionStatus,
            ];
        }

        $command->forceFill([
            'status' => $completionStatus,
            'acknowledged_at' => now(),
            'completed_at' => now(),
            'completion_status' => $completionStatus,
            'completion_message' => $this->normalizer->normalizeWhitespace($payload['message'] ?? ''),
            'result_upload_id' => $this->normalizer->normalizeWhitespace($payload['result_upload_id'] ?? '') ?: null,
        ])->save();

        return [
            'status' => 'ok',
            'site_code' => $context['site_code'],
            'runner_id' => $context['runner_id'],
            'command_id' => $command->id,
            'completion_status' => $completionStatus,
        ];
    }
}
