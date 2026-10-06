<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class PruneInventory extends Command
{
    private const CHUNK = 500;

    protected $signature = 'inventory:prune
        {--dry-run : Report only (this is the default)}
        {--force : Actually delete rows and raw files}
        {--device-scans-days= : Override retention.device_scans_days}
        {--hardware-snapshots-days= : Override retention.hardware_snapshots_days}
        {--raw-files-days= : Override retention.raw_files_days}
        {--network-observations-days= : Override retention.network_observations_days}
        {--peripherals-days= : Override retention.peripherals_days}
        {--storage-health-days= : Override retention.storage_health_observations_days}
        {--change-log-days= : Override retention.change_log_days}
        {--runner-commands-days= : Override retention.runner_commands_days}
        {--audit-log-days= : Override retention.audit_log_days}';

    protected $description = 'Prune old inventory history (dry-run unless --force). Latest scan per device and open commands are always kept';

    public function handle(): int
    {
        $force = (bool) $this->option('force') && ! $this->option('dry-run');
        $days = [];
        foreach ([
            'device_scans' => 'device-scans',
            'hardware_snapshots' => 'hardware-snapshots',
            'raw_files' => 'raw-files',
            'network_observations' => 'network-observations',
            'peripherals' => 'peripherals',
            'storage_health_observations' => 'storage-health',
            'change_log' => 'change-log',
            'runner_commands' => 'runner-commands',
            'audit_log' => 'audit-log',
        ] as $key => $opt) {
            $value = $this->option("{$opt}-days");
            $value = ($value === null || $value === '') ? config("inventory.retention.{$key}_days") : $value;
            if (! is_numeric($value) || (int) $value < 1) {
                $this->error("Invalid retention days for {$key}; must be an integer >= 1.");

                return self::FAILURE;
            }
            $days[$key] = (int) $value;
        }

        $cutoff = fn (string $key): string => now()->subDays($days[$key])->toDateTimeString();
        $rows = [];

        // Child tables first; scans last so cascades never remove rows still inside their own retention.
        foreach (['network_observations' => 'observed_at', 'peripherals' => 'observed_at', 'storage_health_observations' => 'observed_at'] as $table => $col) {
            $rows[$table] = $this->prune($force, fn () => $this->notLatestScanRows($table, $col, $cutoff($table)));
        }

        $rows['change_log'] = $this->prune($force, fn () => DB::table('change_log')->where('observed_at', '<', $cutoff('change_log')));

        $rows['hardware_snapshots'] = $this->prune($force, fn () => DB::table('hardware_snapshots')
            ->whereExists(fn ($e) => $this->notLatest(
                $e->from('device_scans as s')->selectRaw('1')->whereColumn('s.id', 'hardware_snapshots.device_scan_id')
                    ->where('s.scan_time', '<', $cutoff('hardware_snapshots')),
                's'
            )));

        $rows['device_scans'] = $this->prune($force, fn () => $this->eligibleScans($cutoff('device_scans'), $cutoff('change_log')));

        $rows['raw_files'] = $this->pruneRawFiles($force, $cutoff('raw_files'), $cutoff('device_scans'), $cutoff('change_log'));

        $rows['runner_commands'] = $this->prune($force, fn () => DB::table('runner_commands')
            ->whereNotIn('status', ['pending', 'dispatched'])
            ->where('requested_at', '<', $cutoff('runner_commands')));

        $rows['audit_log'] = $this->prune($force, fn () => DB::table('audit_log')->where('created_at', '<', $cutoff('audit_log')));

        $this->line($force ? 'MODE: FORCE (rows deleted)' : 'MODE: DRY-RUN (nothing deleted; pass --force to delete)');
        $this->table(['Table', $force ? 'Deleted' : 'Would delete'], collect($rows)->map(fn ($n, $t) => [$t, $n])->values()->all());

        return self::SUCCESS;
    }

    /** Rows of $table (keyed by id) older than cutoff whose scan is not the device's latest scan. */
    private function notLatestScanRows(string $table, string $col, string $cutoff): Builder
    {
        return DB::table($table)->where($col, '<', $cutoff)
            ->whereExists(fn ($e) => $this->notLatest(
                $e->from('device_scans as s')->selectRaw('1')->whereColumn('s.id', "{$table}.device_scan_id"),
                's'
            ));
    }

    /** Constrain $q (device_scans aliased $a) to scans that have a newer scan for the same device. */
    private function notLatest($q, string $a)
    {
        return $q->whereExists(fn ($n) => $n->from('device_scans as n')->selectRaw('1')
            ->whereColumn('n.device_id', "{$a}.device_id")
            ->where(fn ($w) => $w->whereColumn('n.scan_time', '>', "{$a}.scan_time")
                ->orWhere(fn ($t) => $t->whereColumn('n.scan_time', "{$a}.scan_time")->whereColumn('n.id', '>', "{$a}.id"))));
    }

    /**
     * Old, non-latest scans. Scans still referenced by in-retention change_log rows or by
     * device_assignments are kept, because deleting them would cascade-delete that history.
     */
    private function eligibleScans(string $cutoff, string $changeLogCutoff): Builder
    {
        $q = DB::table('device_scans as s')->where('s.scan_time', '<', $cutoff);
        $this->notLatest($q, 's');

        return $q
            ->whereNotExists(fn ($e) => $e->from('change_log as c')->selectRaw('1')
                ->whereColumn('c.device_scan_id', 's.id')->where('c.observed_at', '>=', $changeLogCutoff))
            ->whereNotExists(fn ($e) => $e->from('device_assignments as a')->selectRaw('1')
                ->where(fn ($w) => $w->whereColumn('a.start_scan_id', 's.id')->orWhereColumn('a.end_scan_id', 's.id')));
    }

    /** Count (dry-run) or delete in chunks the rows the query selects. Query must expose an id column. */
    private function prune(bool $force, \Closure $query): int
    {
        if (! $force) {
            return $query()->count();
        }

        $total = 0;
        do {
            $builder = $query();
            $alias = (string) $builder->from;
            $idColumn = str_contains($alias, ' as ') ? explode(' as ', $alias)[1] . '.id' : 'id';
            $ids = $builder->orderBy($idColumn)->limit(self::CHUNK)->pluck($idColumn)->all();
            if ($ids === []) {
                break;
            }
            $total += DB::table(explode(' as ', $alias)[0])->whereIn('id', $ids)->delete();
        } while (count($ids) === self::CHUNK);

        return $total;
    }

    private function pruneRawFiles(bool $force, string $cutoff, string $scanCutoff, string $changeLogCutoff): int
    {
        $query = fn () => DB::table('raw_files')->where('received_at', '<', $cutoff)
            ->whereNotExists(fn ($e) => $e->from('device_scans as k')->selectRaw('1')
                ->whereColumn('k.raw_hash', 'raw_files.raw_hash')
                ->whereNotIn('k.id', $this->eligibleScans($scanCutoff, $changeLogCutoff)->select('s.id')));

        if (! $force) {
            return $query()->count();
        }

        $total = 0;
        $lastId = 0;
        do {
            $batch = $query()->where('id', '>', $lastId)->orderBy('id')->limit(self::CHUNK)->get(['id', 'saved_path']);
            $deletable = [];
            foreach ($batch as $row) {
                $lastId = $row->id;
                if ($this->removeArchiveFile((string) $row->saved_path)) {
                    $deletable[] = $row->id;
                }
            }
            if ($deletable !== []) {
                $total += DB::table('raw_files')->whereIn('id', $deletable)->delete();
            }
        } while ($batch->count() === self::CHUNK);

        return $total;
    }

    /** Delete the archived file; true when it is gone (or never existed), false when it must be kept. */
    private function removeArchiveFile(string $path): bool
    {
        $root = trim((string) config('inventory.raw_archive_path'), '/');
        $norm = str_replace('\\', '/', $path);

        if ($norm === '' || $root === '' || str_contains($norm, '..') || str_starts_with($norm, '/') || ! str_starts_with($norm, $root . '/')) {
            $this->warn('Skipped raw file outside archive dir; row kept.');

            return false;
        }

        $disk = Storage::disk((string) config('inventory.raw_archive_disk', 'local'));
        try {
            return ! $disk->exists($norm) || $disk->delete($norm);
        } catch (\Throwable) {
            return false;
        }
    }
}
