# CODEX_CONTEXT.extended.md — On-Demand Project Reference

Use this only when a task needs more detail than `AGENTS.md`. Do not auto-load this for small edits.

## Project Shape

The repository contains three active areas:

- `laravel/`: Laravel 10 central portal, importer, database application, and control plane.
- `runner/`: Windows PowerShell runner installed on clients and executed by Scheduled Task.
- `collector/`: lightweight Python/PowerShell site relay that authenticates outward to Laravel.

Legacy Flask/Python backend lives at `..\inventaris_py\inventory_backend\` and is reference-only for parity, rollback, and behavior checks.

## Current Implementation State

The Laravel 10 port is active and materially implemented, not just scaffolding.

Implemented or materially present:
- admin portal
- collector intake
- runner heartbeat
- manual scan command flow
- repair/update flow
- site-kit generation
- SSD telemetry and storage health dashboard
- device detail storage health
- assignment override behavior
- operational diagnostics and backups
- legacy comparison tooling

Current known package/state:
- active Laravel app: Supermicro at `D:\inventory\laravel`
- IT-ADMIN development/Codex repo: `D:\xampp\htdocs\inventaris`
- Git workflow: IT-ADMIN prepares source changes; Supermicro pulls reviewed changes
- official packages/site kits are generated only on Supermicro
- Direct HTTPS endpoint: `https://inventory-pilot.internal.lan` through HPE StoreEasy HTTPS reverse proxy to Laravel on Supermicro
- Direct HTTPS second pilot passed
- live runner package: `1.0.22`
- runner package includes `smartctl.exe`, `drivedb.h`, and smartmontools license/readme files
- runner `1.0.22` includes Direct HTTPS local outbox cleanup
- IT-ADMIN validated runner `1.0.22` with scheduled task `LastTaskResult=0`, HTTPS health, heartbeat, poll, upload, and cleanup logs
- Phase 16A read-only Direct HTTPS site-kit audit command exists: `php artisan inventory:direct-site-kit-audit`
- Phase 16B read-only Direct HTTPS runner triage command exists: `php artisan inventory:direct-runner-triage {runnerId}`
- Phase 16B.1 refined runner triage so old failed commands superseded by later success do not force `ATTENTION`
- Phase 17A read-only production readiness checklist command exists: `php artisan inventory:production-readiness`
- Phase 17B production deployment decision is documented in `docs/PRODUCTION_DEPLOYMENT_DECISION.md`
- Phase 18B read-only installer/server preflight command exists: `php artisan inventory:install-preflight`
- Phase 18C authenticated read-only Portal Setup Wizard MVP exists at `/setup-wizard`
- collector-share mode remains supported and unaffected

## Laravel Responsibilities

Laravel owns:
- authoritative devices, scans, hardware snapshots, raw files, peripherals, network observations, assignments, changes, runners, collectors, sites, and commands
- CSV/JSON normalization
- raw archive storage and duplicate raw hash behavior
- weighted device identity matching
- change detection and audit log
- assignment override handling
- per-disk storage health normalization and risk scoring
- runner/collector status upsert
- command queue creation, dispatch state, acknowledgement processing
- site-token verification
- site-kit building
- backups, diagnostics, reports, and legacy comparison

Main portal areas:
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

Collector-facing APIs include:
- collector status
- runner heartbeat
- CSV intake
- command polling
- command acknowledgement

## Runner Responsibilities

Runner remains PowerShell/client-side. Do not redesign it into PHP or browser-only scanning.

Runner owns:
- Windows hardware interrogation
- Scheduled Task execution
- local runner state files
- branch-share reads/writes
- heartbeat JSON writes
- CSV snapshot writes
- command file consumption
- manual scan execution during runner cycles
- repair/update bootstrap
- bundled `smartctl.exe` probing

Key files:
- `runner/scripts/runner_main.ps1`
- `runner/scripts/scanner_core_v4.ps1`
- `runner/scripts/install_runner.ps1`
- `runner/scripts/bootstrap_update_runner.ps1`
- `runner/scripts/repair_runner.ps1`
- `runner/scripts/run_runner_hidden.vbs`
- `runner/config/runner-config.sample.json`
- `runner/manifest/runner-manifest.json`

## Collector Responsibilities

Collector remains a lightweight relay outside Laravel.

Collector owns:
- branch-share folder layout
- collector status posts
- runner heartbeat relay
- CSV upload relay
- command acknowledgement relay
- pending command polling and branch-share command file writes
- site-token authenticated outward communication

Key files:
- `collector/relay.py`
- `collector/install_collector.ps1`
- `collector/run_collector_hidden.pyw`
- `collector/collector_config.sample.json`

## Data and Evidence

Relevant paths:
- `..\inventaris_py\data\inventory.db`
- `..\inventaris_py\data\raw_archive\`
- `laravel/database/database.sqlite`
- `laravel/storage/app/inventory/raw_archive/`
- `..\inventaris_py\sample_data\`

Rules:
- database is authoritative
- raw CSV snapshots are archived evidence/fallback input
- raw snapshots must not be overwritten
- Google Drive is backup/sync only
- dashboard reads database state, not raw CSV directly

## Data Model Context

Core entities include:
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
- RAM detail fields
- SSD telemetry fields
- per-disk storage health history

## Identity and Assignment

Identity must be weighted. Strong evidence includes:
- valid system UUID
- valid motherboard serial
- stable MAC address
- asset code

Medium evidence includes:
- computer name
- disk detail patterns
- stable user/location correlation

Weak/invalid evidence includes:
- generic BIOS strings
- placeholder/generic serials
- temporary hostname changes

Assignment rules:
- department, site, location, and room are portal-managed
- imported CSV assignment-like fields are evidence only
- manual portal overrides define authoritative reporting/dashboard assignment state

## Change Tracking

Change log should record:
- what changed
- old value
- new value
- observed time
- device
- site/department/room context

Typical severity:
- critical: motherboard/system identity changes or unexpected fixed-disk disappearance
- medium: major hardware changes
- minor: network/peripheral drift

Tracked hardware includes CPU, GPU/detail, RAM total/detail, disk summary/detail, SSD telemetry, and per-disk storage health observations.

## Command Lifecycle

Portal commands are not direct remote execution.

Flow:
1. Laravel queues command.
2. Collector polls Laravel.
3. Collector writes command file to branch share.
4. Runner sees command during next poll/cycle.
5. Runner executes locally.
6. Runner writes CSV and/or ack JSON to branch share.
7. Collector relays results/acks to Laravel.
8. Laravel updates command state and portal status.

Manual scan and repair/update are asynchronous. Collector participates twice: delivery and result/ack relay.

Direct HTTPS commands are also asynchronous:

- polling means delivery, not execution
- ACK is the execution result
- Direct `repair_update` remains blocked for Direct HTTPS MVP

Current operational command set:

```powershell
php artisan inventory:direct-pilot-status
php artisan inventory:direct-site-kit-audit
php artisan inventory:direct-runner-triage {runnerId}
php artisan inventory:production-readiness
php artisan inventory:install-preflight
```

Phase 16A `inventory:direct-site-kit-audit` is read-only and was validated on Supermicro. It audits generated Direct HTTPS site-kit artifacts for transport mode, HTTPS endpoint, stale HTTP endpoint, placeholder endpoint, runner version `1.0.22`, config/README presence, collector-share isolation, and secret redaction. The Supermicro audit passed with an acceptable `WARN` because `collectorName` is present but not required for Direct HTTPS active transport.

Phase 16B `inventory:direct-runner-triage {runnerId}` is read-only and was validated on Supermicro. It triages one Direct HTTPS runner from database state, prints masked identity and timestamp/command summaries, skips collector-share runners safely, and avoids printing tokens, bearer values, token hashes, full configs, raw CSV, command payload JSON, or full runner GUIDs.

Phase 16B.1 keeps historical failed commands visible while preventing old failures superseded by a later succeeded command from forcing `ATTENTION`. Active or recent unresolved failed commands still trigger `ATTENTION`. Supermicro targeted validation passed with 17 tests and 91 assertions. `IT-ADMIN` reports `OK`/`PASS` when heartbeat and poll are fresh and the latest command succeeded; `LAPTOP-I76TA97E` still reports `stale/offline`/`ATTENTION` when stale.

Phase 17A `inventory:production-readiness` is implemented, validated on Supermicro, and documented. It is a Laravel-only read-only Artisan command that summarizes production/cutover readiness risks before moving beyond pilot mode. It distinguishes pilot readiness, Direct HTTPS small-site readiness, and production/cutover readiness, and explicitly states that passing checks is not production approval or cutover approval. Output is plain text with `[OK]`, `[WARN]`, `[FAIL]`, and `[INFO]`, and ends with exactly one result line: `Result: PASS`, `Result: WARN`, or `Result: FAIL`.

Phase 17A sections:

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

Phase 17A Supermicro validation at `D:\inventory\laravel`: `APP_URL=https://inventory-pilot.internal.lan`, `APP_ENV=local`, `APP_DEBUG=true` warning expected, SQLite reachable, migrations table reachable, no pending migrations, storage/downloads/raw archive/backup paths writable, recent backup detected, required operational commands registered, Direct HTTPS runner count 2, stale Direct HTTPS runner count 0, Direct HTTPS runners on `1.0.22` count 2, collector-share runner count 36, collector count 3, Direct `repair_update` blocked, final result `WARN`.

Expected Phase 17A warnings currently include non-production env/debug state, unresolved SQLite production DB decision, pilot/internal hostname, CLI inability to fully prove trusted proxy headers, token rotation UI not done, per-runner token enrollment not done, advanced rate limiting not done, manual Direct HTTPS package refresh/reinstall, larger rollout not validated, production web-server/process/TLS model not finalized, Direct `repair_update` unsupported for Direct HTTPS, and backup policy not verified.

Phase 17A safety: no nested Artisan commands, DB mutation, file writes, cache/config clearing, migrations, runner/collector file access, backup generation, site-kit generation, command triggering, Direct HTTPS API contract changes, command polling/ACK semantic changes, Direct `repair_update` enablement, collector-share behavior changes, or secret printing.

Phase 17B production deployment decision is documented in `docs/PRODUCTION_DEPLOYMENT_DECISION.md`. It is planning/documentation only and does not approve cutover. It keeps SQLite pilot-only, recommends MariaDB/MySQL on Supermicro as the production DB target, recommends IIS + PHP FastCGI for Windows production serving, keeps HPE StoreEasy as TLS termination reverse proxy for now, and requires backup plus restore testing before cutover. It does not change code, runner/collector behavior, API contracts, command semantics, Direct `repair_update`, token UI, production data, `.env`, or generated artifacts.

Phase 18B `inventory:install-preflight` is implemented, validated on Supermicro, and documented. It is a Laravel-only read-only Artisan command for future productized installation, setup wizard, and package/site-kit generation flows. It checks Environment, Laravel Host, Application URL / HTTPS, Storage and Package Paths, Operational Commands, Package / Site-kit Generation Safety, Direct HTTPS and collector-share productization notes, Setup Wizard readiness, MVP manual boundaries, secret-redaction rules, recommended next checks, and final Result.

Phase 18B Supermicro validation at `D:\inventory\laravel`: the approved Supermicro host/path was detected, official package/site-kit generation was allowed only there, `APP_URL` used HTTPS and was not placeholder/example or localhost/loopback, storage/downloads/raw archive/backup paths were readable and writable, required and optional operational commands were detected without running nested Artisan commands, Direct HTTPS remained the small/no-IT transport, collector-share remained the main HQ/multi-PC mode, Setup Wizard MVP was reported as not implemented yet, secrets were not printed, and final result was `WARN`. Expected warnings were local/debug pilot state, pilot/internal hostname, trusted proxy headers not fully provable from CLI, backup/restore rehearsal policy not verified, and Setup Wizard MVP not implemented. Tests passed: `Tests\Feature\InstallPreflightCommandTest` 21 tests/56 assertions; full Laravel suite 245 tests/1061 assertions.

Phase 18B safety: it is not an installer and not the portal setup wizard. It does not generate packages, site kits, backups, or tokens; does not mutate database records; does not write files; does not run migrations; does not run nested Artisan commands; does not change `.env`; does not expose secrets; does not change Direct HTTPS API contracts; does not change command lifecycle semantics; and does not enable Direct `repair_update`.

Phase 18C `GET /setup-wizard` is implemented, validated on Supermicro, and documented. It is an authenticated admin portal page and read-only guided setup MVP. It shows the seven-step productized setup flow, safe `APP_URL` / HTTPS labels, existing site/runner/collector counts only, Direct HTTPS / collector-share / hybrid deployment modes, links to existing runners, collectors, command queue, and downloads pages, verification checklists, and a secret-redaction footer. It references `inventory:install-preflight` and `inventory:direct-site-kit-audit` without running them, and adds an Operations navigation link.

Phase 18C safety: it does not create users, sites, tokens, token rotation, per-runner enrollment, packages, site kits, migrations, `.env` changes, production approval, or production data mutations. It does not touch runner/collector files, change Direct HTTPS API contracts, change command lifecycle semantics, enable Direct `repair_update`, or expose secrets. Tests passed: `Tests\Feature\SetupWizardMvpTest` 8 tests/52 assertions; full Laravel suite 253 tests/1113 assertions. Manual portal validation confirmed `/setup-wizard` loads for authenticated admin, the page is clearly read-only and not production/cutover approval, mode guidance is understandable, verification links are present, and no secrets are displayed.

## Runner Rename / GUID Behavior

Older runner IDs may follow Windows hostname. If a PC is renamed, Laravel can show old/new runner rows even if device inventory still matches the same hardware.

Current behavior:
- runner installs generate or preserve `runnerGuid`
- Laravel stores `runner_guid`
- if hostname/runnerId changes but runnerGuid or weighted device identity matches, Laravel can merge/rename stale runner rows conservatively
- stale runner must not have active pending/dispatched commands for automatic merge
- old IDs are recorded in `raw_state_json.previous_runner_ids`
- command routing still uses current runner_id/hostname

Manual fallback:

```powershell
cd laravel
php artisan inventory:rename-runner OLD-HOSTNAME NEW-HOSTNAME --dry-run
php artisan inventory:rename-runner OLD-HOSTNAME NEW-HOSTNAME
```

## Storage Health Details

Runner emits `Storage_Health_JSON` when readable. Laravel parses it into `storage_health_observations`.

Supported probing:
- bundled `smartctl.exe` only for now
- tries multiple backends such as `auto`, `sat`, `scsi`, and `nvme`

Rules:
- TBW is best-effort
- unavailable SMART/NVMe/TBW values stay blank/NULL, never zero
- RAID/RST/USB bridges/controller layers may block telemetry
- blank TBW is expected on some machines
- partial telemetry is possible
- prefer `risk_level`, `risk_reasons`, and `recommended_action` over TBW alone
- NVMe Data Units Written conversion is `data_units_written * 512000`

Risk inputs include SMART health, wear/percentage used, spare, media/data errors, power-on hours, temperature, TBW where available, HDD sector risks, and fixed-disk disappearance.

## Validation and Parity

Before changing business logic, add targeted tests.

Preserve and test:
- raw archive behavior
- duplicate raw hash handling
- site-token boundary
- command dispatch/ack transitions
- runner heartbeat semantics
- identity weighting and invalid serial handling
- change severity assignment
- historical CSV import/parity behavior

Useful validation commands from `laravel/`:

```powershell
php artisan --version
php artisan migrate
php artisan inventory:doctor
php artisan inventory:doctor --production
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

## Operationally Sensitive Areas

Be explicit and conservative when touching:
- runner self-update or repair/update flow
- site-token handling
- production diagnostics/backups
- storage health scoring
- identity matching
- assignment override behavior
- command dispatch/ack lifecycle
- cutover or production deployment assumptions

Known open items:
- SQLite is acceptable for local development/pilot, but long-term production DB choice is not finalized
- Direct `repair_update` remains blocked for Direct HTTPS MVP
- Direct HTTPS package/site-kit rollout is still manual refresh/reinstall for now
- Phase 18D adds `runner/scripts/install_direct_https_runner.ps1`, a Direct HTTPS-only wrapper that validates generated config and HTTPS `/health`, requires admin PowerShell, preserves existing runner GUID, delegates to `install_runner.ps1`, verifies Scheduled Task/config, writes redacted logs, and does not change Direct HTTPS API contracts, command lifecycle, collector behavior, or Direct `repair_update`; status is implemented, live-validated with manual Scheduled Task trigger on `LAPTOP-I76TA97E`, and documented
- Phase 18E adds `collector/install_collector_site.ps1`, a collector-share wrapper that validates generated collector config, HTTPS `/health`, branch-share read/write with a temporary probe, collector Scheduled Task/config, and runner staging presence, delegates to `install_collector.ps1`, writes redacted logs, and does not call collector APIs, write operational share files, change Direct HTTPS behavior, or alter command lifecycle; status is implemented, package-included, wrapper preflight validated, and Python dependency handled cleanly
- Phase 18E.1 improves Python dependency handling in `collector/install_collector.ps1`: it safely resolves `pythonw`, `python`, or `py`, fails clearly when Python is absent without the old `Source` property error, documents that collector-share MVP requires Python 3.x on the collector host, does not auto-install/bundle Python, keeps Direct HTTPS as the small/no-IT site option, and was validated after Supermicro package rebuild with the expected clean missing-Python `FAIL` message
- Phase 18F Package UX Simplification and Mode-specific Installer Entry Points is implemented, Supermicro package-validated, and documented. It adds mode-specific package UX files only: Direct HTTPS packages include `INSTALL_THIS_PC_DIRECT_HTTPS_RUNNER.cmd` and `README_DIRECT_HTTPS_RUNNER.txt`; collector-share packages include `INSTALL_COLLECTOR_SITE.cmd` and `README_COLLECTOR_SITE.txt`; existing launchers remain for compatibility, wrong-mode top-level launchers are not exposed, and Direct HTTPS audit checks the new Direct HTTPS UX files. Supermicro validation built both package modes, confirmed expected files/README wording, got Direct HTTPS audit `WARN` only for the acceptable `collectorName` note, and found no obvious rendered secret.
- Phase 18A through Phase 18F are done; next work returns to MariaDB migration runbook / rehearsal planning
- token rotation UI is not done
- per-runner token enrollment is not done
- some SSD telemetry gaps are expected on RAID/RST-backed clients
- software license management, endpoint management, and remote support are out of scope unless explicitly added
