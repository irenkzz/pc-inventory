<?php

namespace Tests\Feature;

use App\Models\Collector;
use App\Models\CollectorSite;
use App\Models\Device;
use App\Models\DeviceScan;
use App\Models\RawFile;
use App\Models\Runner;
use App\Models\RunnerCommand;
use App\Models\StorageHealthObservation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

class PilotStatusCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_pilot_status_reports_operational_readiness_as_json(): void
    {
        $this->travelTo('2026-04-30 12:00:00');

        CollectorSite::query()->create([
            'site_id' => 'SITE-HQ',
            'site_name' => 'HQ',
        ]);

        Collector::query()->create([
            'site_id' => 'SITE-HQ',
            'collector_name' => 'HQ-COLLECTOR',
            'last_seen_at' => now()->subMinutes(5),
            'last_status' => 'ok',
        ]);

        Runner::query()->create([
            'runner_id' => 'PC-001',
            'hostname' => 'PC-001',
            'site_id' => 'SITE-HQ',
            'runner_version' => '1.0.15',
            'last_seen_at' => now()->subMinutes(10),
            'last_successful_inventory_at' => now()->subMinutes(10),
        ]);

        Runner::query()->create([
            'runner_id' => 'PC-OLD',
            'hostname' => 'PC-OLD',
            'site_id' => 'SITE-HQ',
            'runner_version' => '1.0.14',
            'last_seen_at' => now()->subDays(3),
        ]);

        RunnerCommand::query()->create([
            'runner_id' => 'PC-OLD',
            'site_id' => 'SITE-HQ',
            'command_type' => 'repair_update',
            'status' => 'pending',
            'requested_at' => now()->subHours(2),
        ]);

        RawFile::query()->create([
            'raw_hash' => str_repeat('a', 64),
            'original_filename' => 'pc-001.csv',
            'saved_path' => 'inventory/raw_archive/pc-001.csv',
            'raw_format' => 'csv',
            'received_at' => now()->subMinutes(10),
        ]);

        $device = Device::query()->create([
            'device_uid' => 'device-pc-001',
            'first_seen_at' => now()->subMinutes(10),
            'last_seen_at' => now()->subMinutes(10),
        ]);

        $scan = DeviceScan::query()->create([
            'device_id' => $device->id,
            'scan_time' => now()->subMinutes(10),
            'scan_source' => 'test',
            'raw_hash' => str_repeat('a', 64),
            'raw_filename' => 'pc-001.csv',
            'raw_format' => 'csv',
            'ingested_at' => now()->subMinutes(10),
        ]);

        StorageHealthObservation::query()->create([
            'device_id' => $device->id,
            'device_scan_id' => $scan->id,
            'disk_key' => 'disk-0',
            'risk_level' => 'unknown',
            'risk_score' => 0,
            'observed_at' => now()->subMinutes(10),
        ]);

        $this->withoutMockingConsoleOutput();

        $buffer = new BufferedOutput();
        $exitCode = Artisan::call('inventory:pilot-status', ['--json' => true, '--command-stale-minutes' => 30], $buffer);
        $output = $buffer->fetch();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('"status": "ok"', $output);
        $this->assertStringContainsString('"runners_below_target_version": 2', $output);
        $this->assertStringContainsString('"commands_stuck": 1', $output);
        $this->assertStringContainsString('"1.0.15": 1', $output);
        $this->assertStringContainsString('"Unknown storage telemetry"', $output);
    }

    public function test_pilot_status_fails_without_recent_collector_runner_or_intake(): void
    {
        $this->withoutMockingConsoleOutput();

        $buffer = new BufferedOutput();
        $exitCode = Artisan::call('inventory:pilot-status', ['--json' => true], $buffer);
        $output = $buffer->fetch();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('"status": "fail"', $output);
        $this->assertStringContainsString('"Recent collector heartbeat"', $output);
        $this->assertStringContainsString('"Recent runner heartbeat"', $output);
        $this->assertStringContainsString('"Latest raw intake"', $output);
    }
}
