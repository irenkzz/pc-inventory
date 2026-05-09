<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PDO;
use Throwable;

class MariaDbRehearsalTransfer extends Command
{
    private const EXPECTED_BASE_PATH = 'D:\\inventory-rehearsal\\laravel';
    private const LIVE_LARAVEL_PATH = 'D:\\inventory\\laravel';
    private const LIVE_SQLITE_PATH = 'D:\\inventory\\laravel\\database\\database.sqlite';
    private const EXPECTED_SOURCE_PATH = 'D:\\inventory-rehearsal\\source-copy\\database.sqlite';
    private const TARGET_DATABASE = 'inventory_rehearsal';
    private const RESTORE_DATABASE = 'inventory_rehearsal_restore';

    protected $signature = 'inventory:mariadb-rehearsal-transfer
        {--source= : Explicit copied SQLite source path}
        {--dry-run : Required; this command has no execute mode}';

    protected $description = 'Dry-run-only app-aware SQLite-to-MariaDB rehearsal transfer planner';

    private bool $hasFail = false;

    private bool $hasWarn = false;

    /** @var array<string, bool> */
    private array $sourceTables = [];

    /** @var array<string, array<int, string>> */
    private array $sourceColumns = [];

    /** @var array<string, bool> */
    private array $targetTables = [];

    private ?PDO $source = null;

    /** @var list<string> */
    private array $requiredTables = [
        'users',
        'devices',
        'device_identities',
        'device_scans',
        'hardware_snapshots',
        'storage_health_observations',
        'network_observations',
        'peripherals',
        'device_assignments',
        'change_log',
        'raw_files',
        'collector_sites',
        'collectors',
        'runners',
        'runner_commands',
    ];

    /** @var list<string> */
    private array $optionalTables = [
        'site_tokens',
        'classification_rules',
        'department_cleanup_rules',
        'site_cleanup_rules',
        'personal_access_tokens',
        'password_reset_tokens',
        'failed_jobs',
    ];

    /** @var list<string> */
    private array $targetMigratedTables = [
        'migrations',
        'users',
        'devices',
        'device_identities',
        'device_scans',
        'hardware_snapshots',
        'storage_health_observations',
        'network_observations',
        'peripherals',
        'device_assignments',
        'change_log',
        'raw_files',
        'collector_sites',
        'collectors',
        'runners',
        'runner_commands',
    ];

    public function handle(): int
    {
        $this->line('MariaDB rehearsal transfer dry-run');
        $this->infoLine('Dry-run only. No execute mode exists. No data is written.');
        $this->infoLine('Database is authoritative. Raw CSV files are archived evidence only.');

        $sourcePath = $this->option('source');

        $this->environmentBoundarySection((string) $sourcePath);
        if (! $this->hasFail) {
            $this->sourceSqliteValidationSection((string) $sourcePath);
        }
        if (! $this->hasFail) {
            $this->targetMariaDbValidationSection();
            $this->restoreDbIsolationSection();
        }
        if (! $this->hasFail) {
            $this->plannedTransferOrderSection();
            $this->sourceTableAvailabilitySection();
            $this->targetTableAvailabilitySection();
            $this->rowCountPreviewSection();
            $this->relationshipPreviewSection();
            $this->jsonTextDecodePreviewSection();
            $this->commandLifecycleSummarySection();
            $this->tokenMetadataSummarySection();
            $this->rawEvidenceReferenceSummarySection();
            $this->assignmentOverrideSummarySection();
            $this->futureResetPlanSection();
            $this->futureExecutePrerequisitesSection();
            $this->stopConditionsSection();
        }

        $this->resultSection();

        return $this->hasFail ? self::FAILURE : self::SUCCESS;
    }

    private function environmentBoundarySection(string $sourcePath): void
    {
        $this->section('Environment boundary');

        if (! (bool) $this->option('dry-run')) {
            $this->fail('--dry-run is required. This command is dry-run-only.');
        } else {
            $this->ok('--dry-run supplied');
        }

        if (trim($sourcePath) === '') {
            $this->fail('--source is required and must be explicit.');
        } else {
            $this->ok('--source supplied');
        }

        if ($this->getDefinition()->hasOption('execute')) {
            $this->fail('--execute option must not exist on this command.');
        } else {
            $this->ok('--execute option absent');
        }

        $basePath = $this->normalizePath(base_path());
        if ($basePath !== self::EXPECTED_BASE_PATH) {
            $this->fail('Current Laravel base path is not the rehearsal path.');
        } else {
            $this->ok('Laravel base path is the rehearsal path.');
        }

        $normalizedSource = $this->normalizePath($sourcePath);
        if ($normalizedSource === self::LIVE_SQLITE_PATH) {
            $this->fail('Source path is the live SQLite database and is refused.');
        }
        if ($normalizedSource !== '' && $this->pathIsInside($normalizedSource, self::LIVE_LARAVEL_PATH)) {
            $this->fail('Source path is under the live Laravel path and is refused.');
        }
        if ($normalizedSource !== '' && $normalizedSource !== self::EXPECTED_SOURCE_PATH) {
            $this->fail('Source path must be the copied rehearsal SQLite source.');
        }

        $defaultConnection = (string) config('database.default');
        $databaseName = (string) config("database.connections.{$defaultConnection}.database");
        $appUrl = strtolower((string) config('app.url'));

        $defaultConnection === 'mysql'
            ? $this->ok('DB_CONNECTION is mysql')
            : $this->fail('DB_CONNECTION is not mysql');

        $databaseName === self::TARGET_DATABASE
            ? $this->ok('DB_DATABASE is inventory_rehearsal')
            : $this->fail('DB_DATABASE is not inventory_rehearsal');

        str_contains($appUrl, 'inventory-pilot.internal.lan')
            ? $this->fail('APP_URL contains inventory-pilot.internal.lan')
            : $this->ok('APP_URL does not contain live pilot hostname');
    }

    private function sourceSqliteValidationSection(string $sourcePath): void
    {
        $this->section('Source SQLite validation');

        if (! is_file($sourcePath)) {
            $this->fail('Source SQLite file does not exist.');
            return;
        }

        $size = filesize($sourcePath);
        if ($size === false || $size <= 0) {
            $this->fail('Source SQLite file is zero bytes or unreadable.');
            return;
        }
        $this->ok('Source SQLite file exists and is non-zero.');

        if (! is_readable($sourcePath)) {
            $this->fail('Source SQLite file is not readable.');
            return;
        }
        $this->ok('Source SQLite file is readable.');

        try {
            $this->source = new PDO('sqlite:' . $sourcePath, null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
            $this->ok('Source SQLite opened for read-only dry-run inspection.');

            $integrity = $this->source->query('PRAGMA integrity_check')->fetchColumn();
            $integrity === 'ok'
                ? $this->ok('Source SQLite integrity/readability check passed.')
                : $this->fail('Source SQLite integrity/readability check failed.');

            $tables = $this->source->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name")
                ->fetchAll(PDO::FETCH_COLUMN);
            foreach ($tables as $table) {
                $table = (string) $table;
                $this->sourceTables[$table] = true;
                $this->sourceColumns[$table] = $this->sourceColumnNames($table);
            }
            $this->infoLine('Source table count: ' . count($this->sourceTables));
        } catch (Throwable) {
            $this->fail('Source SQLite cannot be opened or inspected safely.');
        }
    }

    private function targetMariaDbValidationSection(): void
    {
        $this->section('Target MariaDB validation');

        try {
            DB::connection()->select('select 1');
            $this->ok('MariaDB target reachable.');
        } catch (Throwable) {
            $this->fail('MariaDB target cannot be reached.');
            return;
        }

        foreach ($this->targetMigratedTables as $table) {
            if (Schema::hasTable($table)) {
                $this->targetTables[$table] = true;
                continue;
            }

            $this->fail("Required migrated target table missing: {$table}");
        }

        if (! $this->hasFail) {
            $this->ok('Required migrated target schema is present.');
        }

        try {
            $ran = DB::table('migrations')->count();
            $this->infoLine('Target migrations row count: ' . $ran);
        } catch (Throwable) {
            $this->fail('Migrations table could not be inspected.');
        }
    }

    private function restoreDbIsolationSection(): void
    {
        $this->section('Restore DB isolation check');

        try {
            $connection = DB::connection();
            $driver = $connection->getDriverName();
            $restoreExists = true;
            $tableCount = 0;

            if ($driver === 'mysql') {
                $restoreExists = (int) $connection->table('information_schema.SCHEMATA')
                    ->where('SCHEMA_NAME', self::RESTORE_DATABASE)
                    ->count() === 1;
                $tableCount = (int) $connection->table('information_schema.TABLES')
                    ->where('TABLE_SCHEMA', self::RESTORE_DATABASE)
                    ->count();
            }

            if (! $restoreExists) {
                $this->fail('inventory_rehearsal_restore is missing.');
                return;
            }

            $tableCount === 0
                ? $this->ok('inventory_rehearsal_restore exists and is empty.')
                : $this->fail('inventory_rehearsal_restore is not empty.');
        } catch (Throwable) {
            $this->fail('inventory_rehearsal_restore isolation check failed.');
        }
    }

    private function plannedTransferOrderSection(): void
    {
        $this->section('Planned transfer order');
        foreach ([
            'users',
            'collector_sites',
            'site token / token metadata table if present',
            'devices',
            'device_identities',
            'device_scans',
            'hardware_snapshots',
            'storage_health_observations',
            'network_observations',
            'peripherals',
            'device_assignments',
            'raw_files',
            'change_log',
            'collectors',
            'runners',
            'runner_commands',
            'classification rules table if present',
            'department/site cleanup rules table if present',
            'optional personal_access_tokens if used and safe',
            'optional password_reset_tokens / failed_jobs only if needed',
        ] as $index => $item) {
            $this->infoLine(($index + 1) . '. ' . $item);
        }

        $this->infoLine('Do not transfer migrations from SQLite. MariaDB owns migrations state from Phase 19G.');
    }

    private function sourceTableAvailabilitySection(): void
    {
        $this->section('Source table availability');
        foreach ($this->requiredTables as $table) {
            $this->sourceHasTable($table)
                ? $this->ok("Source table present: {$table}")
                : $this->warnLine("Source table missing: {$table}");
        }
        foreach ($this->optionalTables as $table) {
            $this->sourceHasTable($table)
                ? $this->infoLine("Optional source table present: {$table}")
                : $this->warnLine("Optional source table absent: {$table}");
        }
    }

    private function targetTableAvailabilitySection(): void
    {
        $this->section('Target table availability');
        foreach (array_merge($this->requiredTables, $this->optionalTables) as $table) {
            if (Schema::hasTable($table)) {
                $this->infoLine("Target table present: {$table}");
            } elseif (in_array($table, $this->requiredTables, true)) {
                $this->fail("Required target table missing: {$table}");
            } else {
                $this->warnLine("Optional target table absent: {$table}");
            }
        }
    }

    private function rowCountPreviewSection(): void
    {
        $this->section('Row-count preview');
        foreach (array_merge($this->requiredTables, $this->optionalTables) as $table) {
            $sourceCount = $this->sourceHasTable($table) ? $this->sourceCount($table) : null;
            $targetCount = Schema::hasTable($table) ? $this->targetCount($table) : null;
            if ($targetCount !== null && $targetCount > 0 && $table !== 'users') {
                $this->warnLine("Target current row count non-zero before future transfer for {$table}: {$targetCount}");
            }

            $this->infoLine("table={$table} source_count=" . $this->displayCount($sourceCount) . ' target_current_count=' . $this->displayCount($targetCount));
        }

        foreach ($this->subsetCountLines() as $line) {
            $this->infoLine($line);
        }
    }

    private function relationshipPreviewSection(): void
    {
        $this->section('Relationship preview');
        foreach ([
            ['device_identities', 'device_id', 'devices', 'id'],
            ['device_scans', 'device_id', 'devices', 'id'],
            ['hardware_snapshots', 'device_scan_id', 'device_scans', 'id'],
            ['network_observations', 'device_scan_id', 'device_scans', 'id'],
            ['peripherals', 'device_scan_id', 'device_scans', 'id'],
            ['device_assignments', 'device_id', 'devices', 'id'],
            ['change_log', 'device_id', 'devices', 'id'],
            ['collectors', 'site_id', 'collector_sites', 'site_id'],
            ['runners', 'site_id', 'collector_sites', 'site_id'],
            ['runner_commands', 'runner_id', 'runners', 'runner_id'],
        ] as [$child, $childColumn, $parent, $parentColumn]) {
            $this->relationshipCheck($child, $childColumn, $parent, $parentColumn);
        }

        $this->relationshipCheck('storage_health_observations', 'device_id', 'devices', 'id');
        $this->relationshipCheck('storage_health_observations', 'device_scan_id', 'device_scans', 'id');
        $this->relationshipCheck('raw_files', 'device_scan_id', 'device_scans', 'id', optional: true);
        $this->relationshipCheck('raw_files', 'device_id', 'devices', 'id', optional: true);
        $this->latestScanPreview();
        $this->latestHardwareSnapshotPreview();
    }

    private function jsonTextDecodePreviewSection(): void
    {
        $this->section('JSON/text decode preview');
        foreach ([
            ['hardware_snapshots', 'snapshot_json'],
            ['raw_files', 'metadata_json'],
            ['collectors', 'raw_status_json'],
            ['runners', 'raw_state_json'],
            ['runner_commands', 'payload_json'],
            ['storage_health_observations', 'raw_json'],
            ['storage_health_observations', 'risk_reasons'],
        ] as [$table, $column]) {
            $this->jsonDecodeCheck($table, $column);
        }
    }

    private function commandLifecycleSummarySection(): void
    {
        $this->section('Command lifecycle summary');
        if (! $this->sourceHasTable('runner_commands')) {
            $this->warnLine('runner_commands table missing; command lifecycle summary skipped.');
            return;
        }

        foreach (['queued', 'pending', 'dispatched', 'acknowledged', 'completed', 'succeeded', 'failed'] as $status) {
            $this->infoLine("command_status_{$status}_count=" . $this->sourceCountWhere('runner_commands', 'status', $status));
        }
        $this->infoLine('result_upload_id_populated_count=' . $this->sourceNotNullCount('runner_commands', 'result_upload_id'));
        $this->infoLine('payload_present_count=' . $this->sourceNotNullCount('runner_commands', 'payload_json'));
        $this->jsonDecodeCheck('runner_commands', 'payload_json', label: 'payload_json');
    }

    private function tokenMetadataSummarySection(): void
    {
        $this->section('Token metadata summary without secrets');
        $tokenTables = array_values(array_filter(['site_tokens', 'personal_access_tokens'], fn (string $table): bool => $this->sourceHasTable($table)));
        if ($tokenTables === []) {
            $this->warnLine('No token metadata table found in source.');
            return;
        }

        foreach ($tokenTables as $table) {
            $this->infoLine("token_metadata_table={$table} count=" . $this->sourceCount($table));
            foreach (['type', 'token_type'] as $column) {
                if ($this->sourceHasColumn($table, $column)) {
                    $this->safeDistribution($table, $column, 'token_type_distribution');
                }
            }
            foreach (['token_hash', 'hash'] as $column) {
                if ($this->sourceHasColumn($table, $column)) {
                    $this->infoLine("hash_metadata_presence_count={$this->sourceNotNullCount($table, $column)}");
                }
            }
        }
    }

    private function rawEvidenceReferenceSummarySection(): void
    {
        $this->section('Raw evidence reference summary');
        if (! $this->sourceHasTable('raw_files')) {
            $this->warnLine('raw_files table missing; raw evidence summary skipped.');
            return;
        }

        $pathColumn = $this->firstExistingColumn('raw_files', ['saved_path', 'archive_path', 'path']);
        $this->infoLine('raw_files_count=' . $this->sourceCount('raw_files'));
        if ($pathColumn === null) {
            $this->warnLine('raw_files archive path column missing.');
            return;
        }

        $this->infoLine('raw_archive_reference_populated_count=' . $this->sourceNotNullCount('raw_files', $pathColumn));
        $this->infoLine('missing_archive_path_reference_count=' . $this->sourceNullOrBlankCount('raw_files', $pathColumn));
        $this->infoLine('copied_raw_archive_root_exists=' . (is_dir('D:\\inventory-rehearsal\\raw_archive') ? 'yes' : 'no'));
        $this->infoLine('referenced_evidence_missing_count=' . $this->referencedEvidenceMissingCount($pathColumn));
    }

    private function assignmentOverrideSummarySection(): void
    {
        $this->section('Assignment override summary');
        if (! $this->sourceHasTable('device_assignments')) {
            $this->warnLine('device_assignments table missing; assignment summary skipped.');
            return;
        }

        $this->infoLine('device_assignments_count=' . $this->sourceCount('device_assignments'));
        $missingLinks = $this->relationshipMissingCount('device_assignments', 'device_id', 'devices', 'id');
        $this->infoLine('valid_device_id_links=' . max(0, $this->sourceCount('device_assignments') - $missingLinks));
        foreach (['department', 'site_estimated', 'location', 'room'] as $column) {
            if ($this->sourceHasColumn('device_assignments', $column)) {
                $this->infoLine("assignment_{$column}_populated_count=" . $this->sourceNotNullCount('device_assignments', $column));
            }
        }
        $this->infoLine('assignment_rows_with_missing_device_link=' . $missingLinks);
        $this->infoLine('CSV assignment evidence is not authoritative over portal-managed assignment state.');
    }

    private function futureResetPlanSection(): void
    {
        $this->section('Future reset plan, not executed');
        $this->infoLine('Future execute phase should start from empty migrated schema or safely reset imported app/domain tables.');
        $this->infoLine('Keep migrations table.');
        $this->infoLine('Never clear live SQLite.');
        $this->infoLine('Never reset inventory_rehearsal_restore in Phase 19I.');
        $this->infoLine('No reset/truncate executed in this command.');
    }

    private function futureExecutePrerequisitesSection(): void
    {
        $this->section('Future execute prerequisites');
        $this->infoLine('Review this dry-run evidence.');
        $this->infoLine('Create MariaDB dump of empty migrated inventory_rehearsal schema before execute mode.');
        $this->infoLine('Approve a later execute phase before any data write path exists.');
        $this->infoLine('Keep inventory_rehearsal_restore reserved for restore rehearsal.');
    }

    private function stopConditionsSection(): void
    {
        $this->section('Stop conditions');
        foreach ([
            'source is live SQLite',
            'target is not inventory_rehearsal',
            'inventory_rehearsal_restore receives data',
            'primary keys cannot be preserved',
            'command lifecycle mapping is unclear',
            'token metadata mapping requires secret exposure',
            'raw evidence references cannot be preserved',
            'assignment override semantics are unclear',
            'JSON/text contents would be printed',
            'Direct repair_update becomes enabled',
        ] as $condition) {
            $this->infoLine($condition);
        }
    }

    /** @return list<string> */
    private function subsetCountLines(): array
    {
        return [
            'direct_https_runner_count=' . $this->sourceCountWhere('runners', 'transport_mode', 'direct_https'),
            'collector_share_runner_count=' . $this->collectorShareRunnerCount(),
            'collector_count=' . $this->sourceCount('collectors'),
            'pending_command_count=' . $this->sourceCountWhere('runner_commands', 'status', 'pending'),
            'delivered_dispatched_awaiting_ack_count=' . $this->awaitingAckCount(),
            'failed_command_count=' . $this->sourceCountWhere('runner_commands', 'status', 'failed'),
            'succeeded_command_count=' . $this->sourceCountWhere('runner_commands', 'status', 'succeeded'),
            'latest_scan_per_device_count=' . $this->latestScanPerDeviceCount(),
            'raw_files_with_missing_archive_path_count=' . $this->rawFilesMissingArchivePathCount(),
            'devices_with_latest_snapshot_count=' . $this->devicesWithLatestSnapshotCount(),
            'storage_health_by_risk_level=' . $this->storageHealthRiskSummary(),
            'device_assignment_override_count=' . $this->sourceCount('device_assignments'),
            'token_metadata_count_without_values=' . $this->tokenMetadataCount(),
        ];
    }

    private function relationshipCheck(string $child, string $childColumn, string $parent, string $parentColumn, bool $optional = false): void
    {
        if (! $this->sourceHasTable($child) || ! $this->sourceHasTable($parent)) {
            $this->warnLine("Relationship skipped; missing table: {$child} or {$parent}");
            return;
        }
        if (! $this->sourceHasColumn($child, $childColumn) || ! $this->sourceHasColumn($parent, $parentColumn)) {
            $this->warnLine("Relationship skipped; missing column: {$child}.{$childColumn} or {$parent}.{$parentColumn}");
            return;
        }

        $missing = $this->relationshipMissingCount($child, $childColumn, $parent, $parentColumn);
        $missing === 0
            ? $this->ok("Relationship valid: {$child}.{$childColumn} -> {$parent}.{$parentColumn}")
            : $this->warnLine("Relationship missing count for {$child}.{$childColumn} -> {$parent}.{$parentColumn}: {$missing}");
    }

    private function relationshipMissingCount(string $child, string $childColumn, string $parent, string $parentColumn): int
    {
        return $this->sourceScalarInt(
            "SELECT COUNT(*) FROM {$this->quoteIdentifier($child)} c
             LEFT JOIN {$this->quoteIdentifier($parent)} p ON c.{$this->quoteIdentifier($childColumn)} = p.{$this->quoteIdentifier($parentColumn)}
             WHERE c.{$this->quoteIdentifier($childColumn)} IS NOT NULL AND p.{$this->quoteIdentifier($parentColumn)} IS NULL"
        );
    }

    private function latestScanPreview(): void
    {
        if (! $this->sourceHasTable('device_scans') || ! $this->sourceHasColumn('device_scans', 'device_id')) {
            $this->warnLine('Latest scan per device skipped; missing device_scans.device_id.');
            return;
        }
        $this->infoLine('latest_scan_per_device_resolves_count=' . $this->latestScanPerDeviceCount());
    }

    private function latestHardwareSnapshotPreview(): void
    {
        if (! $this->sourceHasTable('hardware_snapshots') || ! $this->sourceHasTable('device_scans')) {
            $this->warnLine('Latest hardware snapshot preview skipped; missing table.');
            return;
        }
        $this->infoLine('devices_with_latest_hardware_snapshot_count=' . $this->devicesWithLatestSnapshotCount());
    }

    private function jsonDecodeCheck(string $table, string $column, ?string $label = null): void
    {
        $label ??= "{$table}.{$column}";
        if (! $this->sourceHasTable($table)) {
            $this->warnLine("JSON/text check skipped; missing table: {$table}");
            return;
        }
        if (! $this->sourceHasColumn($table, $column)) {
            $this->warnLine("JSON/text check skipped; missing column: {$table}.{$column}");
            return;
        }

        $rows = $this->sourceRows("SELECT {$this->quoteIdentifier($column)} AS value FROM {$this->quoteIdentifier($table)} WHERE {$this->quoteIdentifier($column)} IS NOT NULL AND TRIM(CAST({$this->quoteIdentifier($column)} AS TEXT)) != ''");
        $pass = 0;
        $fail = 0;
        foreach ($rows as $row) {
            json_decode((string) $row['value']);
            json_last_error() === JSON_ERROR_NONE ? $pass++ : $fail++;
        }

        $fail > 0
            ? $this->warnLine("JSON/text decode {$label}: pass={$pass} fail={$fail}")
            : $this->ok("JSON/text decode {$label}: pass={$pass} fail=0");
    }

    private function safeDistribution(string $table, string $column, string $label): void
    {
        $rows = $this->sourceRows("SELECT {$this->quoteIdentifier($column)} AS value, COUNT(*) AS count FROM {$this->quoteIdentifier($table)} GROUP BY {$this->quoteIdentifier($column)}");
        $parts = [];
        foreach ($rows as $row) {
            $value = trim((string) $row['value']);
            if ($value === '' || strlen($value) > 32 || preg_match('/[^A-Za-z0-9_\-]/', $value)) {
                $value = 'redacted-or-blank';
            }
            $parts[] = $value . ':' . (int) $row['count'];
        }
        $this->infoLine("{$label}=" . implode(',', $parts));
    }

    private function sourceColumnNames(string $table): array
    {
        $columns = [];
        foreach ($this->sourceRows('PRAGMA table_info(' . $this->quoteIdentifier($table) . ')') as $row) {
            $columns[] = (string) $row['name'];
        }

        return $columns;
    }

    private function sourceHasTable(string $table): bool
    {
        return isset($this->sourceTables[$table]);
    }

    private function sourceHasColumn(string $table, string $column): bool
    {
        return in_array($column, $this->sourceColumns[$table] ?? [], true);
    }

    private function firstExistingColumn(string $table, array $columns): ?string
    {
        foreach ($columns as $column) {
            if ($this->sourceHasColumn($table, $column)) {
                return $column;
            }
        }

        return null;
    }

    private function sourceCount(string $table): int
    {
        return $this->sourceHasTable($table)
            ? $this->sourceScalarInt('SELECT COUNT(*) FROM ' . $this->quoteIdentifier($table))
            : 0;
    }

    private function targetCount(string $table): int
    {
        try {
            return (int) DB::table($table)->count();
        } catch (Throwable) {
            return 0;
        }
    }

    private function sourceCountWhere(string $table, string $column, string $value): int
    {
        if (! $this->sourceHasTable($table) || ! $this->sourceHasColumn($table, $column)) {
            return 0;
        }

        $statement = $this->source?->prepare('SELECT COUNT(*) FROM ' . $this->quoteIdentifier($table) . ' WHERE ' . $this->quoteIdentifier($column) . ' = :value');
        $statement?->execute(['value' => $value]);

        return (int) $statement?->fetchColumn();
    }

    private function sourceNotNullCount(string $table, string $column): int
    {
        if (! $this->sourceHasTable($table) || ! $this->sourceHasColumn($table, $column)) {
            return 0;
        }

        return $this->sourceScalarInt('SELECT COUNT(*) FROM ' . $this->quoteIdentifier($table) . ' WHERE ' . $this->quoteIdentifier($column) . ' IS NOT NULL AND TRIM(CAST(' . $this->quoteIdentifier($column) . ' AS TEXT)) != \'\'');
    }

    private function sourceNullOrBlankCount(string $table, string $column): int
    {
        if (! $this->sourceHasTable($table) || ! $this->sourceHasColumn($table, $column)) {
            return 0;
        }

        return $this->sourceScalarInt('SELECT COUNT(*) FROM ' . $this->quoteIdentifier($table) . ' WHERE ' . $this->quoteIdentifier($column) . ' IS NULL OR TRIM(CAST(' . $this->quoteIdentifier($column) . ' AS TEXT)) = \'\'');
    }

    private function sourceScalarInt(string $sql): int
    {
        try {
            return (int) $this->source?->query($sql)->fetchColumn();
        } catch (Throwable) {
            return 0;
        }
    }

    /** @return list<array<string, mixed>> */
    private function sourceRows(string $sql): array
    {
        try {
            return $this->source?->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable) {
            return [];
        }
    }

    private function collectorShareRunnerCount(): int
    {
        if (! $this->sourceHasTable('runners') || ! $this->sourceHasColumn('runners', 'transport_mode')) {
            return 0;
        }

        return $this->sourceScalarInt("SELECT COUNT(*) FROM runners WHERE transport_mode IS NULL OR transport_mode != 'direct_https'");
    }

    private function awaitingAckCount(): int
    {
        if (! $this->sourceHasTable('runner_commands')) {
            return 0;
        }
        if ($this->sourceHasColumn('runner_commands', 'acknowledged_at')) {
            return $this->sourceScalarInt("SELECT COUNT(*) FROM runner_commands WHERE status IN ('dispatched', 'delivered') AND acknowledged_at IS NULL");
        }

        return $this->sourceScalarInt("SELECT COUNT(*) FROM runner_commands WHERE status IN ('dispatched', 'delivered')");
    }

    private function latestScanPerDeviceCount(): int
    {
        if (! $this->sourceHasTable('device_scans') || ! $this->sourceHasColumn('device_scans', 'device_id')) {
            return 0;
        }

        return $this->sourceScalarInt('SELECT COUNT(DISTINCT device_id) FROM device_scans WHERE device_id IS NOT NULL');
    }

    private function rawFilesMissingArchivePathCount(): int
    {
        if (! $this->sourceHasTable('raw_files')) {
            return 0;
        }
        $column = $this->firstExistingColumn('raw_files', ['saved_path', 'archive_path', 'path']);

        return $column ? $this->sourceNullOrBlankCount('raw_files', $column) : 0;
    }

    private function devicesWithLatestSnapshotCount(): int
    {
        if (! $this->sourceHasTable('hardware_snapshots') || ! $this->sourceHasTable('device_scans')) {
            return 0;
        }

        return $this->sourceScalarInt(
            'SELECT COUNT(DISTINCT ds.device_id)
             FROM hardware_snapshots hs
             JOIN device_scans ds ON ds.id = hs.device_scan_id
             WHERE ds.device_id IS NOT NULL'
        );
    }

    private function storageHealthRiskSummary(): string
    {
        if (! $this->sourceHasTable('storage_health_observations') || ! $this->sourceHasColumn('storage_health_observations', 'risk_level')) {
            return 'unavailable';
        }
        $rows = $this->sourceRows('SELECT risk_level, COUNT(*) AS count FROM storage_health_observations GROUP BY risk_level ORDER BY risk_level');

        return implode(',', array_map(fn (array $row): string => ((string) ($row['risk_level'] ?? 'blank')) . ':' . (int) $row['count'], $rows));
    }

    private function tokenMetadataCount(): int
    {
        return array_sum(array_map(fn (string $table): int => $this->sourceCount($table), ['site_tokens', 'personal_access_tokens']));
    }

    private function referencedEvidenceMissingCount(string $pathColumn): int
    {
        if (! $this->sourceHasColumn('raw_files', $pathColumn)) {
            return 0;
        }

        $missing = 0;
        foreach ($this->sourceRows('SELECT ' . $this->quoteIdentifier($pathColumn) . ' AS path FROM raw_files WHERE ' . $this->quoteIdentifier($pathColumn) . ' IS NOT NULL') as $row) {
            $path = trim((string) $row['path']);
            if ($path === '') {
                continue;
            }
            $candidate = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
            $full = str_starts_with($candidate, 'D:' . DIRECTORY_SEPARATOR)
                ? $candidate
                : 'D:' . DIRECTORY_SEPARATOR . 'inventory-rehearsal' . DIRECTORY_SEPARATOR . 'raw_archive' . DIRECTORY_SEPARATOR . ltrim($candidate, DIRECTORY_SEPARATOR);
            if (! is_file($full)) {
                $missing++;
            }
        }

        return $missing;
    }

    private function displayCount(?int $count): string
    {
        return $count === null ? 'missing' : (string) $count;
    }

    private function quoteIdentifier(string $identifier): string
    {
        return '"' . str_replace('"', '""', $identifier) . '"';
    }

    private function normalizePath(string $path): string
    {
        if (trim($path) === '') {
            return '';
        }

        return rtrim(str_replace('/', '\\', $path), '\\');
    }

    private function pathIsInside(string $path, string $parent): bool
    {
        $path = strtolower($this->normalizePath($path));
        $parent = strtolower($this->normalizePath($parent));

        return $path === $parent || str_starts_with($path, $parent . '\\');
    }

    private function section(string $name): void
    {
        $this->newLine();
        $this->line($name);
    }

    private function ok(string $message): void
    {
        $this->line("[OK] {$message}");
    }

    private function warnLine(string $message): void
    {
        $this->hasWarn = true;
        $this->line("[WARN] {$message}");
    }

    private function fail(string $message): void
    {
        $this->hasFail = true;
        $this->line("[FAIL] {$message}");
    }

    private function infoLine(string $message): void
    {
        $this->line("[INFO] {$message}");
    }

    private function resultSection(): void
    {
        $this->section('Result');

        if ($this->hasFail) {
            $this->line('Result: FAIL');
            return;
        }

        if ($this->hasWarn) {
            $this->line('Result: WARN');
            return;
        }

        $this->line('Result: PASS');
    }
}
