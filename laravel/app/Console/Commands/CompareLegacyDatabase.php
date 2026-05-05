<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use PDO;

class CompareLegacyDatabase extends Command
{
    protected $signature = 'inventory:compare-legacy
        {legacy_db=../../inventaris_py/data/inventory.db : Path to the legacy Flask SQLite database}
        {--json : Output JSON instead of tables}';

    protected $description = 'Compare high-level inventory counts between the legacy Flask SQLite DB and Laravel DB';

    private const TABLE_MAP = [
        'devices' => 'devices',
        'device_identities' => 'device_identities',
        'scans' => 'device_scans',
        'snapshots' => 'hardware_snapshots',
        'assignment_history' => 'device_assignments',
        'change_log' => 'change_log',
        'raw_files' => 'raw_files',
        'collector_sites' => 'collector_sites',
        'collectors' => 'collectors',
        'runners' => 'runners',
        'runner_commands' => 'runner_commands',
    ];

    public function handle(): int
    {
        $legacyPath = $this->resolvePath((string) $this->argument('legacy_db'));
        if (! is_file($legacyPath)) {
            $this->error("Legacy database not found: {$legacyPath}");
            return self::FAILURE;
        }

        $legacy = new PDO('sqlite:' . $legacyPath);
        $legacy->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $rows = [];
        foreach (self::TABLE_MAP as $legacyTable => $laravelTable) {
            $legacyCount = $this->countTable($legacy, $legacyTable);
            $laravelCount = DB::table($laravelTable)->count();
            $rows[] = [
                'legacy_table' => $legacyTable,
                'laravel_table' => $laravelTable,
                'legacy_count' => $legacyCount,
                'laravel_count' => $laravelCount,
                'delta' => $laravelCount - $legacyCount,
            ];
        }

        $summary = [
            'legacy_db' => $legacyPath,
            'counts' => $rows,
            'legacy_recent_devices' => $this->recentLegacyDevices($legacy),
            'laravel_recent_devices' => $this->recentLaravelDevices(),
        ];

        if ($this->option('json')) {
            $this->line(json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            return self::SUCCESS;
        }

        $this->line("Legacy DB: {$legacyPath}");
        $this->table(['Legacy table', 'Laravel table', 'Legacy', 'Laravel', 'Delta'], array_map(
            fn (array $row): array => [$row['legacy_table'], $row['laravel_table'], $row['legacy_count'], $row['laravel_count'], $row['delta']],
            $rows,
        ));

        $this->line('Recent legacy devices:');
        $this->table(['Asset', 'User', 'Site', 'Last seen'], $summary['legacy_recent_devices']);
        $this->line('Recent Laravel devices:');
        $this->table(['Asset', 'User', 'Site', 'Last seen'], $summary['laravel_recent_devices']);

        return self::SUCCESS;
    }

    private function resolvePath(string $path): string
    {
        $resolved = realpath($path);
        if ($resolved !== false) {
            return $resolved;
        }

        $resolved = realpath(base_path($path));
        if ($resolved !== false) {
            return $resolved;
        }

        return base_path($path);
    }

    private function countTable(PDO $pdo, string $table): int
    {
        $statement = $pdo->query("select count(*) from {$table}");

        return (int) $statement->fetchColumn();
    }

    private function recentLegacyDevices(PDO $pdo): array
    {
        $statement = $pdo->query(
            'select current_asset_code, current_user_name, current_site, last_seen_at from devices order by last_seen_at desc, id desc limit 5',
        );

        return array_map(
            fn (array $row): array => [$row['current_asset_code'], $row['current_user_name'], $row['current_site'], $row['last_seen_at']],
            $statement->fetchAll(PDO::FETCH_ASSOC),
        );
    }

    private function recentLaravelDevices(): array
    {
        return DB::table('devices')
            ->select('current_asset_code', 'current_user_name', 'current_site', 'last_seen_at')
            ->orderByDesc('last_seen_at')
            ->orderByDesc('id')
            ->limit(5)
            ->get()
            ->map(fn ($row): array => [$row->current_asset_code, $row->current_user_name, $row->current_site, $row->last_seen_at])
            ->all();
    }
}
