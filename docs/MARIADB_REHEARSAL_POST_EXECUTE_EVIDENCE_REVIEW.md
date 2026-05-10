# Phase 19N - MariaDB Rehearsal Post-execute Evidence Review

## 1. Purpose

Phase 19N is read-only validation and evidence review after the Phase 19M.2 controlled execute import into the MariaDB rehearsal database.

Phase 19N does not approve restore rehearsal, production migration, or cutover. Phase 19O restore rehearsal design may be discussed only after Phase 19N evidence is accepted.

Scope:

- Review count-only, redacted evidence from `inventory_rehearsal`.
- Confirm imported data is internally consistent.
- Confirm the rehearsal Laravel app can boot and run approved read-only operational commands.
- Preserve the accepted raw evidence warning for unverified `raw_hash` semantics.
- Do not rerun `--execute`.
- Do not mutate `inventory_rehearsal` or `inventory_rehearsal_restore`.

## 2. Phase 19M.2 Execute Summary

Recorded Phase 19M.2 result:

```text
command_mode=execute
confirmations_complete=yes
source_exact_match=yes
target_database=inventory_rehearsal
restore_database_written=no
reset_performed=no
truncate_performed=no
dump_created_by_command=no
classification_rules_action=preserve_target_skip_import
raw_paths_preserved=yes
raw_evidence_mapping_readiness=PASS_WITH_WARN
hash_validation_available=no
writes_performed=yes
primary_key_preservation=PASS
relationship_validation=PASS
json_text_validation=PASS
command_lifecycle_validation=PASS
assignment_override_validation=PASS
raw_evidence_validation=PASS_WITH_WARN
auto_increment_validation=PASS
restore_db_untouched=PASS
secrets_printed=no
raw_filenames_printed=no
raw_paths_printed=no
raw_contents_printed=no
command_payload_json_printed=no
full_runner_guids_printed=no
Result: WARN
```

Known accepted warning:

```text
raw_hash_semantics=file_content_hash_unverified
hash_validation_available=no
raw_evidence_validation=PASS_WITH_WARN
suffix_resolved_count=353
ambiguous_count=0
unresolved_count=0
```

This warning is accepted only because raw evidence mapping resolved completely and unambiguously. A 64-character `raw_hash` value is not treated as proof of file-content SHA-256.

## 3. Imported Count Baseline

Required count-only baseline:

```text
users=1
collector_sites=1
devices=37
device_identities=160
device_scans=353
hardware_snapshots=353
storage_health_observations=694
network_observations=353
peripherals=5501
device_assignments=86
raw_files=353
change_log=1337
collectors=3
runners=38
runner_commands=113
migrations_count=18
classification_rules_count=8
restore_db_table_count=0
restore_db_row_count=0
```

Any unexplained count mismatch is a stop condition.

## 4. Approved Supermicro Commands

Run commands only from the rehearsal Laravel path:

```powershell
cd D:\inventory-rehearsal\laravel
```

Approved basic boundary commands:

```powershell
git status --short
git rev-parse HEAD
php artisan --version
php artisan migrate:status
```

Approved Laravel operational commands:

```powershell
php artisan inventory:doctor
php artisan inventory:doctor --production
php artisan inventory:production-readiness
php artisan inventory:install-preflight
php artisan inventory:direct-pilot-status
php artisan inventory:direct-runner-triage IT-ADMIN
php artisan inventory:direct-runner-triage LAPTOP-I76TA97E
```

Do not run Laravel tests against `inventory_rehearsal` unless test database isolation is proven and documented:

```text
php_artisan_test=deferred_unless_test_db_isolated
```

## 5. Required DB Evidence

Evidence must be count-only and redacted. Use read-only SQL or an approved read-only admin database client. Do not print SQL text in the final evidence packet if it contains table values, bindings, row values, command payload JSON, token material, raw filenames, full paths, or raw contents.

Required subset counts:

```text
direct_https_runner_count=<count>
collector_share_runner_count=<count>
collector_count=<count>
pending_command_count=<count>
delivered_or_dispatched_awaiting_ack_count=<count>
succeeded_command_count=<count>
failed_command_count=<count>
latest_scan_per_device_count=<count>
devices_with_latest_snapshot_count=<count>
raw_files_saved_path_populated_count=<count>
raw_evidence_suffix_resolved_count=353
raw_evidence_ambiguous_count=0
raw_evidence_unresolved_count=0
storage_health_risk_level_counts=<count-only summary>
assignment_override_count=<count>
assignment_department_populated_count=<count>
assignment_site_populated_count=<count>
assignment_location_populated_count=<count>
assignment_room_populated_count=<count>
runner_guid_present_count=<count>
runner_commands_result_upload_id_populated_count=<count>
```

Runner GUID values must not be printed. Only counts are allowed.

## 6. Relationship Validation Evidence

Required relationship checks must be reported as pass/fail and orphan counts only:

- `device_identities.device_id -> devices.id`
- `device_scans.device_id -> devices.id`
- `hardware_snapshots.device_scan_id -> device_scans.id`
- `storage_health_observations.device_id -> devices.id`
- `storage_health_observations.device_scan_id -> device_scans.id`
- `network_observations.device_scan_id -> device_scans.id`
- `peripherals.device_scan_id -> device_scans.id`
- `device_assignments.device_id -> devices.id`
- `change_log.device_id -> devices.id` where applicable
- `collectors.site_id -> collector_sites.site_id`
- `runners.site_id -> collector_sites.site_id` where applicable
- `runners.collector_id -> collectors.id` where applicable
- `runner_commands.runner_id -> runners.runner_id` or the configured runner key
- latest scan links resolve
- latest hardware snapshot links resolve

Expected summary shape:

```text
relationship_validation=PASS
relationship_orphan_count=0
latest_scan_resolution=PASS
latest_snapshot_resolution=PASS
```

## 7. Command Lifecycle Evidence

Command lifecycle evidence must remain count-only:

```text
runner_commands_count=113
queued_count=<count>
delivered_or_dispatched_count=<count>
acknowledged_count=<count>
completed_count=<count>
succeeded_count=<count>
failed_count=<count>
payload_json_decodable_count=<count>
payload_json_printed=no
result_upload_id_populated_count=<count>
```

Command semantics remain unchanged:

- Commands are asynchronous.
- Polling means delivery.
- ACK is the execution result.
- Phase 19N must not change command lifecycle state.

## 8. Raw Evidence Evidence

Accepted raw evidence status:

```text
raw_evidence_validation=PASS_WITH_WARN
raw_paths_preserved=yes
raw_hash_semantics=file_content_hash_unverified
hash_validation_available=no
suffix_resolved_count=353
ambiguous_count=0
unresolved_count=0
raw_filenames_printed=no
raw_paths_printed=no
raw_contents_printed=no
```

Do not rewrite `raw_files.saved_path`. Do not persist rehearsal-resolved raw paths. The database remains authoritative. Raw CSV files remain archived evidence only.

## 9. Operational Command Evidence

Operational command output must be reviewed for:

- Read-only behavior.
- Redacted output.
- No `.env` values.
- No `APP_KEY`.
- No DB credentials.
- No token secrets or token hashes.
- No bearer tokens.
- No Google credentials.
- No command payload JSON.
- No full configs.
- No full runner GUIDs.
- No raw filenames, full paths, file lists, or raw evidence contents.

The expected final command result may be `PASS`, `WARN`, or `FAIL` depending on known rehearsal-only warnings. Warnings must be listed and dispositioned.

## 10. Portal Smoke Policy

Phase 19N does not expose the rehearsal app through a web endpoint.

Policy:

- Do not expose rehearsal through `inventory-pilot.internal.lan`.
- Do not run `php artisan serve`.
- Do not change DNS or HPE StoreEasy.
- Do not point runners or collectors to rehearsal.
- CLI-only Laravel boot or route/controller render checks may be discussed only if safe and read-only.
- Browser/manual portal smoke through a web server is deferred to a separate local-only web exposure phase.

Expected evidence:

```text
portal_smoke_validation=deferred_to_separate_local_only_web_phase
cli_boot_validation=PASS|WARN|FAIL|not_run
web_endpoint_exposed=no
php_artisan_serve_run=no
live_https_endpoint_used=no
```

## 11. Required Evidence Before Phase 19O

Phase 19O restore rehearsal design may be discussed only after all of the following are accepted:

- Phase 19M.2 execute output retained redacted.
- Imported row counts match.
- Primary-key preservation `PASS`.
- Relationship validation `PASS`.
- JSON/text validation `PASS`.
- Command lifecycle validation `PASS`.
- Assignment override validation `PASS`.
- Raw evidence validation `PASS_WITH_WARN` with `ambiguous_count=0` and `unresolved_count=0`.
- Auto-increment validation `PASS`.
- `classification_rules` preserved at count `8`.
- `migrations` preserved at count `18`.
- `inventory_rehearsal_restore` untouched and empty.
- Operational command outputs redacted.
- No secrets or raw data printed.
- No runner/collector traffic changed.
- No package/site-kit generation occurred.
- No live `.env`, live DB driver, HPE StoreEasy, DNS, or live SQLite changes.
- Known warnings listed and dispositioned.

## 12. Final Phase 19N Status Shape

Expected status shape if evidence is accepted:

```text
phase=19N_post_execute_evidence_review
execute_rerun=no
data_reset_or_truncate=no
restore_db_written=no
restore_rehearsal_run=no
production_migration_approved=no
cutover_approved=no
result=PASS_WITH_WARN
known_warning=raw_hash_semantics_unverified
```

## 13. Stop Conditions

Stop Phase 19N review if any of these occur:

- Any count mismatch appears that is not already explained.
- Any relationship validation fails.
- Any primary-key preservation issue appears.
- Any command lifecycle count or state looks corrupted.
- Any JSON/text validation prints payload contents or fails unexpectedly.
- Any raw evidence path becomes unresolved or ambiguous.
- Any raw filename, full path, raw CSV content, command payload JSON, token, secret, `.env` value, DB credential, full config, or full runner GUID appears in evidence.
- `inventory_rehearsal_restore` contains tables or rows.
- A command writes to `inventory_rehearsal` unexpectedly.
- A command writes anywhere outside `inventory_rehearsal`.
- Any live SQLite path is accessed.
- Live `.env` or live DB driver changes.
- Rehearsal is exposed through `inventory-pilot.internal.lan`.
- HPE StoreEasy, DNS, runner, or collector configuration changes.
- Package/site-kit generation happens.

## 14. Acceptance Criteria

Phase 19N is accepted when:

- It is documented as read-only validation and evidence review.
- No execute rerun occurred.
- No reset/truncate occurred.
- No dump restore occurred.
- `inventory_rehearsal_restore` remained untouched and empty.
- Imported counts match Phase 19M.2 success output.
- Primary-key, relationship, JSON/text, command lifecycle, assignment, raw evidence, and auto-increment validations remain `PASS` or accepted `WARN`.
- Raw evidence warning is limited to unverified `raw_hash` semantics; mapping remains complete and unambiguous.
- Laravel operational commands run from `D:\inventory-rehearsal\laravel` and outputs are redacted.
- Any warnings are documented with disposition.
- No secrets or prohibited raw data appear in output.
- Portal smoke requiring web exposure is deferred or limited to CLI-only validation.
- Production migration and cutover remain explicitly unapproved.
