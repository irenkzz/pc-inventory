<?php

namespace App\Services\Runner;

use App\Models\Runner;

class DirectRunnerHeartbeatService
{
    public function __construct(private readonly RunnerStatusService $runnerStatus)
    {
    }

    public function upsert(array $payload, array $authContext): Runner
    {
        $now = now()->toIso8601String();
        $lastError = (string) ($payload['last_error'] ?? '');

        return $this->runnerStatus->upsert([
            'site_id' => (string) $authContext['site_code'],
            'runner_id' => (string) $payload['runner_id'],
            'runner_guid' => (string) ($payload['runner_guid'] ?? ''),
            'hostname' => (string) ($payload['hostname'] ?? ''),
            'runner_version' => (string) ($payload['runner_version'] ?? ''),
            'install_mode' => 'direct_https',
            'transport_mode' => 'direct_https',
            'local_time' => (string) ($payload['local_time'] ?? ''),
            'last_seen_at' => $now,
            'last_direct_heartbeat_at' => $now,
            'last_successful_inventory_at' => (string) ($payload['last_scan_at'] ?? ''),
            'last_upload_status' => (string) ($payload['last_upload_status'] ?? ''),
            'last_upload_error' => $lastError,
            'last_error' => $lastError,
            'auth' => [
                'site_code' => $authContext['site_code'],
                'token_type' => $authContext['token_type'],
            ],
        ]);
    }
}
