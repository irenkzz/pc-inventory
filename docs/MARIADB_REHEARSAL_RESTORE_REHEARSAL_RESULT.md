# Phase 19O.1 - MariaDB Restore Rehearsal Result

## 1. Purpose

This document records the accepted Phase 19O.1 controlled MariaDB restore rehearsal evidence for the Internal Windows PC Inventory System.

Phase 19O.1 validates backup and restore mechanics for the rehearsal MariaDB data set. It does not approve production migration, production cutover, live DB switching, live `.env` changes, web exposure, runner/collector repointing, package/site-kit generation, or Direct `repair_update`.

Evidence in this document is count-only and redacted.

## 2. Accepted Result

Accepted restore rehearsal status:

```text
phase=19O.1_restore_rehearsal
result=PASS_WITH_WARN
restore_source_database=inventory_rehearsal
restore_target_database=inventory_rehearsal_restore
restore_target_pre_restore_empty=yes
dump_created=yes
dump_file_nonzero=yes
restore_completed=yes
restore_into_inventory_rehearsal=no
restore_into_live_db=no
restore_count_validation=PASS
relationship_validation=PASS
relationship_orphan_count=0
restore_raw_evidence_validation=PASS_WITH_WARN
raw_hash_semantics=file_content_hash_unverified
secrets_printed=no
row_values_printed=no
sql_bindings_printed=no
dump_contents_printed=no
```

Accepted warning carried forward:

- `raw_hash_semantics=file_content_hash_unverified`
- `restore_raw_evidence_validation=PASS_WITH_WARN`

This warning remains acceptable only because raw evidence path mapping is complete and unambiguous.

## 3. Phase 19O.1a Restore-target Cleanup

Initial restore rehearsal blocker:

```text
restore_target_initial_blocker=inventory_rehearsal_restore_had_one_empty_change_log_table
```

Read-only metadata review found:

```text
restore_cleanup_fk_outgoing_same_schema_count=2
restore_cleanup_fk_incoming_count=0
restore_cleanup_fk_cross_schema_count=0
```

Approved cleanup path:

```text
restore_cleanup_option=Option_A_drop_only_empty_restore_change_log
restore_cleanup_target=inventory_rehearsal_restore.change_log
restore_cleanup_scope=empty_restore_target_table_only
restore_target_schema_empty_after_cleanup=yes
```

No live DB, live SQLite, `inventory_rehearsal`, or production data was touched by the cleanup path.

## 4. Restored Baseline Counts

Restored baseline counts matched the accepted Phase 19N/19O baseline:

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
restore_target_table_count=20
restore_target_row_count=9409
```

Subset counts:

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

## 5. Helper Query Notes

The `latest_scan_id` query issue was a read-only helper/schema mismatch only.

Disposition:

```text
latest_scan_id_query_issue=read_only_helper_schema_mismatch
write_occurred=no
corrected_derived_counts_matched=yes
```

Do not treat the helper mismatch as data loss or restore failure.

## 6. Evidence Redaction

The restore evidence must not include:

- `.env` values
- `APP_KEY`
- DB credentials
- token secrets or token hashes
- bearer tokens
- Google credentials
- raw CSV contents
- command payload JSON
- full configs
- full runner GUIDs
- raw filenames
- full raw paths
- full raw evidence file lists
- raw evidence file contents
- SQL text with values
- SQL bindings
- row values
- dump contents

Accepted redaction evidence:

```text
secrets_printed=no
row_values_printed=no
sql_bindings_printed=no
dump_contents_printed=no
raw_contents_printed=no
command_payload_json_printed=no
full_runner_guids_printed=no
```

## 7. Remaining Production-migration Readiness Gates

A future production migration phase requires separate explicit approval. At minimum, it must include:

- Maintenance window and write freeze.
- Fresh verified live backups.
- Explicit copied source policy.
- Accepted restore rehearsal evidence.
- Current operational command validation.
- Portal validation evidence.
- Command lifecycle validation.
- Token metadata validation without secrets.
- Raw evidence validation.
- Rollback decision point.
- Explicit cutover approval.

These gates do not approve production migration by themselves.

## 8. Still Not Approved

Phase 19O.1 acceptance does not approve:

- Production migration.
- Production cutover.
- Live DB driver switching.
- Live `.env` changes.
- Live SQLite read or mutation.
- Web endpoint exposure.
- `php artisan serve`.
- Runner or collector repointing.
- Package or site-kit generation.
- Direct `repair_update`.
- Secret, raw CSV, row value, SQL binding, dump content, raw filename/path/file-list, command payload JSON, full config, token material, or full runner GUID exposure.
