<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PruneInventoryCommandTest extends TestCase
{
    use RefreshDatabase;

    private function scan(int $deviceId, string $hash, int $daysAgo, bool $withRaw = true): int
    {
        $when = now()->subDays($daysAgo)->toDateTimeString();
        $id = DB::table('device_scans')->insertGetId([
            'device_id' => $deviceId, 'scan_time' => $when, 'scan_source' => 't',
            'raw_hash' => $hash, 'raw_format' => 'csv', 'created_at' => $when, 'updated_at' => $when,
        ]);
        DB::table('network_observations')->insert([
            'device_id' => $deviceId, 'device_scan_id' => $id, 'observed_at' => $when, 'created_at' => $when, 'updated_at' => $when,
        ]);
        if ($withRaw) {
            Storage::disk('local')->put("inventory/raw_archive/{$hash}.csv", 'x');
            DB::table('raw_files')->insert([
                'raw_hash' => $hash, 'saved_path' => "inventory/raw_archive/{$hash}.csv", 'raw_format' => 'csv',
                'received_at' => $when, 'created_at' => $when, 'updated_at' => $when,
            ]);
        }

        return $id;
    }

    private function seedData(): array
    {
        $now = now()->toDateTimeString();
        $dev = DB::table('devices')->insertGetId(['device_uid' => 'd1', 'first_seen_at' => $now, 'last_seen_at' => $now, 'created_at' => $now, 'updated_at' => $now]);
        $dev2 = DB::table('devices')->insertGetId(['device_uid' => 'd2', 'first_seen_at' => $now, 'last_seen_at' => $now, 'created_at' => $now, 'updated_at' => $now]);

        return [
            'old' => $this->scan($dev, str_repeat('a', 64), 500),
            'latest' => $this->scan($dev, str_repeat('b', 64), 400),     // old but latest for d1
            'lonely' => $this->scan($dev2, str_repeat('c', 64), 900),    // only scan of d2, very old
            'fresh' => $this->scan($dev2, str_repeat('d', 64), 1),
        ];
    }

    public function test_default_is_dry_run_and_deletes_nothing(): void
    {
        $this->seedData();
        $this->artisan('inventory:prune')->expectsOutputToContain('DRY-RUN')->assertSuccessful();

        $this->assertSame(4, DB::table('device_scans')->count());
        $this->assertSame(4, DB::table('raw_files')->count());
        Storage::disk('local')->assertExists('inventory/raw_archive/' . str_repeat('a', 64) . '.csv');

        $this->artisan('inventory:prune', ['--force' => true, '--dry-run' => true])->assertSuccessful();
        $this->assertSame(4, DB::table('device_scans')->count());
    }

    public function test_force_deletes_only_old_non_latest_and_removes_unreferenced_raw_files(): void
    {
        $ids = $this->seedData();
        $this->artisan('inventory:prune', ['--force' => true])->expectsOutputToContain('FORCE')->assertSuccessful();

        $this->assertEqualsCanonicalizing(
            [$ids['latest'], $ids['fresh']],
            DB::table('device_scans')->pluck('id')->all(),
        );
        $this->assertSame(2, DB::table('network_observations')->count());
        $this->assertSame(2, DB::table('raw_files')->count());
        Storage::disk('local')->assertMissing('inventory/raw_archive/' . str_repeat('a', 64) . '.csv');
        Storage::disk('local')->assertExists('inventory/raw_archive/' . str_repeat('b', 64) . '.csv');
    }

    public function test_orphan_raw_file_is_removed_but_traversal_path_is_not_followed(): void
    {
        $now = now()->subDays(999)->toDateTimeString();
        Storage::disk('local')->put('inventory/outside.txt', 'keep');
        foreach (['inventory/raw_archive/../outside.txt' => 'e', 'inventory/raw_archive/orphan.csv' => 'f'] as $path => $c) {
            DB::table('raw_files')->insert(['raw_hash' => str_repeat($c, 64), 'saved_path' => $path, 'raw_format' => 'csv', 'received_at' => $now, 'created_at' => $now, 'updated_at' => $now]);
        }
        Storage::disk('local')->put('inventory/raw_archive/orphan.csv', 'x');

        $this->artisan('inventory:prune', ['--force' => true])->assertSuccessful();

        Storage::disk('local')->assertMissing('inventory/raw_archive/orphan.csv');
        Storage::disk('local')->assertExists('inventory/outside.txt');
        $this->assertSame(1, DB::table('raw_files')->count());
    }

    public function test_open_commands_are_kept_and_terminal_old_commands_deleted(): void
    {
        $now = now()->toDateTimeString();
        $old = now()->subDays(400)->toDateTimeString();
        DB::table('runners')->insert(['runner_id' => 'r1', 'created_at' => $now, 'updated_at' => $now]);
        foreach (['pending', 'dispatched', 'completed', 'failed'] as $status) {
            DB::table('runner_commands')->insert(['runner_id' => 'r1', 'command_type' => 'scan_now', 'status' => $status, 'requested_at' => $old, 'created_at' => $old, 'updated_at' => $old]);
        }

        $this->artisan('inventory:prune', ['--force' => true])->assertSuccessful();

        $this->assertEqualsCanonicalizing(['pending', 'dispatched'], DB::table('runner_commands')->pluck('status')->all());
    }

    public function test_config_defaults(): void
    {
        $this->assertFalse((bool) config('inventory.retention.schedule_enabled'));
        $this->assertFalse((bool) config('inventory.schedule_doctor'));
        $this->assertSame(365, (int) config('inventory.retention.device_scans_days'));
        $this->assertSame('mysqldump', config('inventory.mysqldump_path'));
    }
}
