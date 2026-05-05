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

As of April 30, 2026, the Laravel 10 port is the active central application and is materially beyond scaffolding.

Current implemented state:

- Laravel admin portal is running
- collector intake and runner heartbeat flows are working
- manual scan command flow is working
- runner repair/update flow has been reworked multiple times and the live package is now at `1.0.21`
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
- live package version is currently `1.0.21`

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

- generated site kit for `SITE-HQ` exists
- branch package contains runner `1.0.21`
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

- `1.0.21`

This package includes:

- current-user runner scheduling model
- hidden runner launcher support
- improved update staging logic
- stable generated/preserved runner GUID for hostname-change reconciliation
- bundled smartmontools files for best-effort SSD telemetry
- site-kit/current package updates on the branch share

Practical status of update flow:

- healthy runners already on the corrected model should be able to use `repair/update`
- badly broken or historically mis-permissioned installs may still require one corrective reinstall
- some older machines may have ACL/task-state baggage that makes portal self-repair unreliable until corrected once locally

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
3. keep backups of:
   - Laravel DB
   - raw archives
   - generated downloads/site kits
   - site tokens
   - legacy DB
4. freeze legacy writes before final parity/cutover if full migration is performed

## Useful Laravel Commands

Run from `laravel/`:

```powershell
php artisan --version
php artisan migrate
php artisan inventory:doctor
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
- real production web-server/process model is not finalized.
- real production TLS approach is not finalized.
- some SSD telemetry gaps are expected on RAID/RST-backed clients.
- if the organization wants fleet-wide reliable TBW beyond best effort, a deeper storage telemetry tool or vendor-specific approach may be required.
- Google Drive remains backup/sync only, not operational source of truth.
- full software license management, endpoint management, and remote support remain out of scope unless explicitly added later.
