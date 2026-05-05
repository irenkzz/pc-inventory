<?php

namespace App\Services\Runner;

use App\Models\RunnerCommand;
use Illuminate\Http\Request;

class DirectRunnerCommandPollService
{
    public function __construct(private readonly DirectRunnerCommandAuthService $auth)
    {
    }

    public function poll(Request $request, array $payload): array
    {
        $now = now();
        $redeliveryCutoff = $now->copy()->subMinutes($this->redeliveryMinutes());
        $context = $this->auth->authenticateRunner($request, $payload, [
            'last_direct_poll_at' => $now->toIso8601String(),
        ]);

        $commands = RunnerCommand::query()
            ->where('site_id', $context['site_code'])
            ->where('runner_id', $context['runner_id'])
            ->where(function ($query) use ($redeliveryCutoff): void {
                $query->where('status', 'pending')
                    ->orWhere(function ($nested) use ($redeliveryCutoff): void {
                        $nested->where('status', 'dispatched')
                            ->whereNull('acknowledged_at')
                            ->whereNull('completed_at')
                            ->whereNull('completion_status')
                            ->whereNotNull('delivered_to_runner_at')
                            ->where('delivered_to_runner_at', '<=', $redeliveryCutoff);
                    });
            })
            ->orderBy('requested_at')
            ->orderBy('id')
            ->get()
            ->map(fn (RunnerCommand $command): array => $this->markDelivered($command, $now)->toArray())
            ->values()
            ->all();

        return [
            'status' => 'ok',
            'site_code' => $context['site_code'],
            'runner_id' => $context['runner_id'],
            'commands' => $commands,
        ];
    }

    private function markDelivered(RunnerCommand $command, mixed $deliveredAt): RunnerCommand
    {
        $attributes = [
            'delivered_to_runner_at' => $deliveredAt,
        ];

        if ($command->status === 'pending') {
            $attributes['status'] = 'dispatched';
            $attributes['dispatched_at'] = $deliveredAt;
        }

        $command->forceFill($attributes)->save();

        return $command->refresh();
    }

    private function redeliveryMinutes(): int
    {
        $minutes = (int) config('inventory.direct_command_redelivery_minutes', 3);

        return max(1, $minutes);
    }
}
