# Direct HTTPS Runner Checkpoint

## Purpose

Direct HTTPS runner mode is a second transport mode for small or no-IT sites.

It does not replace collector-share mode.

## Transport Modes

### Collector Share Mode

Used for HQ / multi-PC sites.

Flow:

Runner → local/SMB branch share → Collector → Laravel

### Direct HTTPS Mode

Used for single-PC or no-IT sites.

Flow:

Runner → Laravel HTTPS  
Runner → polls Laravel for commands  
Runner → sends ACK to Laravel

## Implemented

### Current Deployment State

- Active Laravel app runs on Supermicro at `D:\inventory\laravel`.
- IT-ADMIN development/Codex repo is `D:\xampp\htdocs\inventaris`.
- Git workflow is established: IT-ADMIN prepares source changes, Supermicro pulls reviewed changes.
- Official packages and site kits are generated only on Supermicro.
- Direct HTTPS endpoint is active at `https://inventory-pilot.internal.lan`.
- HPE StoreEasy terminates HTTPS and reverse-proxies to Laravel on Supermicro.
- Direct HTTPS second pilot passed.
- Current runner package version is `1.0.22`.

### Laravel

- `direct_runner` token type
- `DirectRunnerAuthService`
- `POST /api/direct-runner/heartbeat`
- `POST /api/direct-runner/scans`
- `POST /api/direct-runner/commands/poll`
- `POST /api/direct-runner/commands/ack`
- Direct command redelivery safety for stale dispatched/unacked commands
- Portal blocks `repair_update` for direct HTTPS runners
- Read-only pilot monitor command: `php artisan inventory:direct-pilot-status`
- Read-only site-kit audit command: `php artisan inventory:direct-site-kit-audit`
- Read-only runner triage command: `php artisan inventory:direct-runner-triage {runnerId}`
- Read-only production readiness checklist command: `php artisan inventory:production-readiness`
- Read-only installer/server preflight command: `php artisan inventory:install-preflight`
- Tests are self-contained with fixtures

### Runner

- package version `1.0.22`
- `transport_mode=direct_https`
- direct heartbeat
- direct scan upload
- local outbox retry
- command polling
- `scan_now` / `manual_scan` execution
- command ACK creation and retry
- phase-tagged logs for heartbeat, upload, poll, and ACK
- heartbeat failure no longer blocks pending upload/ACK recovery
- empty poll response `.Count` bug fixed
- direct mode no longer requires `sharedRoot` or `collectorName`
- Direct HTTPS local outbox cleanup:
  - cleanup only runs in `direct_https`
  - cleanup is constrained under the resolved Direct HTTPS outbox root
  - `outbox/pending` is never deleted
  - `outbox/sent` cleanup: older than 14 days and outside newest 100
  - `outbox/failed` cleanup: older than 30 days and outside newest 100
  - unknown files are skipped
  - cleanup logs summary counts
  - cleanup warnings do not fail the runner cycle

### Deployment / Site Kit

- `transport_mode=collector_share|direct_https`
- direct HTTPS site profile validation
- direct HTTPS site-kit generation
- runner-only direct site kit by default
- new direct HTTPS sample profile
- official site-kit/package generation happens only on Supermicro

## Safety Rules

- Laravel remains authoritative.
- Runner only scans, uploads, polls, ACKs, and retries.
- CSV remains archived evidence.
- Direct runner uses `direct_runner` site token, not collector token.
- Collector-share mode must remain unchanged.
- No DB credentials, Google credentials, master secrets, or global tokens in runner config.
- Direct command polling is at-least-once; commands must tolerate redelivery.
- Direct `repair_update` is blocked for MVP.
- Collector-share mode remains supported and unaffected.
- Do not print token secrets, bearer tokens, token hashes, DB credentials, Google credentials, or raw CSV contents.
- Do not print `.env` values, `APP_KEY` values, command payload JSON, full configs, or full runner GUIDs.

## Validated Pilot Result

Manual test on runner `IT-ADMIN` passed end-to-end:

- Direct heartbeat succeeded
- Direct scheduled scan upload succeeded
- Direct `scan_now` command was delivered
- Runner executed scan command
- Scan upload created `result_upload_id`
- Direct ACK succeeded
- Laravel command reached `status=succeeded`

Second Direct HTTPS pilot on runner `LAPTOP-I76TA97E` also passed end-to-end:

- HTTPS endpoint through HPE StoreEasy reverse proxy returned 200
- Direct runner installed successfully
- Runner appeared in portal
- Heartbeat populated
- Direct command poll populated
- Inventory upload/ingest succeeded
- Devices list showed laptop inventory
- Manual scan command delivered, ACKed, and succeeded
- Portal showed Direct HTTPS Last Inventory and repair/update guardrail correctly after cleanup

Runner package `1.0.22` was validated on IT-ADMIN:

- scheduled task `LastTaskResult=0`
- HTTPS health OK
- heartbeat success observed
- direct poll success observed
- upload status `direct_upload_uploaded` observed
- cleanup summary logs observed
- pending folder had 0 files and was not deleted/touched
- no cleanup warnings/errors observed
- collector stayed disabled/not involved

## Phase 16 Operational Commands

### Phase 16A - Direct HTTPS Site-kit Audit

Command:

```powershell
php artisan inventory:direct-site-kit-audit
```

Status:

- Completed and validated on Supermicro.
- Read-only Laravel Artisan command.
- Audits generated Direct HTTPS site-kit artifacts before installing more Direct HTTPS runners.
- Checks Direct HTTPS transport, HTTPS endpoint, stale HTTP endpoint, placeholder endpoint, runner version `1.0.22`, config/README presence, collector-share isolation, and secret redaction.
- Passed with an acceptable `WARN` because `collectorName` is present in the generated artifact but is not required for Direct HTTPS active transport.
- Does not generate site kits, rotate tokens, update timestamps, or mutate database records.

### Phase 16B - Direct HTTPS Runner Triage

Command:

```powershell
php artisan inventory:direct-runner-triage {runnerId}
```

Status:

- Completed and validated on Supermicro.
- Read-only Laravel Artisan command.
- Triage one Direct HTTPS runner from existing Laravel database state.
- Shows environment, runner identity, masked GUID, Direct HTTPS timestamps, command counts, latest command summary, likely status, and safe next checks.
- Skips collector-share runners safely and does not warn about missing Direct HTTPS fields for collector-share mode.
- Does not print payloads, tokens, bearer values, token hashes, full configs, raw CSV, or full runner GUIDs.

### Phase 16B.1 - Failed-History Refinement

Status:

- Completed and validated on Supermicro.
- Historical failed commands remain visible as informational context.
- Old failed commands superseded by a later succeeded command no longer force `ATTENTION`.
- Active or recent unresolved failed commands still trigger `ATTENTION`.
- Targeted test validation passed: 17 tests, 91 assertions.
- Supermicro validation:
  - `IT-ADMIN`: runner version `1.0.22`, recent heartbeat, recent direct poll, latest command succeeded, historical failed commands superseded, likely status `OK`, `Result: PASS`.
  - `LAPTOP-I76TA97E`: runner version `1.0.21`, stale heartbeat/poll/upload/ACK, latest command succeeded, likely status `stale/offline`, `Result: ATTENTION`.

Confirmed command fields:

- `acknowledged_at` filled
- `completed_at` filled
- `completion_status=succeeded`
- `result_upload_id` filled

### Phase 17A - Production Readiness Checklist Command

Command:

```powershell
php artisan inventory:production-readiness
```

Status:

- Implemented, validated on Supermicro, documented.
- Laravel-only read-only Artisan command.
- Summarizes production/cutover readiness risks before moving beyond pilot mode.
- Distinguishes pilot readiness, Direct HTTPS small-site readiness, and production/cutover readiness.
- Explicitly states that passing checks is not production approval or cutover approval.
- Uses plain text output with `[OK]`, `[WARN]`, `[FAIL]`, and `[INFO]`.
- Ends with exactly one of `Result: PASS`, `Result: WARN`, or `Result: FAIL`.
- Does not run nested Artisan commands, mutate DB records, write files, clear cache/config, run migrations, touch runner/collector files, generate backups, generate site kits, trigger commands, change Direct HTTPS API contracts, change command polling/ACK semantics, enable Direct `repair_update`, modify collector-share behavior, or print secrets.

Sections:

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

Supermicro validation at `D:\inventory\laravel`:

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

Expected current warnings:

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

Validation:

- `Tests\Feature\ProductionReadinessCommandTest` passed: 16 tests, 61 assertions.
- Full Laravel test suite previously passed after implementation: 224 tests, 1005 assertions.

### Phase 18B - Installer/server Preflight Command

Command:

```powershell
php artisan inventory:install-preflight
```

Status:

- Implemented, validated on Supermicro, documented.
- Laravel-only read-only Artisan command for future productized installation, setup wizard, and package/site-kit generation flows.
- Checks Environment, Laravel Host, Application URL / HTTPS, Storage and Package Paths, Operational Commands, Package / Site-kit Generation Safety, Direct HTTPS Productization Notes, Collector-share Productization Notes, Setup Wizard Readiness, MVP Manual Boundaries, Security / Secret Redaction, Recommended Next Checks, and Result.
- Not an installer and not the portal setup wizard.
- Does not generate packages, site kits, backups, or tokens.
- Does not mutate database records, write files, run migrations, run nested Artisan commands, change `.env`, or expose secrets.

Supermicro validation at `D:\inventory\laravel`:

- Host/path appeared as the approved Supermicro active Laravel host.
- Official package/site-kit generation was allowed on the approved Supermicro host.
- `APP_URL` used HTTPS and was not placeholder/example or localhost/loopback.
- Laravel storage, inventory storage root, downloads/site-kit path, raw archive path, and backup path were readable and writable.
- Required operational commands were registered: `inventory:doctor`, `inventory:direct-pilot-status`, `inventory:direct-site-kit-audit`, `inventory:direct-runner-triage`, and `inventory:production-readiness`.
- Optional commands were detected: `inventory:build-site-kit`, `inventory:prepare-site-profile`, `inventory:validate-site-profile`, and `inventory:backup`.
- Nested Artisan commands were not run, no packages/site kits/backups/tokens were generated, and secrets were not printed.
- Direct HTTPS was described as small/no-IT transport; collector-share was described as the main HQ/multi-PC mode.
- Portal Setup Wizard MVP was reported as not implemented yet.
- Final result: `WARN`.

Expected current warnings:

- `APP_ENV=local`.
- `APP_DEBUG=true`.
- `APP_URL` uses pilot/internal hostname.
- Trusted proxy / forwarded HTTPS headers cannot be fully proven from CLI.
- Backup policy and restore rehearsal are not verified by this command.
- Portal Setup Wizard MVP is not implemented yet.

Validation:

- `Tests\Feature\InstallPreflightCommandTest` passed: 21 tests, 56 assertions.
- Full Laravel test suite passed after implementation: 245 tests, 1061 assertions.

### Phase 18C - Portal Setup Wizard MVP

Route:

```text
GET /setup-wizard
```

Status:

- Implemented, validated on Supermicro, documented.
- Authenticated admin portal page.
- Read-only guided setup MVP, not production/cutover approval.
- Shows seven-step setup flow, safe `APP_URL` / HTTPS labels, existing site/runner/collector counts only, deployment-mode guidance, verification checklists, and secret-redaction footer.
- Explains Direct HTTPS, collector-share, and hybrid modes.
- Links to existing runners, collectors, command queue, and downloads pages.
- References `inventory:install-preflight` and `inventory:direct-site-kit-audit` without running nested Artisan commands.
- Adds an Operations navigation link.

Safety:

- Does not create users, sites, tokens, token rotation, per-runner enrollment, packages, site kits, migrations, `.env` changes, production approval, or production data mutations.
- Does not touch runner files or collector files.
- Does not change Direct HTTPS API contracts or command lifecycle semantics.
- Direct `repair_update` remains blocked.
- Collector-share remains supported and unaffected.
- No secrets are printed or exposed.

Validation:

- `Tests\Feature\SetupWizardMvpTest` passed: 8 tests, 52 assertions.
- Full Laravel suite passed after implementation: 253 tests, 1113 assertions.
- Manual portal validation after merge/pull confirmed `/setup-wizard` loads for authenticated admin, read-only and non-cutover wording are clear, mode cards are understandable, verification links are present, and no secrets are displayed.

### Phase 18D - Direct HTTPS Runner Installer MVP

Script:

```text
runner/scripts/install_direct_https_runner.ps1
```

Status:

- Implemented, live-validated with manual Scheduled Task trigger, documented.
- PowerShell wrapper for already generated Direct HTTPS runner packages/configs.
- Requires elevated PowerShell for MVP.
- Locates or accepts `runner-config.template.json` / `runner-config.json`.
- Validates `transport_mode=direct_https` before install.
- Refuses collector-share, missing, blank, or unknown transport modes.
- Validates `serverBaseUrl`, requires `https://`, and refuses HTTP, localhost, loopback, example, placeholder, and `inventory.example.local` URLs.
- Checks `serverBaseUrl + /health` with normal TLS validation and does not use `-SkipCertificateCheck`.
- Detects and backs up an existing installed config before overwrite.
- Preserves an existing installed `runnerGuid` and prints only a redacted suffix.
- Delegates actual file/task installation to `install_runner.ps1`.
- Verifies the Scheduled Task and installed Direct HTTPS config after install.
- Writes a local installer log and prints a redacted support summary plus portal verification instructions.
- Generated Direct HTTPS `INSTALL_THIS_PC_RUNNER_ONLY.cmd` stages locally, elevates with UAC, and invokes the wrapper with `runner-config.template.json`.

Safety:

- Does not change `runner_main.ps1`, scanner behavior, collector behavior, Direct HTTPS API contracts, command lifecycle semantics, or Direct `repair_update`.
- Does not call Laravel Direct HTTPS heartbeat/upload/poll/ACK/command APIs.
- Does not trigger `scan_now` or `manual_scan`.
- Does not print tokens, raw configs, command payloads, `.env` values, `APP_KEY`, or full runner GUIDs.
- Generated Direct HTTPS site kits include and launch the wrapper; generated collector-share site kits remove the Direct HTTPS-only wrapper.
- Package inclusion is additive; config schema and token handling are unchanged.

Validation:

- PowerShell parser check passed for `runner/scripts/install_direct_https_runner.ps1`.
- `tests/powershell/Test-DirectHttpsInstaller.ps1` passed.
- `Tests\Feature\SiteProfileValidationCommandTest` passed.
- `Tests\Feature\DirectSiteKitAuditCommandTest` passed: 8 tests, 46 assertions.
- Full Laravel test suite passed after implementation: 253 tests, 1125 assertions.
- Supermicro Direct HTTPS site-kit audit passed with acceptable `WARN`: `collectorName` is present but not required for Direct HTTPS active transport.
- Direct HTTPS profile validation passed for `..\deployment\profiles\site_hq_direct_https.pilot.json`.

Live runner validation:

- Runner: `LAPTOP-I76TA97E`.
- Result: passed with manual Scheduled Task trigger.
- Method: ran the Direct HTTPS installer wrapper from PowerShell, manually triggered the existing Windows Scheduled Task after installation, then verified normal Direct HTTPS heartbeat, poll, upload, portal state, and Supermicro triage.
- Observed: runner resolved as Direct HTTPS, version `1.0.22`, masked runner GUID only, recent heartbeat, recent direct command poll, populated upload/inventory, latest command succeeded, pending queued commands `0`, delivered awaiting ACK `0`, stale dispatched/no ACK `0`, historical failed command count `0`, likely status `OK`, triage result `PASS`.
- Direct pilot status after validation: `IT-ADMIN` health `OK` version `1.0.22`; `LAPTOP-I76TA97E` health `OK` version `1.0.22`; Direct `repair_update` remains blocked; collector-share remains unaffected; collector-share runner count `36`.
- Nuance: the first validation cycle after wrapper install was manually triggered through Task Scheduler. Automatic scheduled interval observation can continue during monitoring; this is not a blocker for Phase 18D MVP acceptance.

## Known Limitations

- Direct `repair_update` is not supported yet.
- Token rotation UI is not done.
- Advanced rate limiting is not done.
- Per-runner token enrollment is not done.
- Direct HTTPS runner rollout is manual package refresh/reinstall for now.
- Direct mode is intended first for small/no-IT sites, not large branches.

## Next Recommended Steps

1. Monitor Direct HTTPS pilots with `php artisan inventory:direct-pilot-status`.
2. Audit generated Direct HTTPS artifacts with `php artisan inventory:direct-site-kit-audit` before installing more pilot runners.
3. Triage individual Direct HTTPS runners with `php artisan inventory:direct-runner-triage {runnerId}`.
4. Run `php artisan inventory:production-readiness` before moving beyond pilot mode.
5. Run `php artisan inventory:install-preflight` before productized setup/package generation review.
6. Keep Direct HTTPS rollout focused on small/no-IT sites first.
7. Phase 18C Portal Setup Wizard MVP is done.
8. Phase 18D Direct HTTPS Runner Installer MVP is done; continue normal scheduled-interval monitoring for Direct HTTPS runners.
9. Phase 18E Collector-site Installer MVP is implemented in source and keeps Direct HTTPS behavior unaffected; Phase 18E.1 clarifies that collector-share MVP requires Python on the collector host and small/no-IT sites should use Direct HTTPS mode.
10. Later return to MariaDB migration runbook and rehearsal.
