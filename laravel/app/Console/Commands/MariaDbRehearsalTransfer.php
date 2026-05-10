<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
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
    private const RAW_ARCHIVE_PATH = 'D:\\inventory-rehearsal\\raw_archive';
    private const DOWNLOADS_PATH = 'D:\\inventory-rehearsal\\downloads';
    private const MARIADB_DUMP_DIR = 'D:\\inventory-rehearsal\\backups\\mariadb_dumps';
    private const TARGET_DATABASE = 'inventory_rehearsal';
    private const RESTORE_DATABASE = 'inventory_rehearsal_restore';
    private const MIN_EXPECTED_MIGRATION_ROWS = 18;
    private const RAW_HASH_SEMANTICS_FILE_CONTENT_SHA256 = 'file_content_sha256';
    private const RAW_HASH_SEMANTICS_UNVERIFIED = 'file_content_hash_unverified';

    protected $signature = 'inventory:mariadb-rehearsal-transfer
        {--source= : Explicit copied SQLite source path}
        {--dry-run : Read-only planning mode}
        {--readiness : Read-only execute-readiness diagnostics}
        {--execute : Controlled Phase 19M write mode for inventory_rehearsal only}
        {--dump-marker= : Existing MariaDB dump marker/path for readiness/execute validation}
        {--confirm-rehearsal-target : Confirm target is the approved inventory_rehearsal database}
        {--confirm-empty-target : Confirm imported application/domain target tables are empty}
        {--confirm-dump-created : Confirm an operator-created dump exists before execute}
        {--confirm-no-reset : Confirm execute must not reset or truncate target tables}
        {--confirm-classification-rules-preserved : Confirm classification_rules stay target-preserved}
        {--confirm-raw-paths-preserved : Confirm raw_files path references stay unchanged}
        {--confirm-known-warn-raw-hash-unverified : Accept known warning for unverified raw_hash semantics when mapping is complete}';

    protected $description = 'App-aware SQLite-to-MariaDB rehearsal transfer planner and controlled execute importer';

    private bool $hasFail = false;

    private bool $hasWarn = false;

    /** @var array<string, bool> */
    private array $sourceTables = [];

    /** @var array<string, array<int, string>> */
    private array $sourceColumns = [];

    /** @var array<string, bool> */
    private array $targetTables = [];

    private ?PDO $source = null;

    private bool $writesPerformed = false;

    private bool $rawHashWarningPresent = false;

    private string $rawEvidenceMappingReadiness = 'not_run';

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
        'department_cleanup_rules',
        'site_cleanup_rules',
        'personal_access_tokens',
        'password_reset_tokens',
        'jobs',
        'failed_jobs',
        'cache',
        'sessions',
    ];

    /** @var list<string> */
    private array $executeImportTables = [
        'users',
        'collector_sites',
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
    ];

    /** @var list<string> */
    private array $frameworkTransientTables = [
        'personal_access_tokens',
        'password_reset_tokens',
        'jobs',
        'failed_jobs',
        'cache',
        'sessions',
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
        $this->resetRuntimeState();

        $this->line('MariaDB rehearsal transfer');
        $this->infoLine('Phase 19M execute is controlled and requires explicit confirmations.');
        $this->infoLine('Database is authoritative. Raw CSV files are archived evidence only.');

        $sourcePath = (string) $this->option('source');
        $mode = $this->commandMode();

        $this->environmentBoundarySection($sourcePath, $mode);
        if (! $this->hasFail) {
            $this->sourceSqliteValidationSection($sourcePath);
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
            if ($mode === 'readiness' || $mode === 'execute') {
                $this->executeReadinessDiagnosticsSection((string) $this->option('dump-marker'));
            }
            if ($mode === 'execute' && ! $this->hasFail) {
                $this->executeImportSection();
            }
            $this->futureResetPlanSection();
            $this->futureExecutePrerequisitesSection();
            $this->stopConditionsSection();
        }

        $this->resultSection();

        return $this->hasFail ? self::FAILURE : self::SUCCESS;
    }

    private function resetRuntimeState(): void
    {
        $this->hasFail = false;
        $this->hasWarn = false;
        $this->sourceTables = [];
        $this->sourceColumns = [];
        $this->targetTables = [];
        $this->source = null;
        $this->writesPerformed = false;
        $this->rawHashWarningPresent = false;
        $this->rawEvidenceMappingReadiness = 'not_run';
    }

    private function commandMode(): string
    {
        $modes = array_values(array_filter([
            (bool) $this->option('dry-run') ? 'dry-run' : null,
            (bool) $this->option('readiness') ? 'readiness' : null,
            (bool) $this->option('execute') ? 'execute' : null,
        ]));

        if (count($modes) !== 1) {
            return 'invalid';
        }

        return $modes[0];
    }

    private function environmentBoundarySection(string $sourcePath, string $mode): void
    {
        $this->section('Environment boundary');

        if ($mode === 'invalid') {
            $this->fail('Exactly one command mode is required: --dry-run, --readiness, or --execute.');
        } else {
            $this->ok("command_mode={$mode}");
        }

        if (trim($sourcePath) === '') {
            $this->fail('--source is required and must be explicit.');
        } else {
            $this->ok('--source supplied');
        }

        if ($mode === 'execute') {
            $this->validateExecuteConfirmations();
        } else {
            $this->infoLine('writes_performed=no');
        }

        $basePath = $this->normalizePath(base_path());
        if ($basePath !== $this->expectedBasePath()) {
            $this->fail('Current Laravel base path is not the rehearsal path.');
        } else {
            $this->ok('Laravel base path is the rehearsal path.');
        }

        $normalizedSource = $this->normalizePath($sourcePath);
        if ($normalizedSource === $this->liveSqlitePath()) {
            $this->fail('Source path is the live SQLite database and is refused.');
        }
        if ($normalizedSource !== '' && $this->pathIsInside($normalizedSource, $this->liveLaravelPath())) {
            $this->fail('Source path is under the live Laravel path and is refused.');
        }
        if ($normalizedSource !== '' && $normalizedSource !== $this->expectedSourcePath()) {
            $this->fail('Source path must be the copied rehearsal SQLite source.');
        }

        $defaultConnection = (string) config('database.default');
        $databaseName = (string) config("database.connections.{$defaultConnection}.database");
        $appUrl = strtolower((string) config('app.url'));

        $defaultConnection === 'mysql'
            ? $this->ok('DB_CONNECTION is mysql')
            : $this->fail('DB_CONNECTION is not mysql');

        $databaseName === $this->targetDatabase()
            ? $this->ok('DB_DATABASE is inventory_rehearsal')
            : $this->fail('DB_DATABASE is not inventory_rehearsal');

        str_contains($appUrl, 'inventory-pilot.internal.lan')
            ? $this->fail('APP_URL contains inventory-pilot.internal.lan')
            : $this->ok('APP_URL does not contain live pilot hostname');

        $this->infoLine('execute_option_available=' . ($this->getDefinition()->hasOption('execute') ? 'yes' : 'no'));
    }

    private function validateExecuteConfirmations(): void
    {
        $this->infoLine('phase=19M_execute_import');
        $this->infoLine('command_mode=execute');

        if (trim((string) $this->option('dump-marker')) === '') {
            $this->fail('--dump-marker is required with --execute.');
        }

        foreach ([
            'confirm-rehearsal-target',
            'confirm-empty-target',
            'confirm-dump-created',
            'confirm-no-reset',
            'confirm-classification-rules-preserved',
            'confirm-raw-paths-preserved',
            'confirm-known-warn-raw-hash-unverified',
        ] as $flag) {
            if (! (bool) $this->option($flag)) {
                $this->fail("--{$flag} is required with --execute.");
            }
        }

        $this->infoLine('execute_confirmations_complete=' . ($this->hasFail ? 'no' : 'yes'));
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
            if (((bool) $this->option('readiness') || (bool) $this->option('execute')) && $ran < self::MIN_EXPECTED_MIGRATION_ROWS) {
                $this->fail('Target migration state is incomplete for execute-readiness.');
            }
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

            $configuredRestoreExists = config('inventory.mariadb_rehearsal.restore_database_exists');
            $configuredRestoreTableCount = config('inventory.mariadb_rehearsal.restore_database_table_count');

            if ($configuredRestoreExists !== null || $configuredRestoreTableCount !== null) {
                $restoreExists = (bool) $configuredRestoreExists;
                $tableCount = (int) $configuredRestoreTableCount;
            } elseif ($driver === 'mysql') {
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

    private function executeReadinessDiagnosticsSection(string $dumpMarker): void
    {
        $this->section('Execute-readiness diagnostics');
        $this->infoLine('mode=' . ((bool) $this->option('execute') ? 'execute_prewrite_readiness' : 'dry_run_execute_readiness'));
        $this->infoLine('writes_performed=' . ($this->writesPerformed ? 'yes' : 'no'));
        $this->infoLine('execute_option_available=yes');
        $this->infoLine('target_reset_performed=no');
        $this->infoLine('dump_created=no');
        $this->infoLine('restore_performed=no');
        $this->infoLine('execute_approved=' . ((bool) $this->option('execute') ? 'yes' : 'no'));

        $this->copiedEvidencePathsReadinessSection();
        $this->emptyTargetValidationSection();
        $this->dumpMarkerValidationSection($dumpMarker);
        $this->classificationRulesCompareOnlySection();
        $this->rawEvidencePathMappingDiagnosticsSection();
        $this->optionalTablePolicySection();
        $this->schemaDifferencePolicySection();
    }

    private function copiedEvidencePathsReadinessSection(): void
    {
        $this->section('Readiness copied evidence paths');
        is_dir($this->rawArchivePath())
            ? $this->ok('Copied raw archive exists.')
            : $this->fail('Copied raw archive is missing.');

        is_dir($this->downloadsPath())
            ? $this->ok('Copied downloads path exists.')
            : $this->warnLine('Copied downloads path missing or not needed by current source.');

        $this->infoLine('web_endpoint_exposed=no');
        $this->infoLine('package_generation_performed=no');
        $this->infoLine('runner_collector_traffic_changed=no');
    }

    private function emptyTargetValidationSection(): void
    {
        $this->section('Empty target validation');

        $domainUnexpected = 0;
        foreach ($this->executeImportTables as $table) {
            $count = Schema::hasTable($table) ? $this->targetCount($table) : 0;
            if ($count > 0) {
                $domainUnexpected += $count;
                $this->fail("Target application/domain table is not empty: {$table}");
            }
        }

        $frameworkUnexpected = 0;
        foreach ($this->frameworkTransientTables as $table) {
            $count = Schema::hasTable($table) ? $this->targetCount($table) : 0;
            if ($count > 0) {
                $frameworkUnexpected += $count;
                $this->fail("Target framework/transient table has unexpected rows: {$table}");
            }
        }

        $migrationsRows = Schema::hasTable('migrations') ? $this->targetCount('migrations') : 0;
        if ($migrationsRows <= 0) {
            $this->fail('Target migrations table is missing applied migration rows.');
        }

        $classificationRows = Schema::hasTable('classification_rules') ? $this->targetCount('classification_rules') : 0;

        $status = $domainUnexpected === 0 && $frameworkUnexpected === 0 && $migrationsRows > 0 ? 'PASS' : 'FAIL';
        $this->infoLine("target_empty_state={$status}");
        $this->infoLine("target_domain_rows_unexpected_count={$domainUnexpected}");
        $this->infoLine("target_framework_rows_unexpected_count={$frameworkUnexpected}");
        $this->infoLine("target_migrations_rows={$migrationsRows}");
        $this->infoLine("target_classification_rules_rows={$classificationRows}");
        $this->infoLine('target_reset_attempted=no');
    }

    private function dumpMarkerValidationSection(string $dumpMarker): void
    {
        $this->section('Dump marker validation');

        $dumpMarker = trim($dumpMarker);
        $this->infoLine('dump_marker_supplied=' . ($dumpMarker === '' ? 'no' : 'yes'));
        if ($dumpMarker === '') {
            $this->fail('--dump-marker is required with --readiness.');
            $this->infoLine('dump_created_by_command=no');
            $this->infoLine('dump_contents_printed=no');
            return;
        }

        $normalized = $this->normalizePath($dumpMarker);
        $approvedDir = $this->dumpDirectoryPath();
        $underApproved = $this->pathIsInside($normalized, $approvedDir) && ! str_contains($normalized, '..');
        $this->infoLine('dump_marker_under_approved_directory=' . ($underApproved ? 'yes' : 'no'));
        if (! $underApproved) {
            $this->fail('Dump marker is outside approved MariaDB dump directory.');
        }

        $exists = is_file($dumpMarker);
        $this->infoLine('dump_marker_exists=' . ($exists ? 'yes' : 'no'));
        if (! $exists) {
            $this->fail('Dump marker file does not exist.');
            $this->infoLine('dump_created_by_command=no');
            $this->infoLine('dump_contents_printed=no');
            return;
        }

        $size = filesize($dumpMarker);
        $nonZero = $size !== false && $size > 0;
        $this->infoLine('dump_marker_nonzero=' . ($nonZero ? 'yes' : 'no'));
        if (! $nonZero) {
            $this->fail('Dump marker file is zero bytes.');
        }

        $modifiedAt = filemtime($dumpMarker);
        $plausible = $modifiedAt !== false && $modifiedAt <= time() + 300;
        $this->infoLine('dump_marker_timestamp_plausible=' . ($plausible ? 'yes' : 'no'));
        if (! $plausible) {
            $this->warnLine('Dump marker timestamp is not plausible for current rehearsal.');
        }

        $this->infoLine('dump_marker_basename=' . basename($dumpMarker));
        $this->infoLine('dump_marker_size=' . ($size === false ? 'unknown' : (string) $size));
        $this->infoLine('dump_created_by_command=no');
        $this->infoLine('dump_contents_printed=no');
    }

    private function classificationRulesCompareOnlySection(): void
    {
        $this->section('classification_rules compare-only validation');

        $sourceCount = $this->sourceHasTable('classification_rules') ? $this->sourceCount('classification_rules') : 0;
        $targetCount = Schema::hasTable('classification_rules') ? $this->targetCount('classification_rules') : 0;
        $this->infoLine("classification_rules_source_count={$sourceCount}");
        $this->infoLine("classification_rules_target_count={$targetCount}");

        $sourceIds = $this->classificationRuleIdentifiers('source');
        $targetIds = $this->classificationRuleIdentifiers('target');
        $sourceChecksum = $this->classificationRuleChecksum('source');
        $targetChecksum = $this->classificationRuleChecksum('target');

        $identifierMatch = $sourceIds !== [] && $sourceIds === $targetIds;
        $checksumMatch = $sourceChecksum !== '' && hash_equals($sourceChecksum, $targetChecksum);
        $this->infoLine('classification_rules_safe_identifier_match=' . ($identifierMatch ? 'yes' : 'no'));
        $this->infoLine('classification_rules_safe_checksum_match=' . ($checksumMatch ? 'yes' : 'no'));
        $this->infoLine('classification_rules_action=preserve_target_skip_import');
        $this->infoLine('classification_rules_write_attempted=no');

        if ($sourceCount === 0 && $targetCount === 0) {
            $this->warnLine('classification_rules absent in both source and target.');
            return;
        }

        if ($sourceCount === 0 && $targetCount > 0) {
            $this->fail('classification_rules target has rows while source has none.');
            return;
        }

        if ($sourceCount > 0 && $targetCount === 0) {
            $this->fail('classification_rules source has rows while target has none.');
            return;
        }

        if ($sourceCount !== $targetCount || ! $identifierMatch || ! $checksumMatch) {
            $this->fail('classification_rules source/target comparison mismatch.');
            return;
        }

        $this->ok('classification_rules compare-only validation passed.');
    }

    private function rawEvidencePathMappingDiagnosticsSection(): void
    {
        $this->section('Raw evidence path mapping diagnostics');

        $rawFilesCount = $this->sourceCount('raw_files');
        $pathColumn = $this->firstExistingColumn('raw_files', ['saved_path', 'archive_path', 'path']);
        $savedPathPopulated = $pathColumn ? $this->sourceNotNullCount('raw_files', $pathColumn) : 0;
        $rawHashPopulated = $this->sourceHasColumn('raw_files', 'raw_hash') ? $this->sourceNotNullCount('raw_files', 'raw_hash') : 0;
        $rawHashLength64 = $this->rawHashLengthCount(64);
        $rawArchiveRootExists = is_dir($this->rawArchivePath());
        $archiveIndex = $this->archiveBasenameIndex();
        $archiveFileCount = array_sum(array_map('count', $archiveIndex));
        $archiveUniqueBasenameCount = count(array_filter($archiveIndex, fn (array $paths): bool => count($paths) === 1));
        $archiveDuplicateBasenameCount = count(array_filter($archiveIndex, fn (array $paths): bool => count($paths) > 1));
        $rawHashSemantics = $this->rawHashSemantics();
        $hashValidationAvailable = $rawHashSemantics === self::RAW_HASH_SEMANTICS_FILE_CONTENT_SHA256;

        $basenameExists = 0;
        $basenameUnique = 0;
        $suffixResolved = 0;
        $ambiguous = 0;
        $unresolved = 0;
        $hashChecked = 0;
        $hashMismatch = 0;
        $sha256Matches = 0;
        $sha1Matches = 0;
        $md5Matches = 0;

        if ($pathColumn !== null && $rawArchiveRootExists) {
            foreach ($this->sourceRows('SELECT ' . $this->quoteIdentifier($pathColumn) . ' AS path, raw_hash FROM raw_files WHERE ' . $this->quoteIdentifier($pathColumn) . ' IS NOT NULL AND TRIM(CAST(' . $this->quoteIdentifier($pathColumn) . ' AS TEXT)) != \'\'') as $row) {
                $path = (string) $row['path'];
                $basename = basename(str_replace('\\', '/', $path));
                $candidates = $archiveIndex[$basename] ?? [];
                $resolvedCandidate = null;
                if ($candidates !== []) {
                    $basenameExists++;
                }
                if (count($candidates) === 1) {
                    $basenameUnique++;
                    $suffixResolved++;
                    $resolvedCandidate = $candidates[0];
                } elseif (count($candidates) > 1) {
                    $suffixCandidates = $this->suffixMatchingCandidates($path, $candidates);
                    if (count($suffixCandidates) === 1) {
                        $suffixResolved++;
                        $resolvedCandidate = $suffixCandidates[0];
                    } elseif (count($suffixCandidates) > 1) {
                        $ambiguous++;
                    } else {
                        $unresolved++;
                    }
                } else {
                    $unresolved++;
                }

                if ($hashValidationAvailable && $resolvedCandidate !== null) {
                    $rawHash = strtolower(trim((string) ($row['raw_hash'] ?? '')));
                    $hashChecked++;
                    $candidateHash = hash_file('sha256', $resolvedCandidate);
                    if ($candidateHash !== false && hash_equals($candidateHash, $rawHash)) {
                        $sha256Matches++;
                    } else {
                        $hashMismatch++;
                    }
                }
            }
        }

        $mappingFailed = ! $rawArchiveRootExists
            || $pathColumn === null
            || $savedPathPopulated !== $rawFilesCount
            || $ambiguous > 0
            || $unresolved > 0;
        $hashFailed = $hashValidationAvailable && $hashMismatch > 0;
        $mappingReadiness = $mappingFailed || $hashFailed
            ? 'FAIL'
            : ($hashValidationAvailable ? 'PASS' : 'PASS_WITH_WARN');
        $this->rawEvidenceMappingReadiness = $mappingReadiness;
        $this->rawHashWarningPresent = ! $hashValidationAvailable && ! $mappingFailed;

        $this->infoLine("raw_evidence_mapping_readiness={$mappingReadiness}");
        $this->infoLine("raw_files_count={$rawFilesCount}");
        $this->infoLine("saved_path_populated_count={$savedPathPopulated}");
        $this->infoLine('raw_archive_root_exists=' . ($rawArchiveRootExists ? 'yes' : 'no'));
        $this->infoLine("archive_file_count={$archiveFileCount}");
        $this->infoLine("archive_unique_basename_count={$archiveUniqueBasenameCount}");
        $this->infoLine("archive_duplicate_basename_count={$archiveDuplicateBasenameCount}");
        $this->infoLine("basename_exists_in_archive={$basenameExists}");
        $this->infoLine("basename_unique_in_archive={$basenameUnique}");
        $this->infoLine("suffix_resolved_count={$suffixResolved}");
        $this->infoLine("ambiguous_count={$ambiguous}");
        $this->infoLine("unresolved_count={$unresolved}");
        $this->infoLine("raw_hash_populated_count={$rawHashPopulated}");
        $this->infoLine("raw_hash_length_64_count={$rawHashLength64}");
        $this->infoLine("raw_hash_semantics={$rawHashSemantics}");
        $this->infoLine('hash_validation_available=' . ($hashValidationAvailable ? 'yes' : 'no'));
        $this->infoLine('hash_checked_count=' . ($hashValidationAvailable ? (string) $hashChecked : 'not_applicable'));
        $this->infoLine('hash_mismatch_count=' . ($hashValidationAvailable ? (string) $hashMismatch : 'not_applicable'));
        $this->infoLine('hash_algorithm_detected=' . ($hashValidationAvailable ? 'sha256' : 'none'));
        $this->infoLine("sha256_matches={$sha256Matches}");
        $this->infoLine("sha1_matches={$sha1Matches}");
        $this->infoLine("md5_matches={$md5Matches}");
        $this->infoLine('raw_hash_semantic_warning=' . ($hashValidationAvailable ? 'no' : 'yes'));
        $this->infoLine('raw_filenames_printed=no');
        $this->infoLine('raw_file_lists_printed=no');
        $this->infoLine('raw_contents_printed=no');
        $this->infoLine('resolved_paths_stored=no');

        if ($mappingFailed || $hashFailed) {
            $this->fail('Raw evidence path mapping readiness failed.');
            return;
        }

        if (! $hashValidationAvailable) {
            $this->warnLine('raw_hash semantics are unverified; content-hash validation skipped.');
        }
    }

    private function optionalTablePolicySection(): void
    {
        $this->section('Optional missing table policy');
        foreach (['site_tokens', 'department_cleanup_rules', 'site_cleanup_rules'] as $table) {
            $sourcePresent = $this->sourceHasTable($table);
            $targetPresent = Schema::hasTable($table);
            $targetRows = $targetPresent ? $this->targetCount($table) : 0;

            if (! $sourcePresent && ! $targetPresent) {
                $this->warnLine("optional_table={$table} action=skip_absent_in_both");
                continue;
            }
            if ($sourcePresent && ! $targetPresent) {
                $this->fail("Optional table present in source but missing in target: {$table}");
                continue;
            }
            if (! $sourcePresent && $targetRows > 0) {
                $this->fail("Optional table absent in source but target has rows: {$table}");
                continue;
            }
            $this->infoLine("optional_table={$table} source_present=" . ($sourcePresent ? 'yes' : 'no') . ' target_present=' . ($targetPresent ? 'yes' : 'no') . " target_rows={$targetRows}");
        }
    }

    private function executeImportSection(): void
    {
        $this->section('Phase 19M execute import');
        $this->infoLine('phase=19M_execute_import');
        $this->infoLine('command_mode=execute');
        $this->infoLine('source_exact_match=' . ($this->normalizePath((string) $this->option('source')) === $this->expectedSourcePath() ? 'yes' : 'no'));
        $this->infoLine('target_database=' . $this->targetDatabase());
        $this->infoLine('restore_database_written=no');
        $this->infoLine('reset_performed=no');
        $this->infoLine('truncate_performed=no');
        $this->infoLine('dump_created_by_command=no');
        $this->infoLine('classification_rules_action=preserve_target_skip_import');
        $this->infoLine('raw_paths_preserved=yes');
        $this->infoLine("raw_evidence_mapping_readiness={$this->rawEvidenceMappingReadiness}");
        $this->infoLine('hash_validation_available=' . ($this->rawHashSemantics() === self::RAW_HASH_SEMANTICS_FILE_CONTENT_SHA256 ? 'yes' : 'no'));

        if (! (bool) $this->option('confirm-known-warn-raw-hash-unverified') && $this->rawHashWarningPresent) {
            $this->fail('--confirm-known-warn-raw-hash-unverified is required for the current raw_hash warning.');
            return;
        }

        try {
            DB::transaction(function (): void {
                foreach ($this->executeImportTables as $table) {
                    $this->insertApprovedTable($table);
                }

                $this->runExecuteValidation(inTransaction: true);

                $forced = (string) config('inventory.mariadb_rehearsal.force_execute_validation_failure', '');
                if ($forced !== '') {
                    throw new \RuntimeException("forced {$forced} validation failure");
                }
            });
            $this->writesPerformed = true;
            $this->infoLine('writes_performed=yes');
        } catch (Throwable $e) {
            $this->infoLine('writes_performed=no');
            $this->infoLine('transaction_rolled_back=yes');
            $this->fail('Phase 19M execute import rolled back before commit.');
            return;
        }

        $autoIncrementPass = $this->validateAndRepairAutoIncrement();
        $postCommitPass = $this->runExecuteValidation(inTransaction: false);

        $this->infoLine('auto_increment_validation=' . ($autoIncrementPass ? 'PASS' : 'FAIL'));
        $this->infoLine('restore_db_untouched=' . ($this->restoreDbUntouched() ? 'PASS' : 'FAIL'));
        $this->infoLine('secrets_printed=no');
        $this->infoLine('raw_filenames_printed=no');
        $this->infoLine('raw_paths_printed=no');
        $this->infoLine('raw_contents_printed=no');
        $this->infoLine('command_payload_json_printed=no');
        $this->infoLine('full_runner_guids_printed=no');

        if (! $autoIncrementPass || ! $postCommitPass || ! $this->restoreDbUntouched()) {
            $this->fail('Phase 19M post-execute validation failed.');
        }
    }

    private function insertApprovedTable(string $table): void
    {
        if (! $this->sourceHasTable($table) || ! Schema::hasTable($table)) {
            throw new \RuntimeException("missing approved import table {$table}");
        }

        $targetColumns = Schema::getColumnListing($table);
        $columns = array_values(array_intersect($targetColumns, $this->sourceColumns[$table] ?? []));
        if (! in_array('id', $columns, true)) {
            throw new \RuntimeException("id column missing for {$table}");
        }

        $columnSql = implode(', ', array_map(fn (string $column): string => $this->quoteIdentifier($column), $columns));
        $rows = $this->sourceRows('SELECT ' . $columnSql . ' FROM ' . $this->quoteIdentifier($table) . ' ORDER BY id');

        foreach (array_chunk($rows, 100) as $chunk) {
            if ($chunk !== []) {
                DB::table($table)->insert($chunk);
            }
        }

        $this->infoLine("import_table={$table} source_count={$this->sourceCount($table)} target_count={$this->targetCount($table)}");
    }

    private function runExecuteValidation(bool $inTransaction): bool
    {
        $rowCountsPass = $this->executeRowCountsMatch();
        $primaryKeyPass = $this->primaryKeysPreserved();
        $relationshipPass = $this->targetRelationshipsValid();
        $jsonPass = $this->targetJsonTextValid();
        $commandLifecyclePass = $this->commandLifecyclePreserved();
        $assignmentPass = $this->assignmentOverridesPreserved();
        $rawEvidencePass = in_array($this->rawEvidenceMappingReadiness, ['PASS', 'PASS_WITH_WARN'], true)
            && $this->rawFileSavedPathsPreserved();

        $this->infoLine('primary_key_preservation=' . ($primaryKeyPass ? 'PASS' : 'FAIL'));
        $this->infoLine('relationship_validation=' . ($relationshipPass ? 'PASS' : 'FAIL'));
        $this->infoLine('json_text_validation=' . ($jsonPass ? 'PASS' : 'FAIL'));
        $this->infoLine('command_lifecycle_validation=' . ($commandLifecyclePass ? 'PASS' : 'FAIL'));
        $this->infoLine('assignment_override_validation=' . ($assignmentPass ? 'PASS' : 'FAIL'));
        $this->infoLine('raw_evidence_validation=' . ($rawEvidencePass ? $this->rawEvidenceMappingReadiness : 'FAIL'));

        $pass = $rowCountsPass
            && $primaryKeyPass
            && $relationshipPass
            && $jsonPass
            && $commandLifecyclePass
            && $assignmentPass
            && $rawEvidencePass;

        if ($inTransaction && ! $pass) {
            throw new \RuntimeException('in-transaction validation failed');
        }

        return $pass;
    }

    private function executeRowCountsMatch(): bool
    {
        $pass = true;
        foreach ($this->executeImportTables as $table) {
            $sourceCount = $this->sourceCount($table);
            $targetCount = $this->targetCount($table);
            $this->infoLine("execute_count table={$table} source_count={$sourceCount} target_count={$targetCount}");
            if ($sourceCount !== $targetCount) {
                $pass = false;
            }
        }

        return $pass;
    }

    private function primaryKeysPreserved(): bool
    {
        foreach ($this->executeImportTables as $table) {
            if ($this->sourceIdList($table) !== $this->targetIdList($table)) {
                return false;
            }
        }

        return true;
    }

    /** @return list<int> */
    private function sourceIdList(string $table): array
    {
        return array_map('intval', array_column($this->sourceRows('SELECT id FROM ' . $this->quoteIdentifier($table) . ' ORDER BY id'), 'id'));
    }

    /** @return list<int> */
    private function targetIdList(string $table): array
    {
        try {
            return array_map(fn (object $row): int => (int) $row->id, DB::table($table)->select('id')->orderBy('id')->get()->all());
        } catch (Throwable) {
            return [];
        }
    }

    private function targetRelationshipsValid(): bool
    {
        $checks = [
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
        ];

        foreach ($checks as [$child, $childColumn, $parent, $parentColumn]) {
            if (! $this->targetHasColumns($child, [$childColumn]) || ! $this->targetHasColumns($parent, [$parentColumn])) {
                continue;
            }
            $missing = DB::table($child . ' as c')
                ->leftJoin($parent . ' as p', "c.{$childColumn}", '=', "p.{$parentColumn}")
                ->whereNotNull("c.{$childColumn}")
                ->whereNull("p.{$parentColumn}")
                ->count();
            if ($missing > 0) {
                return false;
            }
        }

        return true;
    }

    private function targetJsonTextValid(): bool
    {
        foreach ([
            ['hardware_snapshots', 'snapshot_json'],
            ['raw_files', 'metadata_json'],
            ['collectors', 'raw_status_json'],
            ['runners', 'raw_state_json'],
            ['runner_commands', 'payload_json'],
            ['storage_health_observations', 'raw_json'],
            ['storage_health_observations', 'risk_reasons'],
        ] as [$table, $column]) {
            if (! $this->targetHasColumns($table, [$column])) {
                continue;
            }
            foreach (DB::table($table)->select($column)->whereNotNull($column)->get()->all() as $row) {
                $value = trim((string) $row->{$column});
                if ($value === '') {
                    continue;
                }
                json_decode($value);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    return false;
                }
            }
        }

        return true;
    }

    private function commandLifecyclePreserved(): bool
    {
        if (! $this->targetHasColumns('runner_commands', ['status'])) {
            return true;
        }
        foreach (['queued', 'pending', 'dispatched', 'acknowledged', 'completed', 'succeeded', 'failed'] as $status) {
            if ($this->sourceCountWhere('runner_commands', 'status', $status) !== (int) DB::table('runner_commands')->where('status', $status)->count()) {
                return false;
            }
        }

        return true;
    }

    private function assignmentOverridesPreserved(): bool
    {
        return $this->sourceCount('device_assignments') === $this->targetCount('device_assignments');
    }

    private function rawFileSavedPathsPreserved(): bool
    {
        if (! $this->sourceHasColumn('raw_files', 'saved_path') || ! $this->targetHasColumns('raw_files', ['saved_path'])) {
            return true;
        }

        $source = array_map(fn (array $row): string => (int) $row['id'] . ':' . (string) $row['saved_path'], $this->sourceRows('SELECT id, saved_path FROM raw_files ORDER BY id'));
        $target = DB::table('raw_files')->select(['id', 'saved_path'])->orderBy('id')->get()->map(fn (object $row): string => (int) $row->id . ':' . (string) $row->saved_path)->all();

        return $source === $target;
    }

    /** @param list<string> $columns */
    private function targetHasColumns(string $table, array $columns): bool
    {
        if (! Schema::hasTable($table)) {
            return false;
        }
        $targetColumns = Schema::getColumnListing($table);
        foreach ($columns as $column) {
            if (! in_array($column, $targetColumns, true)) {
                return false;
            }
        }

        return true;
    }

    private function validateAndRepairAutoIncrement(): bool
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            foreach ($this->executeImportTables as $table) {
                $this->infoLine("auto_increment_table={$table} status=not_mysql_skipped");
            }

            return true;
        }

        $pass = true;
        foreach ($this->executeImportTables as $table) {
            $maxId = (int) DB::table($table)->max('id');
            if ($maxId <= 0) {
                $this->infoLine("auto_increment_table={$table} status=no_rows");
                continue;
            }

            $next = $this->mysqlAutoIncrementValue($table);
            $expected = $maxId + 1;
            if ($next === null || $next > $maxId) {
                $this->infoLine("auto_increment_table={$table} status=pass max_id={$maxId}");
                continue;
            }

            try {
                DB::statement('ALTER TABLE `' . str_replace('`', '``', $table) . '` AUTO_INCREMENT = ' . $expected);
                $this->infoLine("auto_increment_table={$table} status=repaired max_id={$maxId}");
            } catch (Throwable) {
                $this->infoLine("auto_increment_table={$table} status=repair_failed max_id={$maxId}");
                $pass = false;
            }
        }
        $this->infoLine('auto_increment_skipped_table=migrations');
        $this->infoLine('auto_increment_skipped_table=classification_rules');

        return $pass;
    }

    private function mysqlAutoIncrementValue(string $table): ?int
    {
        try {
            $row = DB::table('information_schema.TABLES')
                ->select('AUTO_INCREMENT')
                ->where('TABLE_SCHEMA', $this->targetDatabase())
                ->where('TABLE_NAME', $table)
                ->first();

            return $row?->AUTO_INCREMENT === null ? null : (int) $row->AUTO_INCREMENT;
        } catch (Throwable) {
            return null;
        }
    }

    private function restoreDbUntouched(): bool
    {
        $configuredRestoreExists = config('inventory.mariadb_rehearsal.restore_database_exists');
        $configuredRestoreTableCount = config('inventory.mariadb_rehearsal.restore_database_table_count');
        if ($configuredRestoreExists !== null || $configuredRestoreTableCount !== null) {
            return (bool) $configuredRestoreExists && (int) $configuredRestoreTableCount === 0;
        }

        try {
            if (DB::connection()->getDriverName() !== 'mysql') {
                return true;
            }

            $restoreExists = (int) DB::table('information_schema.SCHEMATA')
                ->where('SCHEMA_NAME', self::RESTORE_DATABASE)
                ->count() === 1;
            $tableCount = (int) DB::table('information_schema.TABLES')
                ->where('TABLE_SCHEMA', self::RESTORE_DATABASE)
                ->count();

            return $restoreExists && $tableCount === 0;
        } catch (Throwable) {
            return false;
        }
    }

    private function schemaDifferencePolicySection(): void
    {
        $this->section('Schema difference policy');
        foreach ([
            ['raw_files', 'device_scan_id'],
            ['raw_files', 'device_id'],
            ['storage_health_observations', 'raw_json'],
        ] as [$table, $column]) {
            $present = $this->sourceHasColumn($table, $column);
            $this->warnLine("known_optional_column={$table}.{$column} present=" . ($present ? 'yes' : 'no') . ' action=' . ($present ? 'validate_available' : 'skip_known_optional_missing'));
        }

        $unexpectedRequiredMissing = 0;
        foreach ($this->executeImportTables as $table) {
            if (! Schema::hasTable($table) || ! $this->sourceHasTable($table)) {
                continue;
            }
            foreach ($this->targetRequiredColumnsWithoutSafeSource($table) as $column) {
                $unexpectedRequiredMissing++;
                $this->fail("Required target column has no safe source/default: {$table}.{$column}");
            }
        }
        $this->infoLine("unexpected_required_schema_difference_count={$unexpectedRequiredMissing}");
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
        $this->infoLine('Phase 19M execute starts from an empty approved target and does not reset or truncate tables.');
        $this->infoLine('Keep migrations table.');
        $this->infoLine('Never clear live SQLite.');
        $this->infoLine('Never reset inventory_rehearsal_restore in this command.');
        $this->infoLine('No reset/truncate executed in this command.');
    }

    private function futureExecutePrerequisitesSection(): void
    {
        $this->section('Future execute prerequisites');
        $this->infoLine('Review this dry-run evidence.');
        $this->infoLine('Use an operator-created MariaDB dump marker before execute mode.');
        $this->infoLine('Do not treat Phase 19M execute as restore rehearsal, production migration, or cutover approval.');
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

    private function rawHashLengthCount(int $length): int
    {
        if (! $this->sourceHasTable('raw_files') || ! $this->sourceHasColumn('raw_files', 'raw_hash')) {
            return 0;
        }

        return $this->sourceScalarInt('SELECT COUNT(*) FROM raw_files WHERE raw_hash IS NOT NULL AND LENGTH(TRIM(CAST(raw_hash AS TEXT))) = ' . $length);
    }

    private function rawHashSemantics(): string
    {
        $semantics = strtolower(trim((string) config('inventory.mariadb_rehearsal.raw_hash_semantics', '')));

        return $semantics === self::RAW_HASH_SEMANTICS_FILE_CONTENT_SHA256
            ? self::RAW_HASH_SEMANTICS_FILE_CONTENT_SHA256
            : self::RAW_HASH_SEMANTICS_UNVERIFIED;
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

    /** @return list<string> */
    private function classificationRuleIdentifiers(string $side): array
    {
        $rows = $this->classificationRuleRows($side);
        $identifiers = [];
        foreach ($rows as $row) {
            $identifier = $this->firstNonEmptyValue($row, ['key', 'name', 'pattern', 'label', 'id']);
            if ($identifier === '') {
                return [];
            }
            $identifiers[] = hash('sha256', $identifier);
        }

        sort($identifiers);

        return count($identifiers) === count(array_unique($identifiers)) ? $identifiers : [];
    }

    private function classificationRuleChecksum(string $side): string
    {
        $rows = $this->classificationRuleRows($side);
        if ($rows === []) {
            return '';
        }

        $safeRows = [];
        foreach ($rows as $row) {
            ksort($row);
            unset($row['created_at'], $row['updated_at']);
            $safeRows[] = json_encode($row, JSON_THROW_ON_ERROR);
        }
        sort($safeRows);

        return hash('sha256', implode("\n", $safeRows));
    }

    /** @return list<array<string, mixed>> */
    private function classificationRuleRows(string $side): array
    {
        if ($side === 'source') {
            if (! $this->sourceHasTable('classification_rules')) {
                return [];
            }

            return $this->sourceRows('SELECT * FROM classification_rules');
        }

        if (! Schema::hasTable('classification_rules')) {
            return [];
        }

        try {
            return array_map(fn (object $row): array => (array) $row, DB::table('classification_rules')->get()->all());
        } catch (Throwable) {
            return [];
        }
    }

    /** @param array<string, mixed> $row */
    private function firstNonEmptyValue(array $row, array $columns): string
    {
        foreach ($columns as $column) {
            $value = trim((string) ($row[$column] ?? ''));
            if ($value !== '') {
                return $column . ':' . $value;
            }
        }

        return '';
    }

    /** @return array<string, list<string>> */
    private function archiveBasenameIndex(): array
    {
        $root = $this->rawArchivePath();
        if (! is_dir($root)) {
            return [];
        }

        $index = [];
        foreach (File::allFiles($root) as $file) {
            $index[$file->getBasename()][] = $file->getPathname();
        }

        return $index;
    }

    /** @param list<string> $candidates @return list<string> */
    private function suffixMatchingCandidates(string $storedPath, array $candidates): array
    {
        $normalizedStored = strtolower(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $storedPath));
        $segments = array_values(array_filter(explode(DIRECTORY_SEPARATOR, $normalizedStored), fn (string $segment): bool => $segment !== ''));

        for ($length = min(count($segments), 4); $length >= 1; $length--) {
            $matches = [];
            $suffixSegments = array_slice($segments, -$length);
            foreach ($candidates as $candidate) {
                $normalizedCandidate = strtolower(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $candidate));
                $candidateSegments = array_values(array_filter(explode(DIRECTORY_SEPARATOR, $normalizedCandidate), fn (string $segment): bool => $segment !== ''));
                if ($suffixSegments !== [] && array_slice($candidateSegments, -$length) === $suffixSegments) {
                    $matches[] = $candidate;
                }
            }

            if ($matches !== []) {
                return array_values(array_unique($matches));
            }
        }

        return [];
    }

    /** @return list<string> */
    private function targetRequiredColumnsWithoutSafeSource(string $table): array
    {
        try {
            $databaseName = $this->targetDatabase();
            $driver = DB::connection()->getDriverName();
            if ($driver !== 'mysql') {
                return [];
            }

            $rows = DB::table('information_schema.COLUMNS')
                ->select(['COLUMN_NAME', 'IS_NULLABLE', 'COLUMN_DEFAULT', 'EXTRA'])
                ->where('TABLE_SCHEMA', $databaseName)
                ->where('TABLE_NAME', $table)
                ->get();
        } catch (Throwable) {
            return [];
        }

        $missing = [];
        foreach ($rows as $row) {
            $column = (string) $row->COLUMN_NAME;
            $nullable = (string) $row->IS_NULLABLE;
            $default = $row->COLUMN_DEFAULT;
            $extra = strtolower((string) $row->EXTRA);
            if ($this->sourceHasColumn($table, $column)) {
                continue;
            }
            if ($nullable === 'YES' || $default !== null || str_contains($extra, 'auto_increment')) {
                continue;
            }
            $missing[] = $column;
        }

        return $missing;
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
                : $this->rawArchivePath() . DIRECTORY_SEPARATOR . ltrim($candidate, DIRECTORY_SEPARATOR);
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

    private function expectedBasePath(): string
    {
        return $this->normalizePath((string) config('inventory.mariadb_rehearsal.expected_base_path', self::EXPECTED_BASE_PATH));
    }

    private function liveLaravelPath(): string
    {
        return $this->normalizePath((string) config('inventory.mariadb_rehearsal.live_laravel_path', self::LIVE_LARAVEL_PATH));
    }

    private function liveSqlitePath(): string
    {
        return $this->normalizePath((string) config('inventory.mariadb_rehearsal.live_sqlite_path', self::LIVE_SQLITE_PATH));
    }

    private function expectedSourcePath(): string
    {
        return $this->normalizePath((string) config('inventory.mariadb_rehearsal.expected_source_path', self::EXPECTED_SOURCE_PATH));
    }

    private function rawArchivePath(): string
    {
        return $this->normalizePath((string) config('inventory.mariadb_rehearsal.raw_archive_path', self::RAW_ARCHIVE_PATH));
    }

    private function downloadsPath(): string
    {
        return $this->normalizePath((string) config('inventory.mariadb_rehearsal.downloads_path', self::DOWNLOADS_PATH));
    }

    private function dumpDirectoryPath(): string
    {
        return $this->normalizePath((string) config('inventory.mariadb_rehearsal.dump_directory', self::MARIADB_DUMP_DIR));
    }

    private function targetDatabase(): string
    {
        return (string) config('inventory.mariadb_rehearsal.target_database', self::TARGET_DATABASE);
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
