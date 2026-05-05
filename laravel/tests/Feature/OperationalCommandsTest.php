<?php

namespace Tests\Feature;

use App\Models\Collector;
use App\Models\Runner;
use App\Models\RunnerCommand;
use App\Services\Inventory\InventoryIngestService;
use App\Services\Runner\CommandQueueService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use PDO;
use Tests\TestCase;

class OperationalCommandsTest extends TestCase
{
    use RefreshDatabase;

    public function test_simulate_collector_cycle_creates_status_runner_intake_and_ack(): void
    {
        Storage::fake('local');

        $this->artisan('inventory:simulate-collector', [
            'csv' => $this->fixturePath('sample_data/sample_scan_1.csv'),
            '--queue-scan' => true,
            '--ack' => true,
        ])->assertSuccessful();

        $this->assertSame(1, Collector::query()->count());
        $this->assertSame(1, Runner::query()->count());
        $this->assertDatabaseHas('runner_commands', [
            'command_type' => 'scan_now',
            'status' => 'completed',
            'completion_message' => 'Simulated command acknowledgement.',
        ]);
        $this->assertSame('completed', RunnerCommand::query()->firstOrFail()->status);
    }

    public function test_new_command_supersedes_active_command_for_runner(): void
    {
        config(['app.timezone' => 'Asia/Tokyo']);
        $this->travelTo('2026-04-22 16:30:00');

        Runner::query()->create([
            'runner_id' => 'IT-ADMIN',
            'hostname' => 'IT-ADMIN',
            'runner_version' => '1.0.0',
        ]);

        $commands = app(CommandQueueService::class);

        $first = $commands->queue('IT-ADMIN', null, 'repair_update', 'test');
        $second = $commands->queue('IT-ADMIN', null, 'scan_now', 'test');

        $this->assertSame('superseded', $first->refresh()->status);
        $this->assertSame('pending', $second->refresh()->status);
        $this->assertSame('scan_now', $second->command_type);
        $this->assertDatabaseHas('runner_commands', [
            'id' => $second->id,
            'requested_at' => '2026-04-22 16:30:00',
        ]);
        $this->assertSame(1, RunnerCommand::query()->whereIn('status', ['pending', 'dispatched'])->count());
        $this->assertSame('superseded', $first->completion_status);
    }

    public function test_rename_runner_merges_old_hostname_runner_into_current_runner(): void
    {
        Runner::query()->create([
            'runner_id' => 'DESKTOP-OLD',
            'hostname' => 'DESKTOP-OLD',
            'site_id' => null,
            'runner_version' => '1.0.18',
        ]);
        Runner::query()->create([
            'runner_id' => 'MCR-NEW',
            'hostname' => 'MCR-NEW',
            'site_id' => null,
            'runner_version' => '1.0.20',
            'raw_state_json' => ['runner_id' => 'MCR-NEW'],
        ]);
        RunnerCommand::query()->create([
            'runner_id' => 'DESKTOP-OLD',
            'command_type' => 'scan_now',
            'status' => 'completed',
            'requested_at' => now(),
        ]);

        $this->artisan('inventory:rename-runner', [
            'old_runner_id' => 'desktop-old',
            'new_runner_id' => 'mcr-new',
        ])->assertSuccessful();

        $this->assertDatabaseMissing('runners', ['runner_id' => 'DESKTOP-OLD']);
        $this->assertDatabaseHas('runners', [
            'runner_id' => 'MCR-NEW',
            'hostname' => 'MCR-NEW',
            'runner_version' => '1.0.20',
        ]);
        $this->assertDatabaseHas('runner_commands', [
            'runner_id' => 'MCR-NEW',
            'command_type' => 'scan_now',
        ]);
        $this->assertContains('DESKTOP-OLD', Runner::query()->where('runner_id', 'MCR-NEW')->firstOrFail()->raw_state_json['previous_runner_ids']);
    }

    public function test_rename_runner_renames_when_target_does_not_exist(): void
    {
        Runner::query()->create([
            'runner_id' => 'DESKTOP-OLD',
            'hostname' => 'DESKTOP-OLD',
            'runner_version' => '1.0.18',
            'raw_state_json' => ['runner_id' => 'DESKTOP-OLD'],
        ]);
        RunnerCommand::query()->create([
            'runner_id' => 'DESKTOP-OLD',
            'command_type' => 'repair_update',
            'status' => 'completed',
            'requested_at' => now(),
        ]);

        $this->artisan('inventory:rename-runner', [
            'old_runner_id' => 'desktop-old',
            'new_runner_id' => 'mcr-new',
        ])->assertSuccessful();

        $this->assertDatabaseMissing('runners', ['runner_id' => 'DESKTOP-OLD']);
        $this->assertDatabaseHas('runners', [
            'runner_id' => 'MCR-NEW',
            'hostname' => 'MCR-NEW',
        ]);
        $this->assertDatabaseHas('runner_commands', [
            'runner_id' => 'MCR-NEW',
            'command_type' => 'repair_update',
        ]);
    }

    public function test_compare_legacy_command_outputs_count_summary_when_db_exists(): void
    {
        Storage::fake('local');

        app(InventoryIngestService::class)->ingestCsvText(
            (string) $this->fixtureContents('sample_data/sample_scan_1.csv'),
            'sample_scan_1.csv',
            'test_import',
        );

        $this->artisan('inventory:compare-legacy', [
            'legacy_db' => $this->createLegacyDatabaseFixture(),
            '--json' => true,
        ])
            ->expectsOutputToContain('"legacy_table": "devices"')
            ->assertSuccessful();
    }

    private function createLegacyDatabaseFixture(): string
    {
        $path = storage_path('framework/testing/legacy_inventory.db');
        File::ensureDirectoryExists(dirname($path));
        File::delete($path);

        $pdo = new PDO('sqlite:' . $path);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $pdo->exec('create table devices (id integer primary key autoincrement, current_asset_code text, current_user_name text, current_site text, last_seen_at text)');
        $pdo->exec('insert into devices (current_asset_code, current_user_name, current_site, last_seen_at) values ("LEGACY-001", "legacy-user", "Legacy Site", "2026-04-01 10:00:00")');

        foreach ([
            'device_identities',
            'scans',
            'snapshots',
            'assignment_history',
            'change_log',
            'raw_files',
            'collector_sites',
            'collectors',
            'runners',
            'runner_commands',
        ] as $table) {
            $pdo->exec("create table {$table} (id integer primary key autoincrement)");
        }

        return $path;
    }
}
