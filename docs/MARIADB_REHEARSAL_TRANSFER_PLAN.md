# Phase 19H - App-aware SQLite-to-MariaDB Transfer Plan

## 1. Purpose and non-goals

Phase 19H defines the app-aware data transfer plan from the verified copied SQLite rehearsal source into the MariaDB rehearsal database.

Decision: Option A - documentation-only transfer plan.

Non-goals:

- Do not write application code.
- Do not create a transfer command.
- Do not create a dry-run helper.
- Do not run data transfer.
- Do not truncate or reset MariaDB tables.
- Do not dump MariaDB yet.
- Do not restore into `inventory_rehearsal_restore` yet.
- Do not expose rehearsal as a web endpoint.
- Do not run `php artisan serve`.
- Do not run operational validation commands after transfer because transfer has not happened.
- Do not change live `D:\inventory\laravel\.env`.
- Do not change live `APP_URL`.
- Do not change live DB driver.
- Do not mutate live SQLite.
- Do not read from live SQLite directly.
- Do not approve production migration or cutover.
- Do not generate packages or site kits.
- Do not point HPE StoreEasy, DNS, runners, or collectors to rehearsal.
- Do not change runner behavior.
- Do not change collector behavior.
- Do not change API contracts.
- Do not change command lifecycle semantics.
- Do not enable Direct `repair_update`.
- Do not print `.env` values, `APP_KEY`, DB credentials, token secrets, token hashes, bearer tokens, raw CSV contents, command payload JSON, full configs, or full runner GUIDs.

## 2. Current starting state

- Rehearsal app: `D:\inventory-rehearsal\laravel`.
- Verified copied SQLite source: `D:\inventory-rehearsal\source-copy\database.sqlite`.
- Copied SQLite source is read-only.
- Copied SQLite source size: `5,910,528` bytes.
- Copied SQLite integrity check: `ok`.
- Raw archive copied to `D:\inventory-rehearsal\raw_archive`.
- Downloads/site kits copied to `D:\inventory-rehearsal\downloads`.
- Real rehearsal `.env` exists only at `D:\inventory-rehearsal\laravel\.env`.
- New rehearsal `APP_KEY` was generated and not printed or recorded.
- DB password was stored only in rehearsal `.env` and operator password store.
- `D:\inventory-rehearsal\laravel\database\database.sqlite` does not exist.
- MariaDB version: `10.11.16-MariaDB`.
- `inventory_rehearsal` exists and has `20` migrated Laravel tables.
- `inventory_rehearsal_restore` exists and remains empty.
- `inventory_rehearsal_app` grants are scoped only to `inventory_rehearsal.*` and `inventory_rehearsal_restore.*`.
- Live app remains at `D:\inventory\laravel`.
- Live app still uses SQLite.
- Live `APP_URL` remains `https://inventory-pilot.internal.lan`.
- Direct `repair_update` remains blocked.
- Collector-share remains supported and unaffected.
- Database is authoritative.
- CSV files are archived evidence only.
- Google Drive is backup/sync only.
- Official packages/site kits are generated only on Supermicro.

## 3. Phase 19H decision: Option A documentation-only

Phase 19H is documentation/planning only.

Do not implement a command in Phase 19H.

Future Phase 19I may implement a dry-run-only Artisan command. Future execute mode must wait until dry-run evidence is reviewed.

Recommended future command name:

```powershell
php artisan inventory:mariadb-rehearsal-transfer
```

Future command requirements:

- Must require explicit source:

  ```powershell
  --source="D:\inventory-rehearsal\source-copy\database.sqlite"
  ```

- Must support `--dry-run` first.
- Do not add `--execute` until a later approved phase.
- Source must be explicit; no silent default source.
- Refuse live SQLite path: `D:\inventory\laravel\database\database.sqlite`.
- Refuse any source under `D:\inventory\laravel`.
- Read only from `D:\inventory-rehearsal\source-copy\database.sqlite`.
- Write only to `inventory_rehearsal`.
- Never write to `inventory_rehearsal_restore`.

`inventory_rehearsal_restore` is reserved for later restore rehearsal.

Phase 19I follow-up: dry-run-only command `php artisan inventory:mariadb-rehearsal-transfer` is implemented with required `--source=` and `--dry-run`. No `--execute` mode exists. The command validates source/target boundaries, previews table/count/relationship/JSON/command/token/raw-evidence/assignment checks, prints redacted counts only, and does not write rows, reset tables, dump, restore, expose a web endpoint, generate packages, touch live config, or change runner/collector traffic.

## 4. Source and target boundaries

Allowed source:

```text
D:\inventory-rehearsal\source-copy\database.sqlite
```

Allowed target:

```text
inventory_rehearsal
```

Reserved restore target:

```text
inventory_rehearsal_restore
```

Rules:

- Transfer must never read live SQLite directly.
- Transfer must never write to live/production databases.
- Transfer must never write to `inventory_rehearsal_restore`.
- Raw CSV files are archived evidence only, not operational source of truth.
- Database remains authoritative.

## 5. Future command recommendation

Future Phase 19I should consider a dry-run-only Artisan command:

```powershell
php artisan inventory:mariadb-rehearsal-transfer --source="D:\inventory-rehearsal\source-copy\database.sqlite" --dry-run
```

Dry-run should inspect source/target readiness and produce counts, planned table order, reset plan, mapping risks, and validation summaries without writing data.

Execute mode must be deferred to a later approved phase after dry-run evidence is reviewed.

## 6. Transfer principles

Future transfer must:

- preserve primary keys
- preserve foreign-key relationships
- preserve `created_at` and `updated_at`
- preserve nullable fields as `NULL`
- preserve JSON/text payloads without printing contents
- preserve command lifecycle fields
- preserve token metadata without exposing secrets
- preserve raw archive path references
- preserve portal-managed assignment override data

## 7. Proposed table transfer order

Reviewed proposed order:

1. `users`
2. `collector_sites`
3. site token / token metadata table if present
4. `devices`
5. `device_identities`
6. `device_scans`
7. `hardware_snapshots`
8. `storage_health_observations`
9. `network_observations`
10. `peripherals`
11. `device_assignments`
12. `raw_files`
13. `change_log`
14. `collectors`
15. `runners`
16. `runner_commands`
17. classification rules table if present
18. department/site cleanup rules table if present
19. optional `personal_access_tokens` if used and safe
20. optional `password_reset_tokens` / `failed_jobs` only if needed

Do not transfer `migrations` from SQLite. MariaDB already owns migrations state from Phase 19G.

Framework/system tables should be handled deliberately and minimally.

## 8. Primary key preservation rules

All domain/application tables with relationships must preserve primary keys exactly, including:

- `users`
- `collector_sites`
- `devices`
- `device_identities`
- `device_scans`
- `hardware_snapshots`
- `storage_health_observations`
- `network_observations`
- `peripherals`
- `device_assignments`
- `raw_files`
- `change_log`
- `collectors`
- `runners`
- `runner_commands`
- site token / token metadata table if present
- classification / cleanup rule tables if present

If a table cannot preserve primary keys, stop before transfer design continues.

## 9. Target reset/truncate policy for future transfer

Policy for a later approved transfer only:

- Future transfer should start from an empty migrated schema or safely reset imported app/domain tables.
- Keep `migrations` table.
- Clear target application tables in dependency-safe order only inside the future approved transfer command.
- Never clear live SQLite.
- Never reset `inventory_rehearsal_restore` during Phase 19I dry-run.
- Never touch production/live databases.

Do not execute reset/truncate work in Phase 19H.

## 10. Pre-transfer validation requirements

Future command must validate:

- running from `D:\inventory-rehearsal\laravel`
- base path is not `D:\inventory\laravel`
- `APP_URL` is local-only and not `inventory-pilot.internal.lan`
- `DB_CONNECTION=mysql`
- `DB_DATABASE=inventory_rehearsal`
- MariaDB reachable
- `inventory_rehearsal` has migrated schema
- `migrations` table shows all migrations ran
- `inventory_rehearsal_restore` is empty
- source path is `D:\inventory-rehearsal\source-copy\database.sqlite`
- source SQLite exists, is non-zero, readable, and not live SQLite
- source SQLite integrity/readability check passes
- copied `raw_archive` and `downloads` exist
- no web endpoint is exposed

## 11. Post-transfer validation requirements

Future execution must validate:

- row counts match
- primary keys preserved
- relationships valid
- `created_at` / `updated_at` preserved
- nullable fields preserved
- JSON/text fields decode or remain valid
- command lifecycle state preserved
- token metadata exists without printing secrets
- raw evidence references preserved
- portal-managed assignment overrides preserved
- storage health observations linked and risk levels preserved

## 12. Mandatory row-count comparisons

Required table counts:

- `users`
- `devices`
- `device_identities`
- `device_scans`
- `hardware_snapshots`
- `storage_health_observations`
- `network_observations`
- `peripherals`
- `device_assignments`
- `change_log`
- `raw_files`
- `collector_sites`
- `collectors`
- `runners`
- `runner_commands`
- site token / token metadata table if present
- classification rules table if present
- department/site cleanup rules table if present

Mandatory subset counts:

- Direct HTTPS runner count
- collector-share runner count
- collector count
- pending command count
- delivered awaiting ACK count
- failed command count
- succeeded command count
- latest scan per device count
- raw files with missing archive path count
- devices with latest snapshot count
- storage health observations by `risk_level`
- device assignment override count
- token metadata count without token values

## 13. Mandatory relationship checks

Required checks:

- `device_identities.device_id -> devices.id`
- `device_scans.device_id -> devices.id`
- `hardware_snapshots.device_scan_id -> device_scans.id`
- `network_observations.device_scan_id -> device_scans.id`
- `peripherals.device_scan_id -> device_scans.id`
- `storage_health_observations` device/scan links
- `device_assignments.device_id -> devices.id`
- `change_log.device_id -> devices.id`
- `raw_files` scan/device relation if present
- `collectors` site relation if present
- `runners` site/collector relation if present
- `runner_commands` runner relation if present
- latest scan per device resolves
- latest hardware snapshot resolves

## 14. JSON/text validation rules

Rules:

- Validate JSON fields without printing contents.
- Validate command payload JSON existence/decodability without printing payload.
- Validate `raw_state_json` / metadata JSON without printing contents.
- Validate storage health JSON/text where applicable.
- Output counts only.

## 15. Command lifecycle validation rules

Validate:

- queued count preserved
- delivered/dispatched count preserved
- acknowledged count preserved
- completed count preserved
- succeeded count preserved
- failed count preserved
- `acknowledged_at` preserved
- `completed_at` preserved
- `completion_status` preserved
- `result_upload_id` preserved
- payload exists/decodes without printing

## 16. Token metadata validation without secrets

Validate:

- token metadata count
- token type distribution if safe
- Direct HTTPS runner metadata exists if expected
- collector token metadata exists if expected
- token hashes/non-secret metadata presence only

Never print token secrets, bearer tokens, token hashes, or raw token values.

## 17. Raw evidence reference validation

Validate:

- `raw_files` row count
- raw archive references preserved
- missing archive path count
- copied raw archive root exists

Do not print raw CSV contents.

## 18. Portal-managed assignment validation

Validate:

- `device_assignments` count
- `device_id` links valid
- department/site/location/room override fields preserved
- CSV assignment evidence does not overwrite portal-managed assignment state

## 19. MariaDB dump-before-execute requirement

Before any future execute-mode transfer:

- create MariaDB dump of empty migrated `inventory_rehearsal` schema
- store under `D:\inventory-rehearsal\backups\mariadb_dumps`
- do not print dump contents
- do not include secrets in notes

Do not dump MariaDB in Phase 19H.

## 20. Restore rehearsal deferral

Restore rehearsal is deferred to Phase 19J or later:

- dump transferred MariaDB DB
- restore into `inventory_rehearsal_restore`
- validate counts and app behavior

No restore test means no future production cutover.

## 21. App-level portal validation deferral

App-level portal validation is deferred until after transfer execution.

Later routes:

- dashboard
- devices
- device detail pages
- runners
- Direct HTTPS runner detail
- collector-share runner detail
- collectors
- command queue
- downloads
- storage-health
- raw evidence
- setup-wizard
- reports if available

## 22. Operational command validation deferral

Operational command validation is deferred until after transfer execution.

Later commands from rehearsal path only:

```powershell
php artisan inventory:doctor
php artisan inventory:production-readiness
php artisan inventory:install-preflight
php artisan inventory:direct-pilot-status
php artisan inventory:direct-runner-triage IT-ADMIN
php artisan inventory:direct-runner-triage LAPTOP-I76TA97E
php artisan test
```

Run `php artisan test` only if approved for that later phase.

## 23. Stop conditions

Stop design or later execution if:

- design requires reading live SQLite directly
- design writes anywhere except `inventory_rehearsal`
- design touches `inventory_rehearsal_restore` before restore phase
- table order cannot preserve relationships
- primary keys cannot be preserved
- command lifecycle mapping is unclear
- token metadata mapping is unclear without exposing secrets
- raw evidence references cannot be preserved
- assignment override semantics are unclear
- JSON/text contents would be printed
- future command could run from `D:\inventory\laravel`
- future command could silently default source path
- any secret appears in docs/logs/chat/screenshots
- later execution source is live SQLite
- later execution target is not `inventory_rehearsal`
- later execution causes `inventory_rehearsal_restore` to receive data
- row counts mismatch without explanation
- relationship checks fail
- command lifecycle state corrupts
- token metadata missing
- raw file references break
- Direct `repair_update` becomes enabled

## 24. Out of scope

Out of scope:

- code changes
- transfer command
- dry-run helper
- execute transfer
- truncating/resetting tables
- MariaDB dump execution
- restore rehearsal execution
- portal web exposure
- `php artisan serve`
- portal/app validation execution
- operational command execution after transfer
- production migration
- cutover approval
- live `.env` changes
- live `APP_URL` changes
- live DB driver switch
- live SQLite mutation
- reading live SQLite directly
- package/site-kit generation
- runner/collector repointing
- runner/collector behavior changes
- API contract changes
- command lifecycle changes
- Direct `repair_update`
- token rotation UI
- per-runner token enrollment

## 25. Acceptance criteria

Phase 19H is accepted when:

- documentation-only decision is recorded
- future command recommendation is documented
- source/target boundaries are documented
- transfer principles are documented
- proposed table order is documented
- primary key preservation rules are documented
- future reset policy is documented without execution
- pre-transfer validation requirements are documented
- post-transfer validation requirements are documented
- required row and subset counts are documented
- relationship checks are documented
- JSON/text validation rules are documented
- command lifecycle validation rules are documented
- token metadata validation rules are documented without secrets
- raw evidence validation is documented
- portal-managed assignment validation is documented
- dump-before-execute requirement is documented
- restore rehearsal, portal validation, and operational command validation are deferred
- stop conditions are documented
- out-of-scope boundaries are documented
- no code, command, helper, transfer, reset, dump, restore, web exposure, package generation, live config change, runner/collector traffic change, production data mutation, command lifecycle change, Direct `repair_update`, generated artifact, `.env` value, or secret is changed/exposed

## 26. Cutover-not-approved statement

Phase 19H is a transfer planning document only. It does not approve production migration, production cutover, live DB driver switching, live `.env` changes, app data transfer, Laravel migration execution, portal exposure, runner/collector traffic changes, or package/site-kit generation.

## 27. Phase 19I dry-run command

Phase 19I implements the dry-run-only transfer planning command:

```powershell
php artisan inventory:mariadb-rehearsal-transfer --source="D:\inventory-rehearsal\source-copy\database.sqlite" --dry-run
```

Command boundaries:

- `--source=` is required.
- `--dry-run` is required.
- `--execute` does not exist.
- The source must be explicit and must be the copied rehearsal SQLite source.
- Live SQLite and any source under `D:\inventory\laravel` are refused.
- The command must run from `D:\inventory-rehearsal\laravel`.
- Configured DB connection must be `mysql`.
- Configured DB database must be `inventory_rehearsal`.
- `APP_URL` must not contain `inventory-pilot.internal.lan`.
- `inventory_rehearsal_restore` must remain empty.

The command is read-only. It performs validation and previews only; it does not transfer data, reset/truncate tables, dump MariaDB, restore MariaDB, write reports by default, expose a web endpoint, generate packages/site kits, change live config, read live SQLite, mutate live SQLite, change runner/collector traffic, change command lifecycle semantics, or enable Direct `repair_update`.

## 28. Phase 19I Supermicro dry-run validation

Phase 19I status: implemented, tested, pushed, and Supermicro dry-run validated with expected `WARN`.

Runtime command:

```powershell
php artisan inventory:mariadb-rehearsal-transfer --source="D:\inventory-rehearsal\source-copy\database.sqlite" --dry-run
```

Runtime result:

```text
Result: WARN
```

Validated hard boundaries:

- Dry-run only message was shown.
- No execute mode exists.
- No data was written.
- `--dry-run` supplied: `OK`.
- `--source` supplied: `OK`.
- `--execute` option absent: `OK`.
- Laravel base path is the rehearsal path: `OK`.
- `DB_CONNECTION` is `mysql`: `OK`.
- `DB_DATABASE` is `inventory_rehearsal`: `OK`.
- `APP_URL` does not contain live pilot hostname: `OK`.
- Source SQLite exists, is non-zero, readable, and opened for read-only inspection.
- Source SQLite integrity/readability check passed.
- Source table count: `20`.
- MariaDB target reachable.
- Required migrated target schema present.
- Target migrations row count: `18`.
- `inventory_rehearsal_restore` exists and is empty.

Main source row counts:

- `users=1`
- `devices=37`
- `device_identities=160`
- `device_scans=353`
- `hardware_snapshots=353`
- `storage_health_observations=694`
- `network_observations=353`
- `peripherals=5501`
- `device_assignments=86`
- `change_log=1337`
- `raw_files=353`
- `collector_sites=1`
- `collectors=3`
- `runners=38`
- `runner_commands=113`
- `classification_rules=8`

Subset summaries:

- Direct HTTPS runner count: `2`.
- Collector-share runner count: `36`.
- Collector count: `3`.
- Pending command count: `0`.
- Delivered/dispatched awaiting ACK count: `6`.
- Failed command count: `6`.
- Succeeded command count: `3`.
- Latest scan per device count: `37`.
- Raw files with missing archive path count: `0`.
- Devices with latest snapshot count: `37`.
- Device assignment override count: `86`.
- Token metadata count without values: `0`.

Validation summary:

- Main source and target application tables are present.
- Target current row counts are mostly zero before transfer.
- Main relationship checks passed.
- JSON/text decode checks passed for `hardware_snapshots.snapshot_json`, `raw_files.metadata_json`, `collectors.raw_status_json`, `runners.raw_state_json`, `runner_commands.payload_json`, `storage_health_observations.risk_reasons`, and command payload presence/decode checks.
- `raw_files_count=353`.
- `raw_archive_reference_populated_count=353`.
- `missing_archive_path_reference_count=0`.
- `copied_raw_archive_root_exists=yes`.
- `referenced_evidence_missing_count=353`.
- Assignment links valid: `86`.
- Assignment rows with missing device link: `0`.

WARN review items before any execute-mode phase:

- Optional source/target `site_tokens` table absent.
- Optional source/target `department_cleanup_rules` table absent.
- Optional source/target `site_cleanup_rules` table absent.
- `classification_rules` already has `8` rows in the target.
- `raw_files` relationship checks skipped because `raw_files.device_scan_id` / `raw_files.device_id` columns were missing.
- `storage_health_observations.raw_json` check skipped because the column was missing.
- `referenced_evidence_missing_count=353` must be reviewed before any execute-mode phase.

Guardrail validation:

```powershell
php artisan inventory:mariadb-rehearsal-transfer --dry-run
```

Result: `FAIL`. Reason: `--source` is required and must be explicit.

```powershell
php artisan inventory:mariadb-rehearsal-transfer --source="D:\inventory\laravel\database\database.sqlite" --dry-run
```

Result: `FAIL`. Reasons:

- Source path is the live SQLite database and is refused.
- Source path is under the live Laravel path and is refused.
- Source path must be the copied rehearsal SQLite source.

Boundary confirmations:

- No `--execute` option exists.
- No data transfer occurred.
- No rows were inserted/updated/deleted in MariaDB.
- No tables were reset/truncated.
- No MariaDB dump occurred.
- No restore occurred.
- No web endpoint was exposed.
- No `php artisan serve` was run.
- No package/site-kit generation occurred.
- No live `.env` change occurred.
- No live `APP_URL` change occurred.
- No live DB driver change occurred.
- Live SQLite was not mutated.
- Live SQLite was refused as a source.
- No runner/collector traffic changed.
- No runner/collector/API/command lifecycle behavior changed.
- Direct `repair_update` remains blocked.
- No `.env` values, `APP_KEY`, DB credentials, token secrets, token hashes, bearer tokens, raw CSV contents, command payload JSON, full configs, or full runner GUIDs were printed or recorded.

## 29. Phase 19J dry-run evidence review

Phase 19J dry-run evidence review is documented in `docs/MARIADB_REHEARSAL_DRY_RUN_EVIDENCE_REVIEW.md`.

It is read-only and focuses on deciding whether `referenced_evidence_missing_count=353` is a real missing evidence problem or a raw evidence path-mapping problem before any execute-mode planning continues.

Manual Supermicro review completed with Outcome A: `referenced_evidence_missing_count=353` is explained by path-shape/path-mapping behavior, not missing raw evidence. The copied raw archive contains `563` files, `raw_files` has `353` rows, all `353` `saved_path` basenames exist uniquely in the copied archive, and `unresolved_after_suffix_review=0`.

Phase 19K should be architecture/design for execute-mode transfer with explicit raw evidence path handling. Basename/suffix resolution was unique for the current copied archive, but future implementation must not silently rely on basename-only matching unless uniqueness is proven.

## 30. Phase 19K execute-mode design

Phase 19K execute-mode transfer architecture/design is documented in `docs/MARIADB_REHEARSAL_EXECUTE_MODE_DESIGN.md`.

The design remains documentation-only. It does not approve `--execute`, data transfer, table reset/truncate, MariaDB dump/restore, web exposure, package/site-kit generation, live config changes, runner/collector traffic changes, production migration, or cutover.

Key design decisions:

- First execute implementation must be no-reset and empty-target only.
- A manual dump marker/path is mandatory before any future execute mode.
- `classification_rules=8` is compare-only and target-preserved when source/target identifiers and safe checksums match.
- Raw evidence handling must preserve original `raw_files.saved_path` and derive rehearsal/archive mapping only for validation.
- Basename/suffix resolution was unique for the current copied archive, but future implementation must not silently rely on basename-only matching unless uniqueness is proven.
- Optional missing tables remain WARN/skip only when absent in both source and target.

Next safe phase: Phase 19L read-only execute-readiness hardening, diagnostics, and tests only; no writes.

## 31. Phase 19L execute-readiness diagnostics

Phase 19L extends the existing dry-run-only command with read-only execute-readiness diagnostics:

```powershell
php artisan inventory:mariadb-rehearsal-transfer --source="D:\inventory-rehearsal\source-copy\database.sqlite" --dry-run --readiness --dump-marker="D:\inventory-rehearsal\backups\mariadb_dumps\<dump-file-or-marker>"
```

The readiness mode validates Phase 19K gates without writing data: explicit source, dry-run mode, dump marker, empty target state, compare-only `classification_rules`, raw evidence path mapping, optional table policy, and known schema differences.

It still has no `--execute` mode and does not transfer data, reset/truncate tables, create dumps, restore dumps, write to `inventory_rehearsal_restore`, expose a web endpoint, generate packages/site kits, change live config, read/mutate live SQLite, or change runner/collector traffic.

Phase 19M remains the earliest possible future execute-import phase and requires separate approval.

## 32. Phase 19M controlled execute import

Phase 19M adds the first controlled `--execute` mode to the existing `php artisan inventory:mariadb-rehearsal-transfer` command. The mode is mutually exclusive with `--dry-run` and `--readiness`, requires an explicit copied SQLite `--source`, an operator-created `--dump-marker`, and all Phase 19M confirmation flags.

Execute scope is limited to importing the approved application/domain tables into an empty `inventory_rehearsal` target. It preserves primary keys and source values, preserves `migrations`, keeps `classification_rules` compare-only and target-preserved, preserves `raw_files.saved_path`, skips framework/transient/security tables, and runs post-import count/PK/relationship/JSON/command/raw-evidence/auto-increment validation.

Phase 19M does not reset/truncate tables, create dumps, restore dumps, write to `inventory_rehearsal_restore`, read or mutate live SQLite, change live config, expose web endpoints, generate packages/site kits, change runner/collector traffic, enable Direct `repair_update`, approve production migration, or approve cutover.
