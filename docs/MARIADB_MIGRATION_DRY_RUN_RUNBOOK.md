# Phase 19A - MariaDB/MySQL Migration Dry-run Runbook

## 1. Purpose and non-goals

Phase 19A plans a safe MariaDB/MySQL migration dry-run and rehearsal process for the Internal Windows PC Inventory System. It documents how to rehearse migration mechanics, validation, restore, and stop conditions without executing a production migration or approving cutover.

Phase 19A is documentation-only.

Non-goals:

- Do not run migration.
- Do not install MariaDB/MySQL.
- Do not add a read-only migration readiness command yet.
- Do not create migration/import code.
- Do not switch the live Supermicro app from SQLite to MariaDB/MySQL.
- Do not change the live `.env`.
- Do not mutate production data.
- Do not change runner behavior.
- Do not change collector behavior.
- Do not change Direct HTTPS API contracts.
- Do not change collector API contracts.
- Do not change command lifecycle semantics.
- Do not enable Direct `repair_update`.
- Do not implement token rotation UI.
- Do not implement per-runner token enrollment.
- Do not generate official packages from IT-ADMIN.
- Do not stage generated artifacts.
- Do not expose secrets.
- Do not approve production cutover.
- Do not implement IIS + PHP FastCGI.

A future read-only migration readiness command may be considered after the manual rehearsal process is stable and the required checks below have proven useful.

## 2. Current source-of-truth state

- Active Laravel app runs on Supermicro at `D:\inventory\laravel`.
- IT-ADMIN development/Codex repo is `D:\xampp\htdocs\inventaris`.
- Git workflow: IT-ADMIN prepares source changes; Supermicro pulls reviewed changes.
- Official packages and site kits are generated only on Supermicro.
- Direct HTTPS endpoint is active at `https://inventory-pilot.internal.lan`.
- HPE StoreEasy terminates HTTPS and reverse-proxies to Laravel on Supermicro.
- Direct HTTPS is the second transport for small/no-IT sites.
- Collector-share remains supported and remains the main HQ/multi-PC mode.
- Current runner package version is `1.0.22`.
- Database is authoritative.
- CSV files are archived evidence only.
- Google Drive is backup/sync only.
- Commands are asynchronous: polling means delivery, and ACK is the execution result.
- Direct `repair_update` remains blocked for Direct HTTPS MVP.

The current SQLite database remains in place during Phase 19A.

## 3. Phase 17B decision recap

Phase 17B decided:

- SQLite remains pilot/local only.
- MariaDB/MySQL on Supermicro is the recommended production DB target.
- Current SQLite DB remains in place until a separately approved migration phase.
- IIS + PHP FastCGI is the recommended Windows production web-server model.
- HPE StoreEasy remains TLS termination reverse proxy for now.
- Backup and restore testing are required before any production cutover.

Phase 17B did not approve migration, driver switch, or production cutover.

## 4. IT-ADMIN versus Supermicro responsibilities

IT-ADMIN responsibilities:

- Prepare and review documentation changes in Git.
- Keep Phase 19A source changes documentation-only.
- Do not generate official packages or site kits.
- Do not edit live Supermicro `.env`.
- Do not access or print secret-bearing production values.

Supermicro responsibilities during a later approved rehearsal:

- Host the active Laravel app at `D:\inventory\laravel`.
- Pull reviewed Git changes from IT-ADMIN source work.
- Own official package and site-kit generation when separately approved.
- Host or coordinate the non-production rehearsal Laravel copy.
- Host or coordinate the separate MariaDB/MySQL rehearsal database.
- Keep live runner and collector traffic pointed only at approved live endpoints.
- Preserve live SQLite until a separate production migration phase is approved.

## 5. Required backups before rehearsal

Before any rehearsal, capture backups without printing secrets:

- SQLite database backup.
- Laravel raw archive backup.
- Generated downloads/site kits backup.
- Site token metadata through the DB backup.
- Relevant config metadata without exposing secrets.
- Git commit/revision reference.
- Redacted operational command outputs if collected.

Secret rules:

- Do not print `.env` values.
- Do not print `APP_KEY`.
- Do not print DB credentials.
- Do not print token secrets.
- Do not print token hashes.
- Do not print bearer tokens.
- Do not print raw CSV contents.
- Do not print command payload JSON.
- Do not print full configs.
- Do not print full runner GUIDs.

## 6. Dry-run environment requirements

Use a non-production dry-run environment.

Required isolation:

- Separate Laravel copy/path or isolated rehearsal environment.
- Separate MariaDB/MySQL database.
- Separate rehearsal `.env` copy only, not the live `.env`.
- Separate `APP_URL` or local-only binding.
- No live runner traffic pointed at the rehearsal DB.
- No live collector traffic pointed at the rehearsal DB.
- No official package generation from the rehearsal environment unless separately approved.
- No generated artifacts staged in Git.

The rehearsal environment must be disposable. It must not become the live system by accident.

## 7. MariaDB/MySQL installation assumptions

Phase 19A does not install MariaDB/MySQL.

For a future rehearsal, assume:

- MariaDB/MySQL is installed and running before the rehearsal starts.
- The database service is managed outside this runbook.
- The rehearsal database user has only the privileges needed for schema creation, data import, and validation in the rehearsal database.
- Credentials are stored only in the rehearsal `.env` copy and are never printed.
- Database character set and collation are compatible with Laravel 10 and project data.
- The live SQLite app remains untouched.

## 8. Empty target database preparation

For a future rehearsal:

1. Create a separate empty MariaDB/MySQL database for rehearsal.
2. Create or select a rehearsal-only database user.
3. Configure only the rehearsal Laravel `.env` copy to use the rehearsal database.
4. Confirm the live Supermicro `.env` still points to SQLite.
5. Confirm no runner or collector endpoints reference the rehearsal app or database.
6. Confirm the target database is empty before applying Laravel migrations.

Do not reuse a database that may contain old rehearsal rows unless it is intentionally dropped/recreated or otherwise reset under an approved rehearsal procedure.

## 9. Laravel migration strategy

Recommended migration approach:

1. Build a fresh MariaDB/MySQL schema using Laravel migrations.
2. Transfer data from a copied SQLite source using an app-aware transfer process.
3. Preserve primary keys, relationships, timestamps, JSON/text payloads, command state, token metadata, and raw evidence references.
4. Validate counts, relationships, portal behavior, and operational commands before considering any future cutover discussion.

Do not make direct SQLite-to-MySQL conversion the default strategy.

Direct SQLite-to-MySQL conversion is risky because of differences in:

- typing
- booleans
- JSON/text storage
- datetime behavior
- indexes
- foreign keys
- auto-increment behavior

## 10. SQLite source backup/copy strategy

Use a copied SQLite source, never the live SQLite database file directly.

Required approach:

1. Stop or avoid writes to the source copy operation when a final production migration is later approved.
2. For Phase 19A rehearsal planning, copy from a verified backup or controlled snapshot.
3. Store the copied SQLite file in a non-production rehearsal path.
4. Mark the copy read-only if practical.
5. Confirm the copied SQLite database is readable before transfer.
6. Record the Git commit/revision and backup timestamp used for the rehearsal.

For a final production migration later, require a write freeze or maintenance window.

## 11. Data transfer approach

The transfer must be app-aware and conservative.

Expected transfer characteristics:

- Reads only from the copied SQLite source.
- Writes only to the empty MariaDB/MySQL rehearsal database.
- Preserves critical primary keys.
- Preserves foreign-key relationships.
- Preserves `created_at` and `updated_at` values.
- Preserves nullable fields as NULL where source data is NULL.
- Preserves JSON/text payloads without printing sensitive contents.
- Preserves command lifecycle fields.
- Preserves raw archive path references.
- Preserves assignment override data as authoritative portal-managed state.

Do not use raw CSV files as the operational source of truth for transfer. CSV files are archived evidence only.

## 12. Integrity checks

Required data integrity checks:

- Table counts match.
- Critical primary keys are preserved.
- Foreign-key relationships remain valid.
- Created/updated timestamps are preserved.
- JSON/text payloads decode correctly.
- Token metadata exists without printing secrets.
- Runner GUIDs are present but not printed fully.
- Latest scans link to devices.
- Raw file records point to archived evidence paths.
- Command status, dispatch, ACK, completion, and `result_upload_id` fields are preserved.
- Collector and runner status records are visible.
- Device assignment override data remains authoritative.
- Storage health observations remain linked and visible.

Any mismatch must be explained before continuing.

## 13. Required table/count comparisons

Compare row counts for at least:

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
- classification / cleanup rules if present

Required subset comparisons:

- Direct HTTPS runner count.
- Collector-share runner count.
- Collector count.
- Pending command count.
- Delivered awaiting ACK count.
- Failed command count.
- Latest scan per device count.
- Raw files with missing archive path count.
- Devices with latest snapshot count.
- Storage health observations by risk level.

## 14. App-level validation checks

Required app-level validation:

- Laravel boots against MariaDB/MySQL rehearsal DB.
- Login works.
- Dashboard loads.
- Devices list loads.
- Device detail loads.
- Runners page loads.
- Collectors page loads.
- Command queue loads.
- Downloads page loads.
- Storage health page loads.
- Raw evidence page loads.
- Setup wizard loads.
- Reports pages load if available.
- No obvious HTTP 500 errors appear in Laravel logs.
- No secrets are exposed in portal pages or logs.

## 15. Portal validation checks

Check these portal routes/pages in the rehearsal app:

- `/dashboard`
- `/devices`
- at least 3 device detail pages
- `/runners`
- one Direct HTTPS runner detail page
- one collector-share runner detail page
- `/collectors`
- command queue route
- `/downloads`
- `/storage-health`
- raw evidence/raw files page
- `/setup-wizard`
- reports pages if available

Direct HTTPS portal checks:

- Direct HTTPS badge remains visible.
- Runner version `1.0.22` remains visible.
- Last heartbeat remains visible.
- Last direct poll remains visible.
- Last upload / Last Inventory remains visible.
- Direct `repair_update` remains blocked.

Collector-share portal checks:

- Collector status remains visible.
- Collector-share runners are not treated as missing Direct HTTPS poll.
- Command and ACK wording remains asynchronous.

## 16. Operational command validation

Run these from the rehearsal Laravel environment after the dry-run transfer:

```powershell
php artisan inventory:doctor
php artisan inventory:doctor --production
php artisan inventory:production-readiness
php artisan inventory:install-preflight
php artisan inventory:direct-pilot-status
php artisan inventory:direct-site-kit-audit
php artisan test
```

If the copied DB includes known Direct HTTPS pilot runners, also run:

```powershell
php artisan inventory:direct-runner-triage IT-ADMIN
php artisan inventory:direct-runner-triage LAPTOP-I76TA97E
```

Command outputs collected for evidence must be redacted. Do not include tokens, bearer values, token hashes, DB credentials, raw CSV contents, command payload JSON, `.env` values, `APP_KEY`, full configs, or full runner GUIDs.

## 17. Restore rehearsal

Required restore rehearsal:

1. Restore SQLite backup to a non-production path and confirm readability.
2. Restore raw archive backup to a non-production path.
3. Restore downloads/site kits backup to a non-production path.
4. Confirm portal/device/raw evidence references remain meaningful.
5. Dump MariaDB rehearsal DB.
6. Restore MariaDB dump into a second empty MariaDB rehearsal DB.
7. Point a rehearsal Laravel copy to the restored MariaDB DB.
8. Repeat doctor, production-readiness, portal smoke checks, and selected counts.

No restore test means no future production cutover.

## 18. Stop conditions

Abort rehearsal immediately if:

- Any secret is printed in logs, screenshots, console output, or docs.
- Live `.env` is changed.
- Live DB driver is switched.
- Live SQLite database is modified unexpectedly.
- Production data is mutated.
- Runners or collectors write to rehearsal DB unintentionally.
- Table counts do not match without explained reason.
- Critical primary key preservation fails.
- Command lifecycle state is corrupted.
- Token metadata is missing.
- Raw file references break.
- Portal login fails.
- Dashboard/devices/runners/collectors pages throw 500 errors.
- Direct `repair_update` becomes available for Direct HTTPS.
- Collector-share behavior changes.
- Backup restore fails.

Stop means pause the rehearsal, preserve evidence, identify the cause, and do not proceed to another transfer attempt until the failure mode is understood.

## 19. Rollback plan

Phase 19A does not change production, so rollback is documentation rollback only.

For a later approved rehearsal:

- Keep live SQLite app untouched.
- Keep live `.env` untouched.
- Keep live runner and collector endpoints untouched.
- Delete or archive failed rehearsal databases only after evidence is collected.
- Return the rehearsal Laravel copy to a known state.
- Restore rehearsal data from the pre-rehearsal backups if needed.
- Do not use a failed rehearsal database for production.

For a future production migration phase, rollback must be separately approved and must include a known-good SQLite backup, raw archive backup, downloads/site kits backup, maintenance-window communication, and a clear decision point before reopening writes.

## 20. Cutover-not-approved statement

Phase 19A does not approve production cutover.

Do not switch the live Supermicro app from SQLite to MariaDB/MySQL during Phase 19A. Do not change the live `.env`, mutate production data, point live runners or collectors at a rehearsal database, or announce the MariaDB/MySQL database as production.

Phase 19B follow-up: `docs/MARIADB_REHEARSAL_ENVIRONMENT_PREP.md` documents the recommended non-production rehearsal layout at `D:\inventory-rehearsal`, including separate Laravel path, source-copy locations, rehearsal `.env` handling, local-only `APP_URL`, and no-live-runner/collector guardrails.

Phase 19C follow-up: `docs/MARIADB_REHEARSAL_ENVIRONMENT_SETUP_CHECKLIST.md` documents the approved Option B filesystem-only setup checklist. It prepares folders, reviewed source, copied SQLite backup, raw archive, downloads/site kits, and `.env.rehearsal.example` only; it does not install MariaDB/MySQL or start migration work.

Phase 19D follow-up: `tools/Prepare-MariaDbRehearsalFilesystem.ps1` provides an optional dry-run-by-default helper for the Phase 19C filesystem-only preparation. The helper must be copied/run manually on Supermicro for execution and remains pre-migration only: no MariaDB/MySQL install, DB/user creation, migrations, real `.env`, app data transfer, web endpoint exposure, package generation, runner/collector traffic changes, or cutover approval.

Phase 19D Supermicro validation completed with expected `WARN`: dry-run reported skipped optional SQLite/raw archive/download copies, and `-Execute` created the rehearsal filesystem/source layout plus `.env.rehearsal.example` and non-secret notes without DB install, migrations, real `.env`, package generation, runner/collector traffic changes, or secret exposure.

Phase 19E completed the verified SQLite rehearsal source copy and evidence copy with expected `WARN`. The rehearsal SQLite source is now `D:\inventory-rehearsal\source-copy\database.sqlite`, read-only, `5,910,528` bytes, and integrity-checked as `ok` via PHP/PDO against the copied DB only. Raw archive and downloads/site kits were copied to the rehearsal filesystem without migration, DB install, DB/user creation, data transfer, package generation, live config change, runner/collector traffic change, production data mutation, or secret exposure.

Phase 19F MariaDB/MySQL rehearsal database setup is documented in `docs/MARIADB_REHEARSAL_DB_SETUP.md`. It prepares only the empty MariaDB/MySQL rehearsal database foundation on Supermicro and remains pre-migration: no Laravel migrations, app data transfer, SQLite-to-MySQL conversion, real rehearsal `.env`, live config changes, web exposure, package generation, runner/collector traffic changes, or cutover approval.

Phase 19F Supermicro runtime validation is complete: MariaDB `10.11.16-MariaDB` is running on reachable localhost port `3306`, both rehearsal schemas exist and are empty, `inventory_rehearsal_app` is scoped only to rehearsal schemas, and negative access testing failed as expected for unrelated/system DB access. No migrations, app data transfer, real rehearsal `.env`, live config changes, package generation, runner/collector traffic changes, or secret exposure occurred.

Phase 19G empty Laravel schema migration on the MariaDB rehearsal DB is complete. A real rehearsal `.env` exists only at `D:\inventory-rehearsal\laravel\.env`, with the new rehearsal `APP_KEY` and DB password not printed or recorded. Migrations ran only from `D:\inventory-rehearsal\laravel` against `inventory_rehearsal`, completed successfully, and all migrations show `Ran`; `inventory_rehearsal` now has `20` tables while `inventory_rehearsal_restore` remains empty. No app data transfer, SQLite-to-MySQL conversion, web endpoint exposure, package generation, live config change, runner/collector traffic change, production cutover, or secret exposure occurred.

Phase 19H app-aware SQLite-to-MariaDB transfer planning is documented in `docs/MARIADB_REHEARSAL_TRANSFER_PLAN.md`. It is Option A documentation-only: no transfer command, dry-run helper, data transfer, table reset, dump, restore, portal exposure, operational validation, live config change, runner/collector traffic change, package generation, or cutover approval.

Phase 19I implements dry-run-only command `php artisan inventory:mariadb-rehearsal-transfer --source="D:\inventory-rehearsal\source-copy\database.sqlite" --dry-run`. It requires explicit `--source` and `--dry-run`, has no `--execute`, and performs read-only source/target validation and previews only.

Phase 19I Supermicro dry-run validation completed with expected `WARN`. Hard boundaries passed, source SQLite integrity/readability passed, MariaDB target schema was reachable, `inventory_rehearsal_restore` remained empty, main counts/relationships/JSON summaries were produced without secrets, and guardrails failed as expected for missing `--source` and live SQLite source.

Phase 19J is the read-only evidence review phase for the Phase 19I warning `referenced_evidence_missing_count=353`. It documents how to determine whether the warning is caused by path mapping or genuinely missing copied evidence before any execute-mode planning.

Phase 19J manual Supermicro review concluded Outcome A: the raw evidence warning is explained by path-shape/path-mapping behavior, not missing copied evidence. Execute-mode transfer and cutover remain unapproved.

Phase 19K follow-up: `docs/MARIADB_REHEARSAL_EXECUTE_MODE_DESIGN.md` documents execute-mode architecture/design boundaries only. It records no-reset empty-target policy, dump-before-execute requirement, `classification_rules` compare-only handling, raw evidence path mapping policy, optional/schema difference policies, pre-execute gates, transfer order, and post-execute validation requirements. It does not approve `--execute`, data transfer, table reset/truncate, dump/restore execution, package generation, live config changes, runner/collector traffic changes, production migration, or cutover.

## 21. Future production migration gate

A future production migration phase requires separate approval and must include:

- Approved maintenance window or write freeze.
- Verified current backups.
- Restore rehearsal evidence.
- Successful MariaDB/MySQL dry-run evidence.
- Successful restore into a second MariaDB/MySQL rehearsal database.
- Green or accepted operational command results.
- Portal validation evidence.
- Command lifecycle validation evidence.
- Token metadata validation without secret exposure.
- Raw evidence reference validation.
- Explicit rollback decision point.
- Explicit production cutover approval.

Passing this runbook in rehearsal is not itself cutover approval.

## 22. Phase 19A acceptance criteria

Phase 19A is accepted when:

- MariaDB/MySQL migration dry-run purpose and non-goals are documented.
- Current source-of-truth state is documented.
- Phase 17B DB decision recap is documented.
- IT-ADMIN versus Supermicro responsibilities are documented.
- Required backup scope is documented.
- Dry-run environment isolation is documented.
- MariaDB/MySQL installation assumptions are documented without installing anything.
- Empty target database preparation is documented.
- Laravel migration strategy is documented.
- SQLite source backup/copy strategy is documented.
- App-aware data transfer approach is documented.
- Integrity checks are documented.
- Required table/count comparisons are documented.
- App-level validation checks are documented.
- Portal validation checks are documented.
- Operational command validation is documented.
- Restore rehearsal is documented.
- Stop conditions are documented.
- Rollback plan is documented.
- Cutover-not-approved statement is documented.
- Future production migration gate is documented.
- No application code is changed.
- No migration readiness command is added.
- No migration is run.
- No MariaDB/MySQL installation is performed.
- No live `.env` is changed.
- No DB driver is switched.
- No production data is mutated.
- No runner behavior is changed.
- No collector behavior is changed.
- No Direct HTTPS or collector API contracts are changed.
- No command lifecycle semantics are changed.
- Direct `repair_update` remains blocked.
- No token rotation UI or per-runner token enrollment is implemented.
- No official packages are generated from IT-ADMIN.
- No generated artifacts are staged.
- No secret-bearing files are staged.
