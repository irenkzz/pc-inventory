<?php

namespace Tests\Feature;

use App\Models\DeviceScan;
use App\Models\RawFile;
use App\Models\Runner;
use App\Services\Security\SiteTokenStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class DirectRunnerScanUploadApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_valid_direct_scan_upload_is_accepted(): void
    {
        $this->configureDirectRunnerToken();
        $csv = $this->sampleCsv();

        $this->post('/api/direct-runner/scans', [
            'metadata' => json_encode($this->metadata($csv)),
            'file' => $this->file($csv),
        ], $this->headers('direct-runner-token'))->assertOk()->assertJson([
            'status' => 'ok',
            'idempotent' => false,
            'reason' => 'processed',
        ]);

        $this->assertSame(1, DeviceScan::query()->count());
        $this->assertSame(1, RawFile::query()->count());
        $this->assertDatabaseHas('raw_files', [
            'upload_id' => 'direct-upload-1',
            'raw_format' => 'csv',
        ]);
        $this->assertDatabaseHas('runners', [
            'runner_id' => 'PC-DIRECT-01',
            'site_id' => 'SITE-HQ',
            'transport_mode' => 'direct_https',
            'last_upload_status' => 'uploaded',
            'last_upload_error' => '',
        ]);
        $this->assertNotNull(Runner::query()->where('runner_id', 'PC-DIRECT-01')->firstOrFail()->last_direct_upload_at);
    }

    public function test_collector_token_is_rejected_for_direct_scan_upload(): void
    {
        Config::set('inventory.site_tokens_json', json_encode([
            'SITE-HQ' => [
                'token' => 'collector-token',
                'token_type' => SiteTokenStore::TYPE_COLLECTOR,
            ],
        ]));
        $csv = $this->sampleCsv();

        $this->post('/api/direct-runner/scans', [
            'metadata' => json_encode($this->metadata($csv)),
            'file' => $this->file($csv),
        ], $this->headers('collector-token'))->assertUnauthorized();
    }

    public function test_revoked_direct_runner_token_is_rejected_for_direct_scan_upload(): void
    {
        Config::set('inventory.site_tokens_json', json_encode([
            'SITE-HQ' => [
                'token' => 'revoked-direct-runner-token',
                'token_type' => SiteTokenStore::TYPE_DIRECT_RUNNER,
                'revoked_at' => '2026-05-01T00:00:00+00:00',
            ],
        ]));
        $csv = $this->sampleCsv();

        $this->post('/api/direct-runner/scans', [
            'metadata' => json_encode($this->metadata($csv)),
            'file' => $this->file($csv),
        ], $this->headers('revoked-direct-runner-token'))->assertUnauthorized();
    }

    public function test_bad_sha_is_rejected(): void
    {
        $this->configureDirectRunnerToken();
        $csv = $this->sampleCsv();
        $metadata = $this->metadata($csv);
        $metadata['file_sha256'] = str_repeat('a', 64);

        $this->post('/api/direct-runner/scans', [
            'metadata' => json_encode($metadata),
            'file' => $this->file($csv),
        ], $this->headers('direct-runner-token'))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('file_sha256');

        $this->assertSame(0, DeviceScan::query()->count());
    }

    public function test_duplicate_upload_id_returns_success_without_duplicate_processing(): void
    {
        $this->configureDirectRunnerToken();
        $csv = $this->sampleCsv();
        $metadata = $this->metadata($csv);

        $this->post('/api/direct-runner/scans', [
            'metadata' => json_encode($metadata),
            'file' => $this->file($csv),
        ], $this->headers('direct-runner-token'))->assertOk();

        $this->post('/api/direct-runner/scans', [
            'metadata' => json_encode($metadata),
            'file' => $this->file($csv),
        ], $this->headers('direct-runner-token'))->assertOk()->assertJson([
            'status' => 'ok',
            'idempotent' => true,
            'reason' => 'duplicate_upload_id',
        ]);

        $this->assertSame(1, DeviceScan::query()->count());
        $this->assertSame(1, RawFile::query()->count());
    }

    public function test_duplicate_file_sha256_returns_success_without_duplicate_processing(): void
    {
        $this->configureDirectRunnerToken();
        $csv = $this->sampleCsv();
        $firstMetadata = $this->metadata($csv, 'direct-upload-1');
        $secondMetadata = $this->metadata($csv, 'direct-upload-2');

        $this->post('/api/direct-runner/scans', [
            'metadata' => json_encode($firstMetadata),
            'file' => $this->file($csv),
        ], $this->headers('direct-runner-token'))->assertOk();

        $this->post('/api/direct-runner/scans', [
            'metadata' => json_encode($secondMetadata),
            'file' => $this->file($csv),
        ], $this->headers('direct-runner-token'))->assertOk()->assertJson([
            'status' => 'ok',
            'idempotent' => true,
            'reason' => 'duplicate_file_sha256',
        ]);

        $this->assertSame(1, DeviceScan::query()->count());
        $this->assertSame(1, RawFile::query()->count());
    }

    private function configureDirectRunnerToken(): void
    {
        Config::set('inventory.site_tokens_json', json_encode([
            'SITE-HQ' => [
                'token' => 'direct-runner-token',
                'token_type' => SiteTokenStore::TYPE_DIRECT_RUNNER,
            ],
        ]));
    }

    private function metadata(string $csv, string $uploadId = 'direct-upload-1'): array
    {
        return [
            'site_code' => 'SITE-HQ',
            'runner_id' => 'PC-DIRECT-01',
            'runner_guid' => '8c2c8c70-1111-4f24-9b10-1f5a3e3d4d21',
            'hostname' => 'PC-DIRECT-01',
            'runner_version' => '1.0.21',
            'transport_mode' => 'direct_https',
            'scan_started_at' => '2026-05-01T08:50:00+09:00',
            'scan_finished_at' => '2026-05-01T08:55:00+09:00',
            'upload_id' => $uploadId,
            'file_sha256' => hash('sha256', $csv),
            'retry_count' => 0,
        ];
    }

    private function sampleCsv(): string
    {
        return (string) file_get_contents(base_path('../../inventaris_py/sample_data/sample_scan_1.csv'));
    }

    private function file(string $csv): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('scan.csv', $csv);
    }

    private function headers(string $token): array
    {
        return [
            'Accept' => 'application/json',
            'X-Site-Id' => 'SITE-HQ',
            'X-Site-Token' => $token,
        ];
    }
}
