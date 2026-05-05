<?php

namespace App\Services\Runner;

use App\Models\RawFile;
use App\Services\Inventory\CsvNormalizer;
use App\Services\Inventory\InventoryIngestService;
use App\Services\Security\DirectRunnerAuthService;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class DirectRunnerScanUploadService
{
    public function __construct(
        private readonly DirectRunnerAuthService $auth,
        private readonly CsvNormalizer $normalizer,
        private readonly InventoryIngestService $ingest,
        private readonly RunnerStatusService $runnerStatus,
    ) {
    }

    public function ingest(Request $request): array
    {
        $metadata = $this->metadata((string) $request->input('metadata', ''));
        $authContext = $this->auth->authenticate($this->authRequest($request, $metadata));
        $siteCode = (string) $authContext['site_code'];
        abort_if(($metadata['site_code'] ?? $metadata['site_id'] ?? '') !== $siteCode, 401);

        /** @var UploadedFile $file */
        $file = $request->file('file');
        $csvText = (string) file_get_contents($file->getRealPath());
        $fileSha256 = strtolower((string) $metadata['file_sha256']);

        if (! hash_equals($fileSha256, hash('sha256', $csvText))) {
            $this->recordUploadState($metadata, $siteCode, 'failed', 'file_sha256 does not match uploaded file content.');
            throw ValidationException::withMessages(['file_sha256' => 'file_sha256 does not match uploaded file content.']);
        }

        if ($this->normalizer->csvTextToPayloads($csvText) === []) {
            $this->recordUploadState($metadata, $siteCode, 'failed', 'Uploaded file is not a readable inventory CSV.');
            throw ValidationException::withMessages(['file' => 'Uploaded file is not a readable inventory CSV.']);
        }

        $rawHash = $this->normalizer->computeRawHash($csvText, 'csv');
        $existingByUploadId = $this->existingByUploadId((string) ($metadata['upload_id'] ?? ''));
        if ($existingByUploadId !== null) {
            $this->recordUploadState($metadata, $siteCode, 'uploaded', '');

            return $this->idempotentResult($existingByUploadId, 'duplicate_upload_id');
        }

        $existingByHash = RawFile::query()->where('raw_hash', $rawHash)->first();
        if ($existingByHash !== null) {
            $this->recordUploadState($metadata, $siteCode, 'uploaded', '');

            return $this->idempotentResult($existingByHash, 'duplicate_file_sha256');
        }

        $rawMetadata = [
            'site_id' => $siteCode,
            'runner_id' => (string) $metadata['runner_id'],
            'runner_guid' => (string) ($metadata['runner_guid'] ?? ''),
            'hostname' => (string) ($metadata['hostname'] ?? ''),
            'runner_version' => (string) ($metadata['runner_version'] ?? ''),
            'install_mode' => 'direct_https',
            'transport_mode' => 'direct_https',
            'last_successful_inventory_at' => (string) ($metadata['scan_finished_at'] ?? ''),
            'last_inventory_status' => 'success',
            'last_upload_status' => 'uploaded',
            'last_upload_error' => '',
            'last_direct_upload_at' => now()->toIso8601String(),
            'upload_id' => (string) ($metadata['upload_id'] ?? ''),
            'file_sha256' => $fileSha256,
            'retry_count' => (int) ($metadata['retry_count'] ?? 0),
            'scan_started_at' => (string) ($metadata['scan_started_at'] ?? ''),
            'scan_finished_at' => (string) ($metadata['scan_finished_at'] ?? ''),
        ];

        $results = $this->ingest->ingestCsvText(
            $csvText,
            $file->getClientOriginalName() ?: 'scan.csv',
            'direct_runner_csv',
            $rawMetadata,
        );

        $this->recordUploadState($metadata, $siteCode, 'uploaded', '');

        return [
            'status' => 'ok',
            'idempotent' => false,
            'reason' => 'processed',
            'raw_hash' => $rawHash,
            'results' => $results,
        ];
    }

    private function metadata(string $json): array
    {
        $decoded = json_decode($json, true);
        if (! is_array($decoded)) {
            throw ValidationException::withMessages(['metadata' => 'metadata must be a valid JSON object.']);
        }

        Validator::make($decoded, [
            'site_code' => ['nullable', 'string', 'max:100'],
            'site_id' => ['nullable', 'string', 'max:100'],
            'runner_id' => ['required', 'string', 'max:255'],
            'runner_guid' => ['nullable', 'string', 'max:100'],
            'hostname' => ['nullable', 'string', 'max:255'],
            'runner_version' => ['nullable', 'string', 'max:100'],
            'transport_mode' => ['required', 'in:direct_https'],
            'scan_started_at' => ['nullable', 'date'],
            'scan_finished_at' => ['nullable', 'date'],
            'upload_id' => ['nullable', 'string', 'max:255'],
            'file_sha256' => ['required', 'string', 'size:64', 'regex:/^[A-Fa-f0-9]{64}$/'],
            'retry_count' => ['nullable', 'integer', 'min:0'],
        ])->validate();

        return $decoded;
    }

    private function authRequest(Request $request, array $metadata): Request
    {
        $payload = $request->request->all();
        foreach (['site_code', 'site_id'] as $field) {
            if (array_key_exists($field, $metadata)) {
                $payload[$field] = $metadata[$field];
            }
        }

        return $request->duplicate(
            $request->query->all(),
            $payload,
            $request->attributes->all(),
            $request->cookies->all(),
            $request->files->all(),
            $request->server->all(),
        );
    }

    private function existingByUploadId(string $uploadId): ?RawFile
    {
        $uploadId = $this->normalizer->normalizeWhitespace($uploadId);
        if ($uploadId === '') {
            return null;
        }

        return RawFile::query()->where('upload_id', $uploadId)->first();
    }

    private function idempotentResult(RawFile $rawFile, string $reason): array
    {
        return [
            'status' => 'ok',
            'idempotent' => true,
            'reason' => $reason,
            'raw_hash' => $rawFile->raw_hash,
            'scan_id' => $rawFile->scan?->id,
        ];
    }

    private function recordUploadState(array $metadata, string $siteCode, string $status, string $error): void
    {
        $this->runnerStatus->upsert([
            'site_id' => $siteCode,
            'runner_id' => (string) ($metadata['runner_id'] ?? ''),
            'runner_guid' => (string) ($metadata['runner_guid'] ?? ''),
            'hostname' => (string) ($metadata['hostname'] ?? ''),
            'runner_version' => (string) ($metadata['runner_version'] ?? ''),
            'install_mode' => 'direct_https',
            'transport_mode' => 'direct_https',
            'last_seen_at' => now()->toIso8601String(),
            'last_direct_upload_at' => now()->toIso8601String(),
            'last_upload_status' => $status,
            'last_upload_error' => $error,
            'last_error' => $error,
        ]);
    }
}
