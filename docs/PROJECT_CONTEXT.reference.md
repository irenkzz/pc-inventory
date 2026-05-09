# Internal Windows PC Inventory System - Project Context

This Laravel repository contains:

- the Laravel 10 central replacement
- the Windows runner and branch collector deployment assets

Legacy Python/Flask files and old generated/download/archive data have been moved to sibling path `D:\xampp\htdocs\inventaris_py`.

The agreed architecture is still the same:

- Windows clients run a small scheduled-task runner
- a branch/site collector relays data outward to the central system
- Laravel is the central portal, importer, database application, and control plane
- the database is authoritative
- raw CSV files are archived evidence and fallback import input
- clients must not contain central database credentials, Google Drive credentials, central master secrets, or shared global upload tokens

## Current State Summary

As of May 6, 2026, the Laravel 10 port is the active central application and is materially beyond scaffolding.

Deployment/source-of-truth state:

- Active Laravel app runs on Supermicro at `D:\inventory\laravel`.
- IT-ADMIN development/Codex repo is `D:\xampp\htdocs\inventaris`.
- Git workflow is established: IT-ADMIN prepares source changes, Supermicro pulls reviewed changes.
- Official site kits/packages are generated only on Supermicro.
- Direct HTTPS endpoint is active at `https://inventory-pilot.internal.lan` through HPE StoreEasy HTTPS reverse proxy to Laravel on Supermicro.

Current implemented state:

- Laravel admin portal is running
- collector intake and runner heartbeat flows are working
- manual scan command flow is working
- runner repair/update flow has been reworked multiple times and the live package is now at `1.0.22`
- Direct HTTPS second pilot passed
- Direct HTTPS runner `1.0.22` validated on IT-ADMIN with scheduled task `LastTaskResult=0`, HTTPS health, heartbeat, poll, upload, and cleanup logs
- read-only Direct HTTPS pilot status command exists: `php artisan inventory:direct-pilot-status`
- read-only Direct HTTPS site-kit audit command exists: `php artisan inventory:direct-site-kit-audit`
- read-only Direct HTTPS runner triage command exists: `php artisan inventory:direct-runner-triage {runnerId}`
- read-only production readiness checklist command exists: `php artisan inventory:production-readiness`
- read-only installer/server preflight command exists: `php artisan inventory:install-preflight`
- authenticated read-only Portal Setup Wizard MVP page exists at `/setup-wizard`
- Phase 17B production deployment decision is documented in `docs/PRODUCTION_DEPLOYMENT_DECISION.md`
- Phase 18A installation productization strategy is documented in `docs/INSTALLATION_PRODUCTIZATION_STRATEGY.md`
- generated site kit and branch package include bundled `smartctl.exe` support for best-effort SSD health/TBW probing
- per-disk storage health observations and risk scoring are implemented in Laravel
- storage health has its own portal dashboard and is also surfaced on device detail pages and the main dashboard
- device assignment fields are portal-managed, not scanner-trusted
- project time display has been normalized to follow configured/system timezone behavior in Laravel views

Current known operational caveat:

- some machines expose storage through RAID/RST or other controller layers
- on those machines, SMART/NVMe write counters may not be readable even with bundled `smartctl`
- in those cases, SSD TBW fields remain blank by design because the runner does not fabricate values from weak metadata

## Repository Components

### Legacy Central Backend

Path: `..\inventaris_py\inventory_backend\`

The legacy backend is a Flask/Python central application with:

- portal pages for dashboard, devices, changes, runners, collectors, commands, and downloads
- API intake for JSON and CSV inventory payloads
- collector APIs for status, heartbeat, CSV upload, command polling, and command acknowledgement
- SQLite storage and repository/query modules
- CSV normalization, identity matching, ingest orchestration, and change detection
- CLI helpers for database initialization, importing folders, token registration, and site-kit generation

It remains useful for parity comparison, rollback reference, and historical behavior verification.

### Laravel 10 Central Application

Path: `laravel/`

The Laravel app is Laravel 10 and should stay Laravel 10 unless explicitly upgraded later.

Implemented surface includes:

- migrations and Eloquent models for devices, scans, snapshots, raw files, peripherals, network observations, assignments, changes, runners, collectors, sites, and commands
- services for:
  - CSV normalization
  - raw archive storage
  - weighted device identity matching
  - change detection
  - assignment override handling
  - per-disk storage health normalization and risk scoring
  - runner status upsert
  - collector status upsert
  - command queue management
  - site-token verification
  - site-kit building
  - operational diagnostics and backup
- authenticated Blade admin portal pages for:
  - dashboard
  - devices
  - changes
  - storage health
  - raw evidence
  - inventory review
  - runners
  - collectors
  - command queue
  - downloads
  - reports
- collector-facing API endpoints for:
  - collector status
  - runner heartbeat
  - CSV intake
  - command polling
  - command acknowledgement
- Artisan commands for:
  - import folder
  - rebuild current state
  - backup
  - doctor checks
  - collector diagnostics
  - Direct HTTPS pilot status, site-kit audit, and runner triage
  - production readiness checklist
  - site-token management
  - site profile preparation and validation
  - site-kit building
  - legacy comparison
  - collector cycle simulation

### Windows Runner

Path: `runner/`

The runner remains PowerShell/client-side and must not be replaced by PHP or redesigned into a browser-only scanner.

Responsibilities:

- run from Windows Scheduled Task
- execute local hardware inventory collection
- write CSV snapshots to the branch share
- write runner heartbeat JSON to the branch share
- consume command files from the branch share
- acknowledge commands back through branch-share JSON files
- handle manual scan and repair/update commands during runner cycles
- stage and apply local runner updates from the branch package

Key files:

- `runner/scripts/runner_main.ps1`
- `runner/scripts/scanner_core_v4.ps1`
- `runner/scripts/install_runner.ps1`
- `runner/scripts/bootstrap_update_runner.ps1`
- `runner/scripts/repair_runner.ps1`
- `runner/scripts/run_runner_hidden.vbs`
- `runner/config/runner-config.sample.json`
- `runner/manifest/runner-manifest.json`

Current runner behavior notes:

- normal runner execution is designed around current-user scheduled-task mode for share access
- hidden launcher support exists to avoid visible PowerShell windows during normal scheduled execution
- update flow is separate from normal scan flow and has gone through several fixes
- live package version is currently `1.0.22`
- Direct HTTPS local outbox cleanup runs only in `direct_https`
- Direct HTTPS cleanup never deletes `outbox/pending`
- Direct HTTPS `outbox/sent` cleanup deletes only files older than 14 days and outside newest 100
- Direct HTTPS `outbox/failed` cleanup deletes only files older than 30 days and outside newest 100
- Direct HTTPS cleanup logs summaries and warning-only failures do not fail the runner cycle

### Branch Collector / Relay

Path: `collector/`

The collector remains outside Laravel as a lightweight relay. It owns the site token and authenticates outward to the central Laravel server.

Responsibilities:

- initialize branch-share folder layout
- post collector status to Laravel
- flush runner heartbeat JSON files to Laravel
- upload inventory CSV files to Laravel collector intake
- flush command acknowledgement JSON files to Laravel
- pull pending commands from Laravel and write them to the branch share

Key files:

- `collector/relay.py`
- `collector/install_collector.ps1`
- `collector/run_collector_hidden.pyw`
- `collector/collector_config.sample.json`

### Deployment Assets

Paths:

- `deployment/`
- `laravel/storage/app/inventory/downloads/`
- legacy generated downloads are archived in `..\inventaris_py\downloads\`

Deployment support currently includes:

- sample and generated site profiles
- Laravel site-kit generation commands
- branch-friendly launcher scripts
- downloadable generated site kits
- branch package folders for runner current package and site-kit-current staging

Important current package state:

- official generated site kits/packages are produced only on Supermicro
- branch package contains runner `1.0.22`
- runner package includes bundled `smartctl.exe`, `drivedb.h`, and smartmontools license/readme files

### Data And Evidence

Paths:

- `..\inventaris_py\data\inventory.db`
- `..\inventaris_py\data\raw_archive\`
- `laravel/database/database.sqlite`
- `laravel/storage/app/inventory/raw_archive/`
- `..\inventaris_py\sample_data\`

CSV snapshots are treated as evidence and archived instead of overwritten.

Laravel stores authoritative inventory state in its database.

## What Stays Client-Side

Keep these responsibilities in PowerShell/Python runner or collector code:

- Windows hardware interrogation
- scheduled-task runner execution
- local runner state files
- branch-share writes and reads
- local repair/update bootstrap
- collector polling cycle
- site-token authenticated relay from branch to central
- file movement between live, archive, and failed branch-share folders

## What Belongs In Laravel

Keep or move these responsibilities into Laravel:

- authoritative device records
- weighted device identity matching
- raw file archive registration
- CSV/JSON normalization for central ingest
- scan history and hardware snapshots
- change detection and audit log
- current assignment and manual assignment override state
- runner and collector status visibility
- command queue creation, dispatch state, and acknowledgement processing
- admin portal pages and printable/CSV reports
- site-token storage and verification
- backup, diagnostics, legacy comparison, and site-kit generation commands

## Laravel Data Model Context

Core inventory tables are created in:

- `laravel/database/migrations/2026_04_20_000000_create_inventory_tables.php`

Core entities:

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

Additional migrations add:

- assignment override support
- classification rules
- department/site cleanup rules
- RAM detail columns
- SSD telemetry columns in `hardware_snapshots`
- `storage_health_observations` table for per-disk health/risk history

## Current Inventory Rules

### Identity

Device identity is weighted and must not rely on one serial field alone.

Strong identity signals:

- valid system UUID
- valid motherboard serial
- stable MAC address
- asset code

Placeholder or generic values must be treated as invalid or non-authoritative.

### Assignment

Department, site, location, and room are now treated as portal-managed fields.

Important rule:

- imported CSV values for assignment-like fields are retained as evidence in snapshots
- they are not trusted as authoritative current assignment state
- manual portal overrides define authoritative assignment state for reporting and dashboard summaries

### Change Tracking

Change tracking records:

- what changed
- old value
- new value
- observed time
- device
- site/department/room context

Typical severity model:

- critical: motherboard/system identity changes
- medium: major hardware changes
- minor: network/peripheral drift

Current tracked hardware details include:

- CPU
- GPU and GPU detail
- RAM total and RAM detail fields
- disk summary/detail
- SSD telemetry fields when available
- per-disk storage health observations and disappearance risk when a previously seen fixed disk goes missing

## Current Runner / Collector Command Flow

Portal commands are not direct remote execution.

Current flow:

1. portal queues a command in Laravel
2. collector polls Laravel and writes a command file to the branch share
3. runner sees the command on its next poll cycle
4. runner executes the command
5. runner writes CSV and/or command ack files to the branch share
6. collector relays those files back to Laravel
7. Laravel updates command state and portal status

Important consequence:

- `Manual scan` is asynchronous
- `Repair/update` is asynchronous
- collector participates twice: once to deliver commands, once to relay result/ack state

## Runner Identity / Hostname Rename Workaround

Runner IDs may still follow the Windows computer name on older installs. If a PC is renamed, Laravel can show both the old and new runner IDs even when device inventory correctly matches the same hardware.

Automatic behavior:

- During inventory ingest, if weighted device matching identifies the scan as an existing device and the previous scan's asset/runner name differs from the current runner ID, Laravel automatically merges the stale runner row into the current runner row.
- Automatic merge is conservative: the stale runner must exist, the current runner must exist, and the stale runner must not have an active pending/dispatched command.
- The old ID is recorded in `raw_state_json.previous_runner_ids`.

Operational fallback:

```powershell
cd laravel
php artisan inventory:rename-runner OLD-HOSTNAME NEW-HOSTNAME --dry-run
php artisan inventory:rename-runner OLD-HOSTNAME NEW-HOSTNAME
```

Use the manual command when a rename happened without a fresh inventory scan, when the old runner still has an active command, or when the old/new relationship needs human confirmation.

Longer-term rule:

- Device identity must continue to use weighted hardware evidence.
- Runner installs now generate or preserve a stable `runnerGuid` in `C:\ProgramData\InternalInventoryRunner\config\runner-config.json`.
- Runner heartbeat state includes `runner_guid`, and Laravel stores it on `runners.runner_guid`.
- If a hostname/`runnerId` changes but `runnerGuid` stays the same, Laravel automatically renames/merges the runner row and records the old ID in `raw_state_json.previous_runner_ids`.
- Command routing still uses the current `runner_id`/hostname for branch-share command filenames.
- If Windows is reinstalled and `C:\ProgramData` is lost, a new `runnerGuid` will be generated. Device identity still comes from weighted hardware evidence, so inventory scans can still match the existing device even though the runner installation identity is new.

## Current Deployment / Runner Version Context

Current known live package version:

- `1.0.22`

This package includes:

- current-user runner scheduling model
- hidden runner launcher support
- improved update staging logic
- stable generated/preserved runner GUID for hostname-change reconciliation
- bundled smartmontools files for best-effort SSD telemetry
- site-kit/current package updates on the branch share
- Direct HTTPS local outbox cleanup:
  - pending is never deleted
  - sent cleanup is older than 14 days and outside newest 100
  - failed cleanup is older than 30 days and outside newest 100
  - cleanup only runs in `direct_https`
  - cleanup logs summary and does not fail the runner cycle

Practical status of update flow:

- healthy runners already on the corrected model should be able to use `repair/update`
- badly broken or historically mis-permissioned installs may still require one corrective reinstall
- some older machines may have ACL/task-state baggage that makes portal self-repair unreliable until corrected once locally

## Operational Commands

Current read-only operational command set:

```powershell
php artisan inventory:direct-pilot-status
php artisan inventory:direct-site-kit-audit
php artisan inventory:direct-runner-triage {runnerId}
php artisan inventory:production-readiness
php artisan inventory:install-preflight
```

`inventory:direct-site-kit-audit` was completed in Phase 16A and validated on Supermicro. It audits generated Direct HTTPS site-kit artifacts for safe pilot use, including Direct HTTPS transport, HTTPS endpoint, stale HTTP endpoint, placeholder endpoint, runner version `1.0.22`, config/README presence, collector-share isolation, and secret redaction. It passed with an acceptable `WARN` because `collectorName` is present but is not required for Direct HTTPS active transport.

`inventory:direct-runner-triage {runnerId}` was completed in Phase 16B and validated on Supermicro. It triages one Direct HTTPS runner from database state and prints environment details, runner identity, masked GUID, timestamps, command counts, latest command summary, likely status, and safe next checks. It skips collector-share runners safely.

Phase 16B.1 refined runner triage failed-history behavior. Historical failed commands remain visible, but old failed commands superseded by a later succeeded command no longer force `ATTENTION`. Active or recent unresolved failed commands still trigger `ATTENTION`. Supermicro targeted validation passed with 17 tests and 91 assertions.

Observed Supermicro validation after Phase 16B.1:

- `IT-ADMIN`: runner version `1.0.22`, recent heartbeat, recent direct poll, latest command succeeded, historical failed commands superseded, likely status `OK`, `Result: PASS`.
- `LAPTOP-I76TA97E`: runner version `1.0.21`, stale heartbeat/poll/upload/ACK, latest command succeeded, likely status `stale/offline`, `Result: ATTENTION`.

Phase 17A `inventory:production-readiness` is implemented, validated on Supermicro, and documented. It is a Laravel-only read-only Artisan command that summarizes production/cutover readiness risks before moving beyond pilot mode. It distinguishes pilot readiness, Direct HTTPS small-site readiness, and production/cutover readiness, and explicitly states that passing checks is not production approval or cutover approval. Output is plain text with `[OK]`, `[WARN]`, `[FAIL]`, and `[INFO]`, and ends with exactly one result line: `Result: PASS`, `Result: WARN`, or `Result: FAIL`.

Phase 17A command sections:

- Environment
- Database
- HTTPS / Proxy
- Storage / Evidence
- Security / Secrets
- Operational Commands
- Direct HTTPS Readiness
- Collector-share Readiness
- Cutover Blockers
- Result

Phase 17A safety:

- Does not run nested Artisan commands.
- Does not mutate DB records.
- Does not write files.
- Does not clear cache/config.
- Does not run migrations.
- Does not touch runner or collector files.
- Does not generate backups or site kits.
- Does not trigger commands.
- Does not change Direct HTTPS API contracts.
- Does not change command polling/ACK semantics.
- Does not enable Direct `repair_update`.
- Does not modify collector-share behavior.
- Does not print secrets.

Phase 17A Supermicro validation at `D:\inventory\laravel`:

- `APP_URL` is HTTPS: `https://inventory-pilot.internal.lan`.
- `APP_ENV` is `local`.
- `APP_DEBUG=true` remains an expected warning.
- Database driver is SQLite, database is reachable, migrations table is reachable, and no pending migrations were detected.
- Storage, downloads, raw archive, and backup paths exist and are writable.
- Recent backup presence was detected.
- `APP_KEY` presence is reported without printing the value.
- Site token metadata count is shown without secrets.
- Required operational commands are registered: `inventory:doctor`, `inventory:direct-pilot-status`, `inventory:direct-site-kit-audit`, and `inventory:direct-runner-triage`.
- Direct HTTPS runner count: 2.
- Stale Direct HTTPS runner count: 0.
- Direct HTTPS runners on expected version `1.0.22` count: 2.
- Direct `repair_update` remains blocked for Direct HTTPS MVP.
- Collector-share runner count: 36.
- Collector count: 3.
- Collector-share remains supported and remains the main HQ/multi-PC mode.
- Final result: `WARN`.

Expected Phase 17A warnings currently shown:

- `APP_ENV` is not production.
- `APP_DEBUG=true` outside production.
- SQLite production DB decision unresolved.
- `APP_URL` uses pilot/internal hostname.
- Trusted proxy / forwarded HTTPS headers cannot be fully proven from CLI.
- Token rotation UI not done.
- Per-runner token enrollment not done.
- Advanced rate limiting not done.
- Direct HTTPS rollout remains manual package refresh/reinstall.
- Larger rollout not validated.
- Production web-server/process/TLS model not finalized.
- Direct `repair_update` unsupported for Direct HTTPS.
- Backup policy not verified.

Phase 17A tests:

- `Tests\Feature\ProductionReadinessCommandTest` passed: 16 tests, 61 assertions.
- Full Laravel test suite previously passed after implementation: 224 tests, 1005 assertions.

Phase 17B production deployment decision is documented in `docs/PRODUCTION_DEPLOYMENT_DECISION.md`. It is planning/documentation only, not cutover approval. It keeps SQLite pilot-only, recommends MariaDB/MySQL on Supermicro as the production DB target, recommends IIS + PHP FastCGI for Windows production serving, keeps HPE StoreEasy as TLS termination reverse proxy for now, and requires backup plus restore testing before production cutover. It does not change code, runner/collector behavior, API contracts, command semantics, Direct `repair_update`, token UI, production data, `.env`, or generated artifacts.

Phase 18B `inventory:install-preflight` is implemented, validated on Supermicro, and documented. It is a Laravel-only read-only preflight for future productized installation/setup/package flows. It checks Environment, Laravel Host, Application URL / HTTPS, Storage and Package Paths, Operational Commands, Package / Site-kit Generation Safety, Direct HTTPS Productization Notes, Collector-share Productization Notes, Setup Wizard Readiness, MVP Manual Boundaries, Security / Secret Redaction, Recommended Next Checks, and Result.

Phase 18B safety: it is not an installer and not the portal setup wizard. It does not generate packages, site kits, backups, or tokens; does not mutate database records; does not write files; does not run migrations or nested Artisan commands; does not change `.env`; and does not expose secrets.

Phase 18B Supermicro validation at `D:\inventory\laravel`: host/path was detected as the approved Supermicro active Laravel host, official package/site-kit generation was allowed only on that host, `APP_URL` used HTTPS and was not placeholder/example or localhost/loopback, Laravel storage/inventory/downloads/raw archive/backup paths were readable and writable, required operational commands and optional package/profile/backup commands were detected, Direct HTTPS was described as small/no-IT transport, collector-share was described as main HQ/multi-PC mode, Setup Wizard MVP was reported as not implemented yet, secrets were not printed, and final result was `WARN`. Expected warnings were `APP_ENV=local`, `APP_DEBUG=true`, pilot/internal hostname, CLI inability to fully prove trusted proxy headers, backup policy/restore rehearsal not verified, and Setup Wizard MVP not implemented.

Phase 18B tests: `Tests\Feature\InstallPreflightCommandTest` passed with 21 tests and 56 assertions. The full Laravel suite passed after implementation with 245 tests and 1061 assertions.

Phase 18C `GET /setup-wizard` is implemented, validated on Supermicro, and documented. It is an authenticated admin portal page and read-only guided setup MVP. It shows the seven-step productized setup flow, safe `APP_URL` / HTTPS labels, existing site/runner/collector counts only, Direct HTTPS / collector-share / hybrid deployment-mode guidance, links to existing runners/collectors/commands/downloads pages, verification checklists, and a secret-redaction footer. It references `inventory:install-preflight` and `inventory:direct-site-kit-audit` without running nested Artisan commands, and adds an Operations navigation link.

Phase 18C safety: it does not create users, organization/company schema, sites, tokens, token rotation, per-runner enrollment, packages, site kits, backups, migrations, `.env` changes, production approval, or production data mutations. It does not touch runner/collector files, change Direct HTTPS API contracts, change command lifecycle semantics, enable Direct `repair_update`, or expose secrets. Collector-share remains supported and unaffected.

Phase 18C validation: `Tests\Feature\SetupWizardMvpTest` passed with 8 tests and 52 assertions. The full Laravel suite passed after implementation with 253 tests and 1113 assertions. Manual portal validation after merge/pull confirmed `/setup-wizard` loads for authenticated admin, read-only status and non-cutover wording are clear, Direct HTTPS / collector-share / hybrid modes are understandable, verification links are present, and no secrets are displayed.

Operational safety notes:

- Direct HTTPS remains a second transport for small/no-IT sites.
- Collector-share remains supported and unaffected.
- Direct `repair_update` remains blocked for Direct HTTPS MVP.
- Commands are asynchronous: polling means delivery, ACK is execution result.
- Database is authoritative.
- CSV files are archived evidence only.
- Google Drive is backup/sync only.
- Official site kits/packages are generated only on Supermicro.
- Do not print token secrets, bearer tokens, token hashes, DB credentials, Google credentials, raw CSV contents, command payload JSON, `.env` values, `APP_KEY` values, full configs, or full runner GUIDs.

## SSD Telemetry / TBW Context

Legacy compact fields still kept on `hardware_snapshots`:

- `ssd_tbw_bytes`
- `ssd_tbw_gb`
- `ssd_percentage_used`
- `ssd_power_on_hours`
- `ssd_health_source`
- `ssd_health_detail`

Newer runner/import path:

- runner emits `Storage_Health_JSON` when disk-level SMART/NVMe telemetry can be collected
- Laravel parses that payload into `storage_health_observations`
- each observation is normalized into disk identity, capacity, SMART availability, TBW, wear, temperature, sector/error metrics, risk level, risk score, reasons, and recommended action

Collection method:

- runner uses bundled `smartctl.exe` first
- runner tries multiple device backends (`auto`, `sat`, `scsi`, `nvme`) when probing Windows physical drives
- if telemetry is readable, it is stored in snapshot fields and shown in Laravel
- if per-disk telemetry is readable, it is also stored as first-class `storage_health_observations`

Known limitation:

- if a machine exposes storage only through RAID/RST/controller layers that block SMART passthrough, TBW may remain blank
- this is a hardware/controller visibility limitation, not a Laravel display bug

Practical interpretation:

- blank TBW on some machines is currently expected
- partial telemetry such as power-on-hours without TBW is also possible

## Storage Health & Backup Risk Monitor

The Laravel application stores per-disk storage health observations in addition to the legacy compact SSD telemetry fields on `hardware_snapshots`.

Supported probing tool:

- `smartctl.exe` from the bundled smartmontools package is the only supported storage health probing tool for now.
- Vendor-specific SSD tools are intentionally excluded from the runner package.

Operational rules:

- TBW is best-effort telemetry, not the only risk signal.
- NVMe Data Units Written are converted with `data_units_written * 512000`.
- If TBW, SMART, or NVMe counters are unavailable, the runner and importer store blank/NULL values, not zero.
- RAID, Intel RST, USB bridges, and other controller layers may block SMART/NVMe passthrough.
- Blank TBW is expected on some machines and is not a scan failure.
- Administrators should prioritize `risk_level`, `risk_reasons`, and `recommended_action` over TBW alone.

Risk inputs include:

- SSD SMART health, percentage used/wear, available spare, media/data integrity errors, power-on hours, temperature, and TBW when available.
- HDD SMART health, reallocated sectors, current pending sectors, offline uncorrectable sectors, power-on hours, temperature, and sector-count trend between scans.
- unexpected disappearance of previously seen fixed disks between scans, which is treated as a critical observation unless the disk looks removable/external

Portal visibility:

- `/storage-health` shows latest per-disk risk observations.
- Device detail pages include a storage health section.
- The main dashboard highlights critical and unknown storage observations.

## Current Porting Status

The port is no longer just a plan. It is an active Laravel 10 system with real deployment and pilot behavior.

Completed or materially implemented:

- Laravel 10 application scaffold and module structure
- core migrations and models
- import, matching, archive, and change-detection services
- runner/collector/command APIs
- authenticated admin portal pages
- operational Artisan commands
- downloadable site-kit generation
- current-user runner deployment model
- hidden collector and runner launcher support
- Direct HTTPS runner installer wrapper MVP
- Collector-site installer wrapper MVP
- SSD telemetry ingestion/display plumbing
- per-disk storage health ingest, scoring, and portal visibility
- substantial feature and unit test coverage

Still operationally sensitive:

- runner self-update hardening across historically broken installs
- validating mixed storage-controller SSD telemetry behavior across multiple PCs
- parity and pilot checks against legacy backend data

## Migration / Validation Plan

### Phase 1: Legacy Mapping And Behavioral Parity

1. Keep Flask backend available as reference.
2. Compare Flask concepts to Laravel modules.
3. Preserve exact behavior where it matters:
   - raw archive behavior
   - duplicate raw hash handling
   - site-token boundary
   - command dispatch/ack state transitions
   - runner heartbeat field semantics
   - identity weighting and invalid-serial handling
   - change severity assignment

### Phase 2: Data And Import Validation

1. Run Laravel migrations on a clean local database when needed.
2. Import `..\..\inventaris_py\sample_data\` and selected real historical CSV batches when running from `laravel/`.
3. Run legacy comparison against `..\..\inventaris_py\data\inventory.db` when running from `laravel/`.
4. review deltas in devices, scans, raw files, changes, runners, collectors, and commands.
5. add targeted tests before changing business logic.

### Phase 3: Portal / Collector / Runner Validation

1. verify `/health`
2. verify collector status, heartbeat, CSV intake, command poll, and command ack APIs
3. verify manual scan flow from portal through collector and runner back to Laravel
4. verify repair/update flow on healthy runners
5. verify portal pages and reports against real branch-share activity

### Phase 4: Operational Hardening

1. run `php artisan inventory:doctor`
2. run `php artisan inventory:doctor --production` before cutover
3. run `php artisan inventory:production-readiness` before moving beyond pilot mode
4. run `php artisan inventory:install-preflight` before productized setup/package generation review
5. keep backups of:
   - Laravel DB
   - raw archives
   - generated downloads/site kits
   - site tokens
   - legacy DB
6. freeze legacy writes before final parity/cutover if full migration is performed

## Useful Laravel Commands

Run from `laravel/`:

```powershell
php artisan --version
php artisan migrate
php artisan inventory:doctor
php artisan inventory:direct-pilot-status
php artisan inventory:direct-site-kit-audit
php artisan inventory:direct-runner-triage IT-ADMIN
php artisan inventory:production-readiness
php artisan inventory:install-preflight
php artisan inventory:import-folder ..\..\inventaris_py\sample_data
php artisan inventory:compare-legacy ..\..\inventaris_py\data\inventory.db
php artisan inventory:register-site-token SITE-HQ
php artisan inventory:site-tokens
php artisan inventory:collector-diagnostic http://127.0.0.1:8000 SITE-HQ <site-token>
php artisan inventory:simulate-collector ..\..\inventaris_py\sample_data\sample_scan_1.csv --queue-scan --ack
php artisan inventory:build-site-kit --profile=..\deployment\profiles\generated\SITE-HQ.json
php artisan inventory:backup --label=pre-cutover
```

## Assumptions / Known Open Questions

- SQLite is still acceptable for local development and pilot, but long-term production DB choice is not finalized.
- Direct `repair_update` remains blocked for Direct HTTPS MVP.
- Direct HTTPS rollout remains manual package refresh/reinstall for now; Phase 18D Direct HTTPS installer wrapper is implemented, live-validated with manual Scheduled Task trigger on `LAPTOP-I76TA97E`, and documented.
- Phase 18E collector-site installer wrapper is implemented, package-included, and validated to the MVP boundary: generated collector-share config, HTTPS `/health`, branch-share read/write probe, runner staging, and config backup passed before the managed-host Python dependency check stopped installation cleanly.
- Phase 18E.1 improves collector installer Python dependency handling: collector-share MVP requires Python 3.x on the collector host, missing Python now fails clearly without the old `Source` property error, Python is not auto-installed or bundled, small/no-IT sites should use Direct HTTPS mode, and future productization may bundle Python or package the collector as a self-contained executable/service.
- Phase 18F Package UX Simplification and Mode-specific Installer Entry Points is implemented, Supermicro package-validated, and documented. It adds mode-specific package UX files without runtime/API/command/token changes: Direct HTTPS packages include `INSTALL_THIS_PC_DIRECT_HTTPS_RUNNER.cmd` and `README_DIRECT_HTTPS_RUNNER.txt` while retaining `INSTALL_THIS_PC_RUNNER_ONLY.cmd`; collector-share packages include `INSTALL_COLLECTOR_SITE.cmd` and `README_COLLECTOR_SITE.txt` while retaining existing collector launchers. Wrong-mode top-level launchers are not exposed. Hybrid means using both modes across different sites, not mixing modes inside one runner installation. Supermicro validation built both Direct HTTPS and collector-share packages, confirmed the expected files and README wording, confirmed Direct HTTPS audit `WARN` only for the acceptable `collectorName` note, and confirmed no obvious rendered secret.
- Phase 18 roadmap status: 18A Installation Productization Strategy done; 18B installer/server preflight done; 18C Portal Setup Wizard MVP done; 18D Direct HTTPS Runner Installer MVP done; 18E Collector-site Installer MVP done; 18E.1 Collector Python dependency handling done; 18F Package UX Simplification and Mode-specific Installer Entry Points done.
- Phase 19A MariaDB/MySQL migration dry-run runbook is documented in `docs/MARIADB_MIGRATION_DRY_RUN_RUNBOOK.md`. It is documentation-only: no migration, no MariaDB/MySQL installation, no readiness command, no live `.env` change, no DB driver switch, no production data mutation, no package generation, and no cutover approval.
- token rotation UI is still not done.
- per-runner token enrollment is still not done.
- some SSD telemetry gaps are expected on RAID/RST-backed clients.
- if the organization wants fleet-wide reliable TBW beyond best effort, a deeper storage telemetry tool or vendor-specific approach may be required.
- Google Drive remains backup/sync only, not operational source of truth.
- full software license management, endpoint management, and remote support remain out of scope unless explicitly added later.
