<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CollectorStatusRequest;
use App\Http\Requests\CommandAckRequest;
use App\Http\Requests\RunnerHeartbeatRequest;
use App\Services\Collector\CollectorStatusService;
use App\Services\Inventory\InventoryIngestService;
use App\Services\Runner\CommandQueueService;
use App\Services\Runner\RunnerStatusService;
use App\Services\Security\SiteTokenVerifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CollectorIntakeController extends Controller
{
    public function __construct(
        private readonly SiteTokenVerifier $siteTokenVerifier,
        private readonly CollectorStatusService $collectorStatus,
        private readonly RunnerStatusService $runnerStatus,
        private readonly InventoryIngestService $ingest,
        private readonly CommandQueueService $commands,
    ) {
    }

    public function status(CollectorStatusRequest $request): JsonResponse
    {
        $siteId = $this->siteTokenVerifier->verify($request);
        $payload = $request->validated();
        $payload['site_id'] = $this->verifiedSite($siteId, $payload['site_id'] ?? null);

        $this->collectorStatus->upsert($payload);

        return response()->json(['status' => 'ok']);
    }

    public function heartbeat(RunnerHeartbeatRequest $request): JsonResponse
    {
        $siteId = $this->siteTokenVerifier->verify($request);
        $payload = $request->validated();
        $payload['site_id'] = $this->verifiedSite($siteId, $payload['site_id'] ?? null);

        $this->runnerStatus->upsert($payload);

        return response()->json(['status' => 'ok']);
    }

    public function csv(Request $request): JsonResponse
    {
        $siteId = $this->siteTokenVerifier->verify($request);
        $request->validate([
            'file' => ['required', 'file', 'max:' . (int) config('inventory.max_upload_kb', 5120)],
        ]);

        $file = $request->file('file');
        $metadata = [
            'site_id' => $siteId,
            'site_name' => (string) $request->input('site_name', ''),
            'collector_name' => (string) $request->input('collector_name', ''),
            'runner_id' => (string) $request->input('runner_id', ''),
            'hostname' => (string) $request->input('hostname', $request->input('runner_id', '')),
            'runner_version' => (string) $request->input('runner_version', ''),
            'last_successful_inventory_at' => (string) $request->input('last_successful_inventory_at', $request->input('scan_time', '')),
            'last_inventory_status' => (string) $request->input('last_inventory_status', 'success'),
            'last_upload_status' => 'uploaded',
            'last_seen_at' => (string) $request->input('last_seen_at', ''),
        ];

        return response()->json($this->ingest->ingestCsvText(
            (string) file_get_contents($file->getRealPath()),
            $file->getClientOriginalName() ?: 'scan.csv',
            'collector_csv',
            $metadata,
        ));
    }

    public function commands(Request $request): JsonResponse
    {
        $siteId = $this->siteTokenVerifier->verify($request);
        $items = $this->commands->pendingForSite($siteId, 100)
            ->map(fn ($command) => $this->commands->markDispatched($command)->toArray())
            ->values();

        return response()->json($items);
    }

    public function acknowledge(CommandAckRequest $request): JsonResponse
    {
        $siteId = $this->siteTokenVerifier->verify($request);
        $payload = $request->validated();

        $command = $this->commands->acknowledge(
            (int) $payload['command_id'],
            (string) ($payload['status'] ?? 'completed'),
            (string) ($payload['message'] ?? ''),
            $siteId,
        );
        abort_if($command === null, 404);

        if (isset($payload['runner_state']) && is_array($payload['runner_state'])) {
            $payload['runner_state']['site_id'] = $this->verifiedSite($siteId, $payload['runner_state']['site_id'] ?? null);
            $this->runnerStatus->upsert($payload['runner_state']);
        }

        return response()->json(['status' => 'ok']);
    }

    /** The token-verified site always wins; a conflicting payload site_id is rejected. */
    private function verifiedSite(string $siteId, mixed $payloadSite): string
    {
        $payloadSite = trim((string) $payloadSite);
        // Open mode (no tokens configured) has no verified site to enforce.
        if ($siteId === '') {
            return $payloadSite;
        }
        abort_if($payloadSite !== '' && $payloadSite !== $siteId, 403);

        return $siteId;
    }
}
