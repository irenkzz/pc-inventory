<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PDO;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

class MariaDbRehearsalTransferCommandTest extends TestCase
{
    public function test_command_is_registered_with_execute_and_confirmation_options(): void
    {
        $commands = Artisan::all();

        $this->assertArrayHasKey('inventory:mariadb-rehearsal-transfer', $commands);
        $this->assertTrue($commands['inventory:mariadb-rehearsal-transfer']->getDefinition()->hasOption('source'));
        $this->assertTrue($commands['inventory:mariadb-rehearsal-transfer']->getDefinition()->hasOption('dry-run'));
        $this->assertTrue($commands['inventory:mariadb-rehearsal-transfer']->getDefinition()->hasOption('readiness'));
        $this->assertTrue($commands['inventory:mariadb-rehearsal-transfer']->getDefinition()->hasOption('dump-marker'));
        $this->assertTrue($commands['inventory:mariadb-rehearsal-transfer']->getDefinition()->hasOption('execute'));
        foreach ($this->executeConfirmationFlags() as $flag) {
            $this->assertTrue($commands['inventory:mariadb-rehearsal-transfer']->getDefinition()->hasOption($flag));
        }
        $this->assertFalse($commands['inventory:mariadb-rehearsal-transfer']->getDefinition()->hasOption('force-execute'));
    }

    public function test_command_requires_exactly_one_mode(): void
    {
        [$exitCode, $output] = $this->runCommand([
            '--source' => 'D:\\inventory-rehearsal\\source-copy\\database.sqlite',
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Exactly one command mode is required', $output);
        $this->assertStringContainsString('Result: FAIL', $output);
    }

    public function test_command_requires_explicit_source(): void
    {
        [$exitCode, $output] = $this->runCommand([
            '--dry-run' => true,
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('[FAIL] --source is required and must be explicit.', $output);
        $this->assertStringContainsString('Result: FAIL', $output);
    }

    public function test_command_with_no_mode_fails_closed(): void
    {
        [$exitCode, $output] = $this->runCommand([]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Exactly one command mode is required', $output);
        $this->assertStringContainsString('--source is required and must be explicit.', $output);
    }

    public function test_command_refuses_live_sqlite_path(): void
    {
        [$exitCode, $output] = $this->runCommand([
            '--dry-run' => true,
            '--source' => 'D:\\inventory\\laravel\\database\\database.sqlite',
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Source path is the live SQLite database and is refused.', $output);
        $this->assertStringContainsString('Source path is under the live Laravel path and is refused.', $output);
    }

    public function test_command_refuses_source_under_live_laravel_path(): void
    {
        [$exitCode, $output] = $this->runCommand([
            '--dry-run' => true,
            '--source' => 'D:\\inventory\\laravel\\storage\\copy.sqlite',
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Source path is under the live Laravel path and is refused.', $output);
    }

    public function test_command_refuses_wrong_base_path(): void
    {
        [$exitCode, $output] = $this->runCommand([
            '--dry-run' => true,
            '--source' => 'D:\\inventory-rehearsal\\source-copy\\database.sqlite',
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Current Laravel base path is not the rehearsal path.', $output);
    }

    public function test_command_refuses_wrong_database_name(): void
    {
        $this->app->setBasePath('D:\\inventory-rehearsal\\laravel');
        Config::set('database.default', 'mysql');
        Config::set('database.connections.mysql.database', 'not_inventory_rehearsal');

        [$exitCode, $output] = $this->runCommand([
            '--dry-run' => true,
            '--source' => 'D:\\inventory-rehearsal\\source-copy\\database.sqlite',
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('DB_DATABASE is not inventory_rehearsal', $output);
    }

    public function test_command_refuses_live_app_url(): void
    {
        $this->app->setBasePath('D:\\inventory-rehearsal\\laravel');
        Config::set('database.default', 'mysql');
        Config::set('database.connections.mysql.database', 'inventory_rehearsal');
        Config::set('app.url', 'https://inventory-pilot.internal.lan');

        [$exitCode, $output] = $this->runCommand([
            '--dry-run' => true,
            '--source' => 'D:\\inventory-rehearsal\\source-copy\\database.sqlite',
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('APP_URL contains inventory-pilot.internal.lan', $output);
    }

    public function test_command_checks_source_exists_after_boundaries_pass(): void
    {
        $this->setRehearsalBoundaryConfig();

        [$exitCode, $output] = $this->runCommand([
            '--dry-run' => true,
            '--source' => 'D:\\inventory-rehearsal\\source-copy\\database.sqlite',
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Source SQLite file does not exist.', $output);
    }

    public function test_command_prints_required_dry_run_sections_on_boundary_failures(): void
    {
        [$exitCode, $output] = $this->runCommand([
            '--dry-run' => true,
            '--source' => 'D:\\inventory\\laravel\\database\\database.sqlite',
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Environment boundary', $output);
        $this->assertStringContainsString('Result', $output);
        $this->assertStringContainsString('Phase 19M execute is controlled and requires explicit confirmations.', $output);
    }

    public function test_command_does_not_print_secrets_or_payloads(): void
    {
        Config::set('app.key', 'base64:fake-app-key-secret-value');
        Config::set('database.connections.mysql.password', 'fake-db-password-secret-value');

        [, $output] = $this->runCommand([
            '--dry-run' => true,
            '--source' => 'D:\\inventory\\laravel\\database\\database.sqlite',
        ]);

        $this->assertStringNotContainsString('fake-app-key-secret-value', $output);
        $this->assertStringNotContainsString('fake-db-password-secret-value', $output);
        $this->assertStringNotContainsString('payload_json', $output);
        $this->assertStringNotContainsString('command payload', strtolower($output));
    }

    public function test_command_result_line_is_exactly_one_final_line(): void
    {
        [, $output] = $this->runCommand([
            '--dry-run' => true,
            '--source' => 'D:\\inventory\\laravel\\database\\database.sqlite',
        ]);

        $this->assertSame(1, preg_match_all('/^Result: (PASS|WARN|FAIL)\r?$/m', $output));
        $this->assertMatchesRegularExpression('/Result: (PASS|WARN|FAIL)\s*$/', $output);
    }

    public function test_command_class_contains_planned_order_and_no_reset_mode(): void
    {
        $source = file_get_contents(app_path('Console/Commands/MariaDbRehearsalTransfer.php'));

        $this->assertIsString($source);
        $this->assertStringContainsString('users', $source);
        $this->assertStringContainsString('runner_commands', $source);
        $this->assertStringContainsString('Do not transfer migrations from SQLite', $source);
        $this->assertStringNotContainsString('truncate(', $source);
        $this->assertStringNotContainsString('delete(', $source);
        $this->assertStringNotContainsString('update(', $source);
    }

    public function test_readiness_conflicts_with_dry_run(): void
    {
        [$exitCode, $output] = $this->runCommand([
            '--dry-run' => true,
            '--readiness' => true,
            '--source' => 'D:\\inventory-rehearsal\\source-copy\\database.sqlite',
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Exactly one command mode is required', $output);
        $this->assertStringContainsString('Result: FAIL', $output);
    }

    public function test_readiness_requires_explicit_source(): void
    {
        [$exitCode, $output] = $this->runCommand([
            '--readiness' => true,
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('--source is required and must be explicit.', $output);
        $this->assertStringContainsString('Result: FAIL', $output);
    }

    public function test_dry_run_readiness_passes_read_only_fixture_with_expected_warnings(): void
    {
        $fixture = $this->makeReadinessFixture();

        [$exitCode, $output] = $this->runCommand([
            '--readiness' => true,
            '--source' => $fixture['source'],
            '--dump-marker' => $fixture['dump'],
        ]);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('mode=dry_run_execute_readiness', $output);
        $this->assertStringContainsString('writes_performed=no', $output);
        $this->assertStringContainsString('execute_option_available=yes', $output);
        $this->assertStringContainsString('target_reset_performed=no', $output);
        $this->assertStringContainsString('dump_created=no', $output);
        $this->assertStringContainsString('restore_performed=no', $output);
        $this->assertStringContainsString('execute_approved=no', $output);
        $this->assertStringContainsString('target_empty_state=PASS', $output);
        $this->assertStringContainsString('dump_marker_supplied=yes', $output);
        $this->assertStringContainsString('dump_marker_under_approved_directory=yes', $output);
        $this->assertStringContainsString('dump_marker_exists=yes', $output);
        $this->assertStringContainsString('dump_marker_nonzero=yes', $output);
        $this->assertStringContainsString('classification_rules_action=preserve_target_skip_import', $output);
        $this->assertStringContainsString('classification_rules_write_attempted=no', $output);
        $this->assertStringContainsString('suffix_resolved_count=1', $output);
        $this->assertStringContainsString('ambiguous_count=0', $output);
        $this->assertStringContainsString('unresolved_count=0', $output);
        $this->assertStringContainsString('raw_evidence_mapping_readiness=PASS_WITH_WARN', $output);
        $this->assertStringContainsString('raw_hash_semantics=file_content_hash_unverified', $output);
        $this->assertStringContainsString('hash_validation_available=no', $output);
        $this->assertStringContainsString('hash_checked_count=not_applicable', $output);
        $this->assertStringContainsString('hash_mismatch_count=not_applicable', $output);
        $this->assertStringContainsString('hash_algorithm_detected=none', $output);
        $this->assertStringContainsString('raw_hash_semantic_warning=yes', $output);
        $this->assertStringContainsString('raw_filenames_printed=no', $output);
        $this->assertStringContainsString('raw_file_lists_printed=no', $output);
        $this->assertStringContainsString('raw_contents_printed=no', $output);
        $this->assertStringContainsString('resolved_paths_stored=no', $output);
        $this->assertStringContainsString('optional_table=site_tokens action=skip_absent_in_both', $output);
        $this->assertStringContainsString('Result: WARN', $output);
        $this->assertStringNotContainsString('evidence-one.csv', $output);
        $this->assertStringNotContainsString($fixture['rawArchive'], $output);
    }

    public function test_readiness_missing_dump_marker_fails(): void
    {
        $fixture = $this->makeReadinessFixture();

        [$exitCode, $output] = $this->runCommand([
            '--readiness' => true,
            '--source' => $fixture['source'],
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('dump_marker_supplied=no', $output);
        $this->assertStringContainsString('--dump-marker is required with --readiness.', $output);
    }

    public function test_readiness_dump_marker_outside_approved_directory_fails(): void
    {
        $fixture = $this->makeReadinessFixture();
        $outside = $fixture['root'] . DIRECTORY_SEPARATOR . 'outside.marker';
        file_put_contents($outside, 'marker');

        [$exitCode, $output] = $this->runCommand([
            '--readiness' => true,
            '--source' => $fixture['source'],
            '--dump-marker' => $outside,
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('dump_marker_under_approved_directory=no', $output);
        $this->assertStringContainsString('Dump marker is outside approved MariaDB dump directory.', $output);
    }

    public function test_readiness_dump_marker_path_traversal_fails(): void
    {
        $fixture = $this->makeReadinessFixture();
        $traversal = $fixture['dumpDir'] . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'escape.marker';
        file_put_contents($fixture['root'] . DIRECTORY_SEPARATOR . 'escape.marker', 'marker');

        [$exitCode, $output] = $this->runCommand([
            '--readiness' => true,
            '--source' => $fixture['source'],
            '--dump-marker' => $traversal,
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('dump_marker_under_approved_directory=no', $output);
    }

    public function test_readiness_missing_and_zero_byte_dump_marker_fail(): void
    {
        $fixture = $this->makeReadinessFixture();
        $missing = $fixture['dumpDir'] . DIRECTORY_SEPARATOR . 'missing.marker';

        [$missingExit, $missingOutput] = $this->runCommand([
            '--readiness' => true,
            '--source' => $fixture['source'],
            '--dump-marker' => $missing,
        ]);

        $this->assertSame(1, $missingExit);
        $this->assertStringContainsString('dump_marker_exists=no', $missingOutput);

        $zero = $fixture['dumpDir'] . DIRECTORY_SEPARATOR . 'zero.marker';
        touch($zero);

        [$zeroExit, $zeroOutput] = $this->runCommand([
            '--readiness' => true,
            '--source' => $fixture['source'],
            '--dump-marker' => $zero,
        ]);

        $this->assertSame(1, $zeroExit);
        $this->assertStringContainsString('dump_marker_nonzero=no', $zeroOutput);
    }

    public function test_readiness_fails_when_domain_target_rows_exist(): void
    {
        $fixture = $this->makeReadinessFixture();
        DB::table('devices')->insert(['id' => 99]);

        [$exitCode, $output] = $this->runCommand([
            '--readiness' => true,
            '--source' => $fixture['source'],
            '--dump-marker' => $fixture['dump'],
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Target application/domain table is not empty: devices', $output);
        $this->assertStringContainsString('target_empty_state=FAIL', $output);
        $this->assertStringContainsString('target_reset_attempted=no', $output);
    }

    public function test_readiness_fails_when_runner_commands_target_rows_exist(): void
    {
        $fixture = $this->makeReadinessFixture();
        DB::table('runner_commands')->insert(['id' => 99]);

        [$exitCode, $output] = $this->runCommand([
            '--readiness' => true,
            '--source' => $fixture['source'],
            '--dump-marker' => $fixture['dump'],
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Target application/domain table is not empty: runner_commands', $output);
    }

    public function test_readiness_allows_migrations_and_matching_classification_rules(): void
    {
        $fixture = $this->makeReadinessFixture();

        [$exitCode, $output] = $this->runCommand([
            '--readiness' => true,
            '--source' => $fixture['source'],
            '--dump-marker' => $fixture['dump'],
        ]);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('target_migrations_rows=18', $output);
        $this->assertStringContainsString('target_classification_rules_rows=8', $output);
        $this->assertStringContainsString('classification_rules_safe_identifier_match=yes', $output);
        $this->assertStringContainsString('classification_rules_safe_checksum_match=yes', $output);
    }

    public function test_readiness_fails_on_classification_rule_checksum_mismatch(): void
    {
        $fixture = $this->makeReadinessFixture(classificationTargetSuffix: 'different');

        [$exitCode, $output] = $this->runCommand([
            '--readiness' => true,
            '--source' => $fixture['source'],
            '--dump-marker' => $fixture['dump'],
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('classification_rules_safe_checksum_match=no', $output);
        $this->assertStringContainsString('classification_rules source/target comparison mismatch.', $output);
        $this->assertStringNotContainsString('different', $output);
    }

    public function test_readiness_fails_when_classification_rules_source_present_target_zero(): void
    {
        $fixture = $this->makeReadinessFixture(classificationTargetRows: 0);

        [$exitCode, $output] = $this->runCommand([
            '--readiness' => true,
            '--source' => $fixture['source'],
            '--dump-marker' => $fixture['dump'],
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('classification_rules source has rows while target has none.', $output);
    }

    public function test_readiness_fails_when_classification_rules_target_present_source_zero(): void
    {
        $fixture = $this->makeReadinessFixture(classificationSourceRows: 0);

        [$exitCode, $output] = $this->runCommand([
            '--readiness' => true,
            '--source' => $fixture['source'],
            '--dump-marker' => $fixture['dump'],
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('classification_rules target has rows while source has none.', $output);
    }

    public function test_readiness_raw_evidence_duplicate_basename_without_unique_suffix_fails(): void
    {
        $fixture = $this->makeReadinessFixture(duplicateRawBasename: true);

        [$exitCode, $output] = $this->runCommand([
            '--readiness' => true,
            '--source' => $fixture['source'],
            '--dump-marker' => $fixture['dump'],
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('ambiguous_count=1', $output);
        $this->assertStringContainsString('Raw evidence path mapping readiness failed.', $output);
        $this->assertStringNotContainsString('evidence-one.csv', $output);
    }

    public function test_readiness_raw_evidence_missing_candidate_fails(): void
    {
        $fixture = $this->makeReadinessFixture(skipRawArchiveFile: true);

        [$exitCode, $output] = $this->runCommand([
            '--readiness' => true,
            '--source' => $fixture['source'],
            '--dump-marker' => $fixture['dump'],
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('unresolved_count=1', $output);
    }

    public function test_readiness_raw_hash_length_64_mismatch_warns_when_semantics_unverified(): void
    {
        $fixture = $this->makeReadinessFixture(rawHash: str_repeat('a', 64));

        [$exitCode, $output] = $this->runCommand([
            '--readiness' => true,
            '--source' => $fixture['source'],
            '--dump-marker' => $fixture['dump'],
        ]);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('raw_evidence_mapping_readiness=PASS_WITH_WARN', $output);
        $this->assertStringContainsString('raw_hash_length_64_count=1', $output);
        $this->assertStringContainsString('raw_hash_semantics=file_content_hash_unverified', $output);
        $this->assertStringContainsString('hash_validation_available=no', $output);
        $this->assertStringContainsString('hash_checked_count=not_applicable', $output);
        $this->assertStringContainsString('hash_mismatch_count=not_applicable', $output);
        $this->assertStringContainsString('hash_algorithm_detected=none', $output);
        $this->assertStringContainsString('sha256_matches=0', $output);
        $this->assertStringContainsString('sha1_matches=0', $output);
        $this->assertStringContainsString('md5_matches=0', $output);
        $this->assertStringContainsString('raw_hash_semantic_warning=yes', $output);
        $this->assertStringContainsString('Result: WARN', $output);
        $this->assertStringNotContainsString('file_content_sha256', $output);
    }

    public function test_readiness_raw_hash_blank_warns_when_mapping_is_complete(): void
    {
        $fixture = $this->makeReadinessFixture(rawHash: '');

        [$exitCode, $output] = $this->runCommand([
            '--readiness' => true,
            '--source' => $fixture['source'],
            '--dump-marker' => $fixture['dump'],
        ]);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('raw_evidence_mapping_readiness=PASS_WITH_WARN', $output);
        $this->assertStringContainsString('raw_hash_populated_count=0', $output);
        $this->assertStringContainsString('hash_validation_available=no', $output);
        $this->assertStringContainsString('Result: WARN', $output);
    }

    public function test_readiness_raw_hash_proven_sha256_mismatch_fails(): void
    {
        $fixture = $this->makeReadinessFixture(rawHash: str_repeat('a', 64));
        Config::set('inventory.mariadb_rehearsal.raw_hash_semantics', 'file_content_sha256');

        [$exitCode, $output] = $this->runCommand([
            '--readiness' => true,
            '--source' => $fixture['source'],
            '--dump-marker' => $fixture['dump'],
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('raw_evidence_mapping_readiness=FAIL', $output);
        $this->assertStringContainsString('raw_hash_semantics=file_content_sha256', $output);
        $this->assertStringContainsString('hash_validation_available=yes', $output);
        $this->assertStringContainsString('hash_checked_count=1', $output);
        $this->assertStringContainsString('hash_mismatch_count=1', $output);
        $this->assertStringContainsString('hash_algorithm_detected=sha256', $output);
    }

    public function test_readiness_raw_hash_proven_sha256_match_passes(): void
    {
        $fixture = $this->makeReadinessFixture(rawHash: hash('sha256', 'redacted'));
        Config::set('inventory.mariadb_rehearsal.raw_hash_semantics', 'file_content_sha256');

        [$exitCode, $output] = $this->runCommand([
            '--readiness' => true,
            '--source' => $fixture['source'],
            '--dump-marker' => $fixture['dump'],
        ]);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('raw_evidence_mapping_readiness=PASS', $output);
        $this->assertStringContainsString('raw_hash_semantics=file_content_sha256', $output);
        $this->assertStringContainsString('hash_validation_available=yes', $output);
        $this->assertStringContainsString('hash_checked_count=1', $output);
        $this->assertStringContainsString('hash_mismatch_count=0', $output);
        $this->assertStringContainsString('sha256_matches=1', $output);
        $this->assertStringContainsString('raw_hash_semantic_warning=no', $output);
    }

    public function test_readiness_raw_evidence_duplicate_basename_with_unique_suffix_resolves_with_warning(): void
    {
        $fixture = $this->makeReadinessFixture(duplicateRawBasename: true, rawSavedPath: 'a/evidence-one.csv');

        [$exitCode, $output] = $this->runCommand([
            '--readiness' => true,
            '--source' => $fixture['source'],
            '--dump-marker' => $fixture['dump'],
        ]);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('raw_evidence_mapping_readiness=PASS_WITH_WARN', $output);
        $this->assertStringContainsString('suffix_resolved_count=1', $output);
        $this->assertStringContainsString('ambiguous_count=0', $output);
        $this->assertStringContainsString('unresolved_count=0', $output);
    }

    public function test_readiness_raw_evidence_archive_root_missing_fails(): void
    {
        $fixture = $this->makeReadinessFixture();
        unlink($fixture['rawArchive'] . DIRECTORY_SEPARATOR . 'evidence-one.csv');
        rmdir($fixture['rawArchive']);

        [$exitCode, $output] = $this->runCommand([
            '--readiness' => true,
            '--source' => $fixture['source'],
            '--dump-marker' => $fixture['dump'],
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('raw_archive_root_exists=no', $output);
        $this->assertStringContainsString('raw_evidence_mapping_readiness=FAIL', $output);
    }

    public function test_readiness_raw_evidence_preserves_stored_saved_path_and_does_not_persist_resolution(): void
    {
        $fixture = $this->makeReadinessFixture(duplicateRawBasename: true, rawSavedPath: 'a/evidence-one.csv');
        $before = $this->rawFileSavedPath($fixture['source']);

        [$exitCode, $output] = $this->runCommand([
            '--readiness' => true,
            '--source' => $fixture['source'],
            '--dump-marker' => $fixture['dump'],
        ]);

        $after = $this->rawFileSavedPath($fixture['source']);

        $this->assertSame(0, $exitCode);
        $this->assertSame('a/evidence-one.csv', $before);
        $this->assertSame($before, $after);
        $this->assertStringContainsString('resolved_paths_stored=no', $output);
        $this->assertStringNotContainsString($fixture['rawArchive'], $output);
    }

    public function test_readiness_optional_table_present_in_source_missing_target_fails(): void
    {
        $fixture = $this->makeReadinessFixture(sourceSiteTokens: true);

        [$exitCode, $output] = $this->runCommand([
            '--readiness' => true,
            '--source' => $fixture['source'],
            '--dump-marker' => $fixture['dump'],
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Optional table present in source but missing in target: site_tokens', $output);
    }

    public function test_readiness_optional_table_absent_source_target_rows_fails(): void
    {
        $fixture = $this->makeReadinessFixture(targetSiteTokens: true);

        [$exitCode, $output] = $this->runCommand([
            '--readiness' => true,
            '--source' => $fixture['source'],
            '--dump-marker' => $fixture['dump'],
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Optional table absent in source but target has rows: site_tokens', $output);
    }

    public function test_readiness_known_missing_optional_columns_warn_and_skip(): void
    {
        $fixture = $this->makeReadinessFixture();

        [$exitCode, $output] = $this->runCommand([
            '--readiness' => true,
            '--source' => $fixture['source'],
            '--dump-marker' => $fixture['dump'],
        ]);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('known_optional_column=raw_files.device_scan_id present=yes action=validate_available', $output);
        $this->assertStringContainsString('known_optional_column=raw_files.device_id present=yes action=validate_available', $output);
        $this->assertStringContainsString('known_optional_column=storage_health_observations.raw_json present=no action=skip_known_optional_missing', $output);
    }

    public function test_readiness_missing_migrations_table_fails(): void
    {
        $fixture = $this->makeReadinessFixture();
        Schema::drop('migrations');

        [$exitCode, $output] = $this->runCommand([
            '--readiness' => true,
            '--source' => $fixture['source'],
            '--dump-marker' => $fixture['dump'],
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Required migrated target table missing: migrations', $output);
    }

    public function test_readiness_incomplete_migration_state_fails(): void
    {
        $fixture = $this->makeReadinessFixture();
        DB::table('migrations')->where('id', '>', 1)->delete();

        [$exitCode, $output] = $this->runCommand([
            '--readiness' => true,
            '--source' => $fixture['source'],
            '--dump-marker' => $fixture['dump'],
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Target migration state is incomplete for execute-readiness.', $output);
    }

    public function test_readiness_non_empty_restore_database_fails_without_writing_to_it(): void
    {
        $fixture = $this->makeReadinessFixture();
        Config::set('inventory.mariadb_rehearsal.restore_database_exists', true);
        Config::set('inventory.mariadb_rehearsal.restore_database_table_count', 1);

        [$exitCode, $output] = $this->runCommand([
            '--readiness' => true,
            '--source' => $fixture['source'],
            '--dump-marker' => $fixture['dump'],
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('inventory_rehearsal_restore is not empty.', $output);
    }

    public function test_readiness_does_not_change_target_row_counts(): void
    {
        $fixture = $this->makeReadinessFixture();
        $before = $this->targetSnapshotCounts();

        [$exitCode] = $this->runCommand([
            '--readiness' => true,
            '--source' => $fixture['source'],
            '--dump-marker' => $fixture['dump'],
        ]);

        $after = $this->targetSnapshotCounts();

        $this->assertSame(0, $exitCode);
        $this->assertSame($before, $after);
    }

    public function test_execute_conflicts_with_dry_run_and_readiness(): void
    {
        $fixture = $this->makeReadinessFixture();

        [$dryRunExit, $dryRunOutput] = $this->runCommand($this->executeParameters($fixture, ['--dry-run' => true]));
        [$readinessExit, $readinessOutput] = $this->runCommand($this->executeParameters($fixture, ['--readiness' => true]));

        $this->assertSame(1, $dryRunExit);
        $this->assertSame(1, $readinessExit);
        $this->assertStringContainsString('Exactly one command mode is required', $dryRunOutput);
        $this->assertStringContainsString('Exactly one command mode is required', $readinessOutput);
    }

    public function test_execute_without_source_fails(): void
    {
        $fixture = $this->makeReadinessFixture();

        [$exitCode, $output] = $this->runCommand($this->executeParameters($fixture, ['--source' => null]));

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('--source is required and must be explicit.', $output);
        $this->assertSame(0, DB::table('devices')->count());
    }

    public function test_execute_requires_dump_marker_and_each_confirmation(): void
    {
        $fixture = $this->makeReadinessFixture();

        [$missingDumpExit, $missingDumpOutput] = $this->runCommand($this->executeParameters($fixture, ['--dump-marker' => null]));
        $this->assertSame(1, $missingDumpExit);
        $this->assertStringContainsString('--dump-marker is required with --execute.', $missingDumpOutput);

        foreach ($this->executeConfirmationFlags() as $flag) {
            [$exitCode, $output] = $this->runCommand($this->executeParameters($fixture, ['--' . $flag => false]));
            $this->assertSame(1, $exitCode);
            $this->assertStringContainsString("--{$flag} is required with --execute.", $output);
        }
    }

    public function test_execute_requires_raw_hash_warning_confirmation_when_warning_present(): void
    {
        $fixture = $this->makeReadinessFixture();

        [$exitCode, $output] = $this->runCommand($this->executeParameters($fixture, [
            '--confirm-known-warn-raw-hash-unverified' => false,
        ]));

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('--confirm-known-warn-raw-hash-unverified is required with --execute.', $output);
        $this->assertSame(0, DB::table('devices')->count());
    }

    public function test_execute_imports_approved_tables_and_preserves_target_owned_tables(): void
    {
        $fixture = $this->makeReadinessFixture();
        $beforeMigrations = DB::table('migrations')->count();
        $beforeClassification = DB::table('classification_rules')->pluck('pattern', 'id')->all();

        [$exitCode, $output] = $this->runCommand($this->executeParameters($fixture));

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('phase=19M_execute_import', $output);
        $this->assertStringContainsString('command_mode=execute', $output);
        $this->assertStringContainsString('writes_performed=yes', $output);
        $this->assertStringContainsString('restore_database_written=no', $output);
        $this->assertStringContainsString('reset_performed=no', $output);
        $this->assertStringContainsString('truncate_performed=no', $output);
        $this->assertStringContainsString('classification_rules_action=preserve_target_skip_import', $output);
        $this->assertStringContainsString('raw_paths_preserved=yes', $output);
        $this->assertStringContainsString('raw_evidence_mapping_readiness=PASS_WITH_WARN', $output);
        $this->assertStringContainsString('hash_validation_available=no', $output);
        $this->assertStringContainsString('primary_key_preservation=PASS', $output);
        $this->assertStringContainsString('relationship_validation=PASS', $output);
        $this->assertStringContainsString('json_text_validation=PASS', $output);
        $this->assertStringContainsString('command_lifecycle_validation=PASS', $output);
        $this->assertStringContainsString('assignment_override_validation=PASS', $output);
        $this->assertStringContainsString('raw_evidence_validation=PASS_WITH_WARN', $output);
        $this->assertStringContainsString('auto_increment_validation=PASS', $output);
        $this->assertStringContainsString('restore_db_untouched=PASS', $output);
        $this->assertStringContainsString('secrets_printed=no', $output);
        $this->assertStringContainsString('raw_filenames_printed=no', $output);
        $this->assertStringContainsString('raw_paths_printed=no', $output);
        $this->assertStringContainsString('raw_contents_printed=no', $output);
        $this->assertStringContainsString('command_payload_json_printed=no', $output);
        $this->assertStringContainsString('full_runner_guids_printed=no', $output);
        $this->assertStringContainsString('Result: WARN', $output);

        foreach ($this->approvedImportTables() as $table) {
            $this->assertSame(1, DB::table($table)->count(), $table);
        }
        $this->assertSame($beforeMigrations, DB::table('migrations')->count());
        $this->assertSame($beforeClassification, DB::table('classification_rules')->pluck('pattern', 'id')->all());
        $this->assertSame('archive/evidence-one.csv', DB::table('raw_files')->where('id', 1)->value('saved_path'));
        $this->assertSame(0, DB::table('personal_access_tokens')->count());
        $this->assertStringNotContainsString('evidence-one.csv', $output);
        $this->assertStringNotContainsString($fixture['rawArchive'], $output);
        $this->assertStringNotContainsString('{}', $output);
        $this->assertStringNotContainsString('RUNNER-ONE', $output);
    }

    public function test_execute_dirty_target_fails_without_cleanup(): void
    {
        $fixture = $this->makeReadinessFixture();
        DB::table('devices')->insert(['id' => 99]);

        [$exitCode, $output] = $this->runCommand($this->executeParameters($fixture));

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Target application/domain table is not empty: devices', $output);
        $this->assertStringContainsString('writes_performed=no', $output);
        $this->assertSame(1, DB::table('devices')->count());
        $this->assertSame(99, DB::table('devices')->value('id'));
    }

    public function test_execute_pre_gates_fail_before_write(): void
    {
        $fixture = $this->makeReadinessFixture(classificationTargetSuffix: 'different');

        [$exitCode, $output] = $this->runCommand($this->executeParameters($fixture));

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('classification_rules source/target comparison mismatch.', $output);
        $this->assertSame(0, DB::table('devices')->count());
    }

    public function test_execute_rolls_back_on_validation_failure(): void
    {
        $fixture = $this->makeReadinessFixture();
        Config::set('inventory.mariadb_rehearsal.force_execute_validation_failure', 'count');

        [$exitCode, $output] = $this->runCommand($this->executeParameters($fixture));

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('transaction_rolled_back=yes', $output);
        $this->assertStringContainsString('execute_result=FAIL', $output);
        $this->assertStringContainsString('failed_import_table=none', $output);
        $this->assertStringContainsString('failed_stage=count_validation', $output);
        $this->assertStringContainsString('rollback_reason_code=count_validation_failed', $output);
        $this->assertStringContainsString('transaction_committed=no', $output);
        $this->assertStringContainsString('Phase 19M execute import rolled back before commit.', $output);
        foreach ($this->approvedImportTables() as $table) {
            $this->assertSame(0, DB::table($table)->count(), $table);
        }
    }

    public function test_execute_rolls_back_on_json_validation_failure(): void
    {
        $fixture = $this->makeReadinessFixture(rawMetadataJson: '{bad-json');

        [$exitCode, $output] = $this->runCommand($this->executeParameters($fixture));

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('json_text_validation=FAIL', $output);
        $this->assertStringContainsString('transaction_rolled_back=yes', $output);
        $this->assertStringContainsString('failed_stage=json_validation', $output);
        $this->assertStringContainsString('rollback_reason_code=invalid_json', $output);
        $this->assertSame(0, DB::table('raw_files')->count());
    }

    public function test_execute_diagnostics_for_first_table_insert_failure_are_redacted(): void
    {
        $fixture = $this->makeReadinessFixture();
        Config::set('inventory.mariadb_rehearsal.force_execute_insert_failure_table', 'users');

        [$exitCode, $output] = $this->runCommand($this->executeParameters($fixture));

        $this->assertSame(1, $exitCode);
        $this->assertRollbackDiagnostics($output, 'users', 'insert_rows', 'insert_failed');
        $this->assertNoPartialImportedRows();
        $this->assertRedactedExecuteFailureOutput($output, $fixture);
    }

    public function test_execute_diagnostics_for_collector_sites_failure_after_users_are_redacted(): void
    {
        $fixture = $this->makeReadinessFixture();
        Config::set('inventory.mariadb_rehearsal.force_execute_insert_failure_table', 'collector_sites');
        Config::set('inventory.mariadb_rehearsal.force_execute_insert_failure_reason', 'duplicate_key');

        [$exitCode, $output] = $this->runCommand($this->executeParameters($fixture));

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('import_table=users source_count=1 target_count=1', $output);
        $this->assertRollbackDiagnostics($output, 'collector_sites', 'insert_rows', 'duplicate_key');
        $this->assertSame(0, DB::table('users')->count());
        $this->assertNoPartialImportedRows();
        $this->assertRedactedExecuteFailureOutput($output, $fixture);
    }

    public function test_execute_foreign_key_insert_failure_reason_is_safe(): void
    {
        $fixture = $this->makeReadinessFixture();
        Config::set('inventory.mariadb_rehearsal.force_execute_insert_failure_table', 'devices');
        Config::set('inventory.mariadb_rehearsal.force_execute_insert_failure_reason', 'foreign_key_violation');

        [$exitCode, $output] = $this->runCommand($this->executeParameters($fixture));

        $this->assertSame(1, $exitCode);
        $this->assertRollbackDiagnostics($output, 'devices', 'insert_rows', 'foreign_key_violation');
        $this->assertNoPartialImportedRows();
    }

    public function test_execute_schema_mapping_failure_after_users_is_classified(): void
    {
        $fixture = $this->makeReadinessFixture(sourceCollectorSiteIdColumn: false);

        [$exitCode, $output] = $this->runCommand($this->executeParameters($fixture));

        $this->assertSame(1, $exitCode);
        $this->assertRollbackDiagnostics($output, 'collector_sites', 'schema_map', 'schema_mapping_failed');
        $this->assertNoPartialImportedRows();
    }

    public function test_execute_not_null_violation_is_classified_without_values(): void
    {
        $fixture = $this->makeReadinessFixture();
        Schema::table('collector_sites', function ($blueprint): void {
            $blueprint->string('required_name');
        });

        [$exitCode, $output] = $this->runCommand($this->executeParameters($fixture));

        $this->assertSame(1, $exitCode);
        $this->assertRollbackDiagnostics($output, 'collector_sites', 'insert_rows', 'not_null_violation');
        $this->assertRedactedExecuteFailureOutput($output, $fixture);
    }

    public function test_execute_relationship_validation_failure_rolls_back_with_reason(): void
    {
        $fixture = $this->makeReadinessFixture(invalidCollectorSiteLink: true);

        [$exitCode, $output] = $this->runCommand($this->executeParameters($fixture));

        $this->assertSame(1, $exitCode);
        $this->assertRollbackDiagnostics($output, 'none', 'relationship_validation', 'relationship_validation_failed');
        $this->assertNoPartialImportedRows();
    }

    public function test_execute_unexpected_exception_is_classified_without_message(): void
    {
        $fixture = $this->makeReadinessFixture();
        Config::set('inventory.mariadb_rehearsal.force_execute_validation_failure', 'unexpected');

        [$exitCode, $output] = $this->runCommand($this->executeParameters($fixture));

        $this->assertSame(1, $exitCode);
        $this->assertRollbackDiagnostics($output, 'none', 'post_commit_validation', 'unexpected_exception');
        $this->assertStringNotContainsString('forced unexpected validation failure', $output);
        $this->assertNoPartialImportedRows();
    }

    private function setRehearsalBoundaryConfig(): void
    {
        $this->app->setBasePath('D:\\inventory-rehearsal\\laravel');
        Config::set('database.default', 'mysql');
        Config::set('database.connections.mysql.database', 'inventory_rehearsal');
        Config::set('app.url', 'http://127.0.0.1:8090');
    }

    /**
     * @return array{root: string, source: string, rawArchive: string, downloads: string, dumpDir: string, dump: string}
     */
    private function makeReadinessFixture(
        int $classificationSourceRows = 8,
        int $classificationTargetRows = 8,
        string $classificationTargetSuffix = '',
        bool $duplicateRawBasename = false,
        bool $skipRawArchiveFile = false,
        ?string $rawHash = null,
        string $rawSavedPath = 'archive/evidence-one.csv',
        string $rawMetadataJson = '{}',
        bool $sourceSiteTokens = false,
        bool $targetSiteTokens = false,
        bool $sourceCollectorSiteIdColumn = true,
        bool $invalidCollectorSiteLink = false,
    ): array {
        $root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'phase19l_' . bin2hex(random_bytes(6));
        $source = $root . DIRECTORY_SEPARATOR . 'source-copy' . DIRECTORY_SEPARATOR . 'database.sqlite';
        $rawArchive = $root . DIRECTORY_SEPARATOR . 'raw_archive';
        $downloads = $root . DIRECTORY_SEPARATOR . 'downloads';
        $dumpDir = $root . DIRECTORY_SEPARATOR . 'backups' . DIRECTORY_SEPARATOR . 'mariadb_dumps';
        $dump = $dumpDir . DIRECTORY_SEPARATOR . 'empty-schema.marker';

        mkdir(dirname($source), 0777, true);
        mkdir($rawArchive, 0777, true);
        mkdir($downloads, 0777, true);
        mkdir($dumpDir, 0777, true);
        file_put_contents($dump, 'non-secret dump marker');
        if (! $skipRawArchiveFile) {
            file_put_contents($rawArchive . DIRECTORY_SEPARATOR . 'evidence-one.csv', 'redacted');
        }
        if ($duplicateRawBasename) {
            mkdir($rawArchive . DIRECTORY_SEPARATOR . 'a', 0777, true);
            mkdir($rawArchive . DIRECTORY_SEPARATOR . 'b', 0777, true);
            file_put_contents($rawArchive . DIRECTORY_SEPARATOR . 'a' . DIRECTORY_SEPARATOR . 'evidence-one.csv', 'redacted-a');
            file_put_contents($rawArchive . DIRECTORY_SEPARATOR . 'b' . DIRECTORY_SEPARATOR . 'evidence-one.csv', 'redacted-b');
        }

        $this->app->setBasePath($root . DIRECTORY_SEPARATOR . 'laravel');
        Config::set('inventory.mariadb_rehearsal.expected_base_path', $root . DIRECTORY_SEPARATOR . 'laravel');
        Config::set('inventory.mariadb_rehearsal.expected_source_path', $source);
        Config::set('inventory.mariadb_rehearsal.raw_archive_path', $rawArchive);
        Config::set('inventory.mariadb_rehearsal.downloads_path', $downloads);
        Config::set('inventory.mariadb_rehearsal.dump_directory', $dumpDir);
        Config::set('inventory.mariadb_rehearsal.target_database', ':memory:');
        Config::set('database.default', 'mysql');
        Config::set('database.connections.mysql', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => false,
        ]);
        Config::set('app.url', 'http://127.0.0.1:8090');
        DB::purge('mysql');
        DB::reconnect('mysql');

        $this->createSourceSqlite($source, $classificationSourceRows, $rawHash, $rawSavedPath, $rawMetadataJson, $sourceSiteTokens, $sourceCollectorSiteIdColumn, $invalidCollectorSiteLink);
        $this->createTargetSchema($classificationTargetRows, $classificationTargetSuffix, $targetSiteTokens);

        return compact('root', 'source', 'rawArchive', 'downloads', 'dumpDir', 'dump');
    }

    private function createSourceSqlite(string $source, int $classificationRows, ?string $rawHash, string $rawSavedPath, string $rawMetadataJson, bool $sourceSiteTokens, bool $sourceCollectorSiteIdColumn, bool $invalidCollectorSiteLink): void
    {
        $pdo = new PDO('sqlite:' . $source);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        foreach ([
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
        ] as $table) {
            $pdo->exec("CREATE TABLE {$table} (id INTEGER PRIMARY KEY)");
        }

        $pdo->exec('ALTER TABLE device_identities ADD COLUMN device_id INTEGER');
        $pdo->exec('ALTER TABLE device_scans ADD COLUMN device_id INTEGER');
        $pdo->exec('ALTER TABLE hardware_snapshots ADD COLUMN device_scan_id INTEGER');
        $pdo->exec('ALTER TABLE hardware_snapshots ADD COLUMN snapshot_json TEXT');
        $pdo->exec('ALTER TABLE storage_health_observations ADD COLUMN device_id INTEGER');
        $pdo->exec('ALTER TABLE storage_health_observations ADD COLUMN device_scan_id INTEGER');
        $pdo->exec('ALTER TABLE storage_health_observations ADD COLUMN risk_level TEXT');
        $pdo->exec('ALTER TABLE storage_health_observations ADD COLUMN risk_reasons TEXT');
        $pdo->exec('ALTER TABLE network_observations ADD COLUMN device_scan_id INTEGER');
        $pdo->exec('ALTER TABLE peripherals ADD COLUMN device_scan_id INTEGER');
        $pdo->exec('ALTER TABLE device_assignments ADD COLUMN device_id INTEGER');
        $pdo->exec('ALTER TABLE device_assignments ADD COLUMN department TEXT');
        $pdo->exec('ALTER TABLE device_assignments ADD COLUMN location TEXT');
        $pdo->exec('ALTER TABLE device_assignments ADD COLUMN room TEXT');
        $pdo->exec('ALTER TABLE change_log ADD COLUMN device_id INTEGER');
        $pdo->exec('ALTER TABLE raw_files ADD COLUMN saved_path TEXT');
        $pdo->exec('ALTER TABLE raw_files ADD COLUMN raw_hash TEXT');
        $pdo->exec('ALTER TABLE raw_files ADD COLUMN metadata_json TEXT');
        $pdo->exec('ALTER TABLE raw_files ADD COLUMN device_scan_id INTEGER');
        $pdo->exec('ALTER TABLE raw_files ADD COLUMN device_id INTEGER');
        $pdo->exec('ALTER TABLE collectors ADD COLUMN site_id TEXT');
        $pdo->exec('ALTER TABLE collectors ADD COLUMN raw_status_json TEXT');
        $pdo->exec('ALTER TABLE runners ADD COLUMN site_id TEXT');
        $pdo->exec('ALTER TABLE runners ADD COLUMN runner_id TEXT');
        $pdo->exec('ALTER TABLE runners ADD COLUMN transport_mode TEXT');
        $pdo->exec('ALTER TABLE runners ADD COLUMN raw_state_json TEXT');
        $pdo->exec('ALTER TABLE runner_commands ADD COLUMN runner_id TEXT');
        $pdo->exec('ALTER TABLE runner_commands ADD COLUMN status TEXT');
        $pdo->exec('ALTER TABLE runner_commands ADD COLUMN acknowledged_at TEXT');
        $pdo->exec('ALTER TABLE runner_commands ADD COLUMN result_upload_id INTEGER');
        $pdo->exec('ALTER TABLE runner_commands ADD COLUMN payload_json TEXT');
        if ($sourceCollectorSiteIdColumn) {
            $pdo->exec('ALTER TABLE collector_sites ADD COLUMN site_id TEXT');
        }

        $pdo->exec("INSERT INTO users (id) VALUES (1)");
        $collectorSite = $invalidCollectorSiteLink ? 'SITE-OTHER' : 'SITE-HQ';
        if ($sourceCollectorSiteIdColumn) {
            $pdo->exec("INSERT INTO collector_sites (id, site_id) VALUES (1, '{$collectorSite}')");
        } else {
            $pdo->exec('INSERT INTO collector_sites (id) VALUES (1)');
        }
        $pdo->exec("INSERT INTO devices (id) VALUES (1)");
        $pdo->exec("INSERT INTO device_identities (id, device_id) VALUES (1, 1)");
        $pdo->exec("INSERT INTO device_scans (id, device_id) VALUES (1, 1)");
        $pdo->exec("INSERT INTO hardware_snapshots (id, device_scan_id, snapshot_json) VALUES (1, 1, '{}')");
        $pdo->exec("INSERT INTO storage_health_observations (id, device_id, device_scan_id, risk_level, risk_reasons) VALUES (1, 1, 1, 'low', '[]')");
        $pdo->exec("INSERT INTO network_observations (id, device_scan_id) VALUES (1, 1)");
        $pdo->exec("INSERT INTO peripherals (id, device_scan_id) VALUES (1, 1)");
        $pdo->exec("INSERT INTO device_assignments (id, device_id, department, location, room) VALUES (1, 1, 'IT', 'HQ', '101')");
        $pdo->exec("INSERT INTO change_log (id, device_id) VALUES (1, 1)");
        $stmt = $pdo->prepare('INSERT INTO raw_files (id, saved_path, raw_hash, metadata_json, device_scan_id, device_id) VALUES (1, ?, ?, ?, 1, 1)');
        $stmt->execute([$rawSavedPath, $rawHash ?? '', $rawMetadataJson]);
        $pdo->exec("INSERT INTO collectors (id, site_id, raw_status_json) VALUES (1, 'SITE-HQ', '{}')");
        $pdo->exec("INSERT INTO runners (id, site_id, runner_id, transport_mode, raw_state_json) VALUES (1, 'SITE-HQ', 'RUNNER-ONE', 'direct_https', '{}')");
        $pdo->exec("INSERT INTO runner_commands (id, runner_id, status, acknowledged_at, result_upload_id, payload_json) VALUES (1, 'RUNNER-ONE', 'succeeded', '2026-05-10 00:00:00', 1, '{}')");

        $pdo->exec('CREATE TABLE classification_rules (id INTEGER PRIMARY KEY, key TEXT, pattern TEXT, created_at TEXT, updated_at TEXT)');
        for ($i = 1; $i <= $classificationRows; $i++) {
            $stmt = $pdo->prepare('INSERT INTO classification_rules (id, key, pattern, created_at, updated_at) VALUES (?, ?, ?, ?, ?)');
            $stmt->execute([$i, 'rule-' . $i, 'class-' . $i, '2026-05-10', '2026-05-10']);
        }

        if ($sourceSiteTokens) {
            $pdo->exec('CREATE TABLE site_tokens (id INTEGER PRIMARY KEY, token_hash TEXT)');
            $pdo->exec("INSERT INTO site_tokens (id, token_hash) VALUES (1, 'redacted')");
        }
    }

    private function createTargetSchema(int $classificationRows, string $classificationSuffix, bool $targetSiteTokens): void
    {
        Schema::dropAllTables();
        foreach ([
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
            'raw_files',
            'change_log',
            'collectors',
            'runners',
            'runner_commands',
            'personal_access_tokens',
            'password_reset_tokens',
            'jobs',
            'failed_jobs',
            'cache',
            'sessions',
        ] as $table) {
            Schema::create($table, function ($blueprint): void {
                $blueprint->integer('id')->primary();
            });
        }

        Schema::create('collector_sites', function ($blueprint): void {
            $blueprint->string('site_id')->primary();
            $blueprint->string('site_name')->nullable();
            $blueprint->text('description')->nullable();
        });

        Schema::table('device_identities', function ($blueprint): void {
            $blueprint->integer('device_id')->nullable();
        });
        Schema::table('device_scans', function ($blueprint): void {
            $blueprint->integer('device_id')->nullable();
        });
        Schema::table('hardware_snapshots', function ($blueprint): void {
            $blueprint->integer('device_scan_id')->nullable();
            $blueprint->text('snapshot_json')->nullable();
        });
        Schema::table('storage_health_observations', function ($blueprint): void {
            $blueprint->integer('device_id')->nullable();
            $blueprint->integer('device_scan_id')->nullable();
            $blueprint->string('risk_level')->nullable();
            $blueprint->text('risk_reasons')->nullable();
            $blueprint->text('raw_json')->nullable();
        });
        Schema::table('network_observations', function ($blueprint): void {
            $blueprint->integer('device_scan_id')->nullable();
        });
        Schema::table('peripherals', function ($blueprint): void {
            $blueprint->integer('device_scan_id')->nullable();
        });
        Schema::table('device_assignments', function ($blueprint): void {
            $blueprint->integer('device_id')->nullable();
            $blueprint->string('department')->nullable();
            $blueprint->string('location')->nullable();
            $blueprint->string('room')->nullable();
        });
        Schema::table('change_log', function ($blueprint): void {
            $blueprint->integer('device_id')->nullable();
        });
        Schema::table('raw_files', function ($blueprint): void {
            $blueprint->string('saved_path')->nullable();
            $blueprint->string('raw_hash')->nullable();
            $blueprint->text('metadata_json')->nullable();
            $blueprint->integer('device_scan_id')->nullable();
            $blueprint->integer('device_id')->nullable();
        });
        Schema::table('collectors', function ($blueprint): void {
            $blueprint->string('site_id')->nullable();
            $blueprint->text('raw_status_json')->nullable();
        });
        Schema::table('runners', function ($blueprint): void {
            $blueprint->string('site_id')->nullable();
            $blueprint->string('runner_id')->nullable();
            $blueprint->string('transport_mode')->nullable();
            $blueprint->text('raw_state_json')->nullable();
        });
        Schema::table('runner_commands', function ($blueprint): void {
            $blueprint->string('runner_id')->nullable();
            $blueprint->string('status')->nullable();
            $blueprint->timestamp('acknowledged_at')->nullable();
            $blueprint->integer('result_upload_id')->nullable();
            $blueprint->text('payload_json')->nullable();
        });
        Schema::create('classification_rules', function ($blueprint): void {
            $blueprint->integer('id')->primary();
            $blueprint->string('key')->nullable();
            $blueprint->string('pattern')->nullable();
            $blueprint->timestamp('created_at')->nullable();
            $blueprint->timestamp('updated_at')->nullable();
        });

        for ($i = 1; $i <= 18; $i++) {
            DB::table('migrations')->insert(['id' => $i]);
        }
        for ($i = 1; $i <= $classificationRows; $i++) {
            DB::table('classification_rules')->insert([
                'id' => $i,
                'key' => 'rule-' . $i,
                'pattern' => 'class-' . $i . $classificationSuffix,
                'created_at' => '2026-05-10',
                'updated_at' => '2026-05-10',
            ]);
        }

        if ($targetSiteTokens) {
            Schema::create('site_tokens', function ($blueprint): void {
                $blueprint->integer('id')->primary();
                $blueprint->string('token_hash')->nullable();
            });
            DB::table('site_tokens')->insert(['id' => 1, 'token_hash' => 'redacted']);
        }
    }

    /** @return array<string, int> */
    private function targetSnapshotCounts(): array
    {
        $tables = [
            'users',
            'devices',
            'device_scans',
            'raw_files',
            'runner_commands',
            'classification_rules',
            'migrations',
        ];

        $counts = [];
        foreach ($tables as $table) {
            $counts[$table] = DB::table($table)->count();
        }

        return $counts;
    }

    private function rawFileSavedPath(string $source): string
    {
        $pdo = new PDO('sqlite:' . $source);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        return (string) $pdo->query('SELECT saved_path FROM raw_files WHERE id = 1')->fetchColumn();
    }

    private function assertRollbackDiagnostics(string $output, string $table, string $stage, string $reason): void
    {
        $this->assertStringContainsString('execute_result=FAIL', $output);
        $this->assertStringContainsString('first_failure_detected=yes', $output);
        $this->assertStringContainsString('transaction_started=yes', $output);
        $this->assertStringContainsString('transaction_committed=no', $output);
        $this->assertStringContainsString('transaction_rolled_back=yes', $output);
        $this->assertStringContainsString("failed_import_table={$table}", $output);
        $this->assertStringContainsString("failed_stage={$stage}", $output);
        $this->assertStringContainsString("rollback_reason_code={$reason}", $output);
        $this->assertMatchesRegularExpression('/exception_class=(redacted|none|[A-Za-z0-9_\\\\]+)/', $output);
        $this->assertMatchesRegularExpression('/sqlstate=(redacted|none|[A-Z0-9]{5})/', $output);
        $this->assertMatchesRegularExpression('/sql_error_category=(none|not_sql|[a-z_]+)/', $output);
        $this->assertStringContainsString('sql_message_printed=no', $output);
        $this->assertStringContainsString('sql_query_printed=no', $output);
        $this->assertStringContainsString('bindings_printed=no', $output);
        $this->assertStringContainsString('row_values_printed=no', $output);
        $this->assertStringContainsString('raw_contents_printed=no', $output);
        $this->assertStringContainsString('secrets_printed=no', $output);
        $this->assertStringContainsString('target_empty_state_after_failure=PASS', $output);
        $this->assertStringContainsString('writes_performed=no', $output);
    }

    private function assertNoPartialImportedRows(): void
    {
        foreach ($this->approvedImportTables() as $table) {
            $this->assertSame(0, DB::table($table)->count(), $table);
        }
        $this->assertSame(18, DB::table('migrations')->count());
        $this->assertSame(8, DB::table('classification_rules')->count());
    }

    private function assertRedactedExecuteFailureOutput(string $output, array $fixture): void
    {
        $this->assertStringNotContainsString('select ', strtolower($output));
        $this->assertStringNotContainsString('insert into', strtolower($output));
        $this->assertStringNotContainsString('bindings', strtolower(str_replace('bindings_printed=no', '', $output)));
        $this->assertStringNotContainsString('SITE-HQ', $output);
        $this->assertStringNotContainsString('RUNNER-ONE', $output);
        $this->assertStringNotContainsString('evidence-one.csv', $output);
        $this->assertStringNotContainsString($fixture['rawArchive'], $output);
        $this->assertStringNotContainsString('redacted-a', $output);
        $this->assertStringNotContainsString('redacted-b', $output);
        $this->assertStringNotContainsString('payload_json":"', $output);
        $this->assertStringNotContainsString('token_hash', $output);
        $this->assertStringNotContainsString('fake-db-password-secret-value', $output);
    }

    /** @return list<string> */
    private function executeConfirmationFlags(): array
    {
        return [
            'confirm-rehearsal-target',
            'confirm-empty-target',
            'confirm-dump-created',
            'confirm-no-reset',
            'confirm-classification-rules-preserved',
            'confirm-raw-paths-preserved',
            'confirm-known-warn-raw-hash-unverified',
        ];
    }

    /** @return list<string> */
    private function approvedImportTables(): array
    {
        return [
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
    }

    private function executeParameters(array $fixture, array $overrides = []): array
    {
        $parameters = [
            '--execute' => true,
            '--source' => $fixture['source'],
            '--dump-marker' => $fixture['dump'],
        ];

        foreach ($this->executeConfirmationFlags() as $flag) {
            $parameters['--' . $flag] = true;
        }

        return array_merge($parameters, $overrides);
    }

    private function runCommand(array $parameters): array
    {
        $this->withoutMockingConsoleOutput();

        $buffer = new BufferedOutput();
        $exitCode = Artisan::call('inventory:mariadb-rehearsal-transfer', $parameters, $buffer);

        return [$exitCode, $buffer->fetch()];
    }
}
