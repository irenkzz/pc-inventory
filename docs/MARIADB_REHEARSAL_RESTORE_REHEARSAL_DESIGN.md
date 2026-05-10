# Phase 19O - MariaDB Restore Rehearsal Design

## 1. Purpose

Phase 19O is the restore rehearsal design phase for validating MariaDB backup and restore mechanics after:

- Phase 19M.2 completed the controlled execute import into `inventory_rehearsal`.
- Phase 19N read-only post-execute evidence review was accepted as `PASS_WITH_WARN`.

Phase 19O design does not approve restore execution. A later explicit Phase 19O execution step or Phase 19O.1 must approve running dump and restore commands.

Production migration and cutover remain unapproved.

## 2. Accepted Phase 19N State

Accepted Phase 19N status:

```text
phase=19N_post_execute_evidence_review
result=PASS_WITH_WARN
execute_rerun=no
reset_or_truncate=no
restore_db_written=no
production_migration_approved=no
cutover_approved=no
```

Phase 19M.2 execute validation baseline:

```text
writes_performed=yes
primary_key_preservation=PASS
relationship_validation=PASS
json_text_validation=PASS
command_lifecycle_validation=PASS
assignment_override_validation=PASS
raw_evidence_validation=PASS_WITH_WARN
auto_increment_validation=PASS
restore_db_untouched=PASS
Result: WARN
```

Accepted Phase 19N warnings:

- `raw_hash_semantics=file_content_hash_unverified`
- Raw evidence mapping complete and unambiguous: `suffix_resolved_count=353`, `ambiguous_count=0`, `unresolved_count=0`
- Rehearsal operational commands may return expected `WARN`, `FAIL`, or `ATTENTION` because rehearsal is not production and no live runners are pointed to `inventory_rehearsal`.
- Accidental `php artisan test` run is a documentation note only and must not be used as Phase 19N evidence.
- `assignment_site_populated_count=column_missing` is accepted as a helper/schema mismatch, not data loss.

## 3. Source And Restore Target

Future restore source:

```text
restore_dump_source_database=inventory_rehearsal
```

Future restore target:

```text
restore_target_database=inventory_rehearsal_restore
```

Rules:

- `inventory_rehearsal` is the source DB for the restore rehearsal dump.
- `inventory_rehearsal_restore` must be empty before restore.
- Restore must never target `inventory_rehearsal`.
- Restore must never target any live or production database.
- Restore must never use live SQLite.
- Restore must never change live `.env`.

## 4. Future Approved Restore Execution Flow

This section documents a future flow only. It is not approval to execute it.

### A. Pre-restore Checks

Required checks before any later approved restore execution:

- Confirm Phase 19N is accepted.
- Confirm the command is running on Supermicro.
- Confirm path boundaries are for `D:\inventory-rehearsal`.
- Confirm `inventory_rehearsal` contains the imported Phase 19N baseline counts.
- Confirm `inventory_rehearsal_restore` exists and is empty.
- Confirm no live app config changes are planned or present.
- Confirm no runner or collector traffic points to rehearsal or restore DB.
- Confirm dump and restore tool paths without printing passwords.

Expected status shape:

```text
phase_19n_accepted=yes
restore_source_database=inventory_rehearsal
restore_target_database=inventory_rehearsal_restore
restore_target_empty=yes
live_config_change_planned=no
runner_collector_traffic_change_planned=no
credentials_printed=no
```

### B. Dump

Future approved dump behavior:

- Create a MariaDB dump from `inventory_rehearsal`.
- Store the dump under `D:\inventory-rehearsal\backups\mariadb_dumps`.
- Do not print dump contents.
- Do not put a password in a command line or notes.
- Record only dump filename, size, timestamp, and safe checksum if used.

Safe evidence shape:

```text
dump_source_database=inventory_rehearsal
dump_directory_approved=yes
dump_file_created=yes
dump_file_nonzero=yes
dump_filename_recorded=yes
dump_size_recorded=yes
dump_timestamp_recorded=yes
dump_checksum_recorded=yes|no
dump_contents_printed=no
credentials_printed=no
```

### C. Restore

Future approved restore behavior:

- Restore the dump into `inventory_rehearsal_restore` only.
- Do not restore into `inventory_rehearsal`.
- Do not restore into any live DB.
- Do not change live `.env`.
- Do not expose a web endpoint.
- Do not run `php artisan serve`.

Safe evidence shape:

```text
restore_target_database=inventory_rehearsal_restore
restore_into_inventory_rehearsal=no
restore_into_live_db=no
live_env_changed=no
web_endpoint_exposed=no
php_artisan_serve_run=no
```

### D. Restore Validation

Future approved validation must be count-only and redacted:

- Compare restored table counts in `inventory_rehearsal_restore` to the Phase 19N baseline.
- Validate relationship orphan counts.
- Validate JSON/text decodability counts.
- Validate command lifecycle counts.
- Validate assignment override counts.
- Validate raw evidence mapping policy remains `PASS_WITH_WARN`.
- Validate auto-increment positions if applicable.
- Validate no secrets or raw values are printed.

Expected summary shape:

```text
restore_count_validation=PASS
restore_relationship_validation=PASS
restore_json_text_validation=PASS
restore_command_lifecycle_validation=PASS
restore_assignment_validation=PASS
restore_raw_evidence_validation=PASS_WITH_WARN
restore_auto_increment_validation=PASS
secrets_printed=no
row_values_printed=no
raw_contents_printed=no
command_payload_json_printed=no
```

### E. Optional Restore Laravel Validation

Optional restore Laravel validation must use a separate restore-validation Laravel configuration/copy or an explicitly approved temporary environment approach.

Rules:

- Do not overwrite the existing rehearsal `.env` unless separately approved.
- Do not touch live `.env`.
- Do not expose a web endpoint.
- Do not run `php artisan serve`.
- Approved commands must be read-only.
- Do not point runners or collectors to the restore DB.

## 5. Required Restore Comparison Baseline

Expected restored counts:

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
migrations=18
classification_rules=8
```

Expected restored subset counts:

```text
direct_https_runner_count=2
collector_share_runner_count=36
collector_count=3
pending_command_count=0
delivered_dispatched_awaiting_ack_count=6
succeeded_command_count=3
failed_command_count=6
result_upload_id_populated_count=3
latest_scan_per_device_count=37
devices_with_latest_snapshot_count=37
raw_files_saved_path_populated_count=353
storage_health_by_risk_level=critical:70,healthy:249,unknown:175,warning:24,watch:176
device_assignment_override_count=86
runner_guid_present_count=20
```

Values must be reported as counts only. Runner GUID values, command payload JSON, row values, raw filenames, full paths, raw CSV contents, token material, and secrets must not be printed.

## 6. Known Warnings Carried Into Phase 19O

Expected warnings and disposition:

- `raw_hash` semantics remain unverified.
- Raw evidence validation remains `PASS_WITH_WARN` only if `suffix_resolved_count=353`, `ambiguous_count=0`, and `unresolved_count=0`.
- Direct HTTPS runners may remain stale or offline in restore validation because no live runners are pointed to the restore DB.
- `inventory:doctor --production` and production readiness checks may fail or warn if restore validation uses local/rehearsal settings.
- `assignment_site_populated_count=column_missing` is accepted unless a corrected helper is separately added.

## 7. Stop Conditions

Stop Phase 19O planning or any later approved execution if:

- `inventory_rehearsal_restore` is not empty before restore.
- Dump source is not `inventory_rehearsal`.
- Restore target is not `inventory_rehearsal_restore`.
- Any live DB, live path, or live `.env` is touched.
- Live SQLite is read or mutated.
- Dump or restore output prints credentials or secrets.
- Dump file is zero bytes or missing.
- Restore fails or produces partial tables.
- Restored table counts do not match Phase 19N baseline without explanation.
- Relationship validation fails.
- JSON/text validation fails or prints contents.
- Command lifecycle counts differ unexpectedly.
- Raw evidence mapping becomes unresolved or ambiguous.
- `raw_files.saved_path` is rewritten.
- `inventory_rehearsal` is reset or truncated.
- Runners or collectors are pointed to rehearsal or restore DB.
- HPE StoreEasy or DNS changes occur.
- Package or site-kit generation occurs.
- A web endpoint is exposed.
- Command payload JSON, full configs, full runner GUIDs, raw filenames, full paths, raw CSV contents, row values, or secrets appear in evidence.

## 8. Acceptance Criteria

Phase 19O design is accepted when:

- This design document exists.
- Phase 19N acceptance is recorded.
- Restore execution remains explicitly unapproved.
- Source and target restore boundaries are documented.
- Dump and restore command shape is documented safely without secrets.
- Restore validation checks are documented.
- Phase 19N baseline counts are included.
- Stop conditions are documented.
- Production migration and cutover remain explicitly unapproved.
- No code changed unless explicitly justified.
- No generated artifacts, dumps, logs, DB files, `.env` files, or secret-bearing files are staged.

Expected design-only status:

```text
phase=19O_restore_rehearsal_design
phase_19n_accepted=yes
restore_execution_approved=no
dump_created=no
restore_run=no
inventory_rehearsal_restore_written=no
production_migration_approved=no
cutover_approved=no
result=DESIGN_ONLY
```
