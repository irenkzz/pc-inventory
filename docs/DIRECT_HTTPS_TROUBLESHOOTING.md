# Direct HTTPS Troubleshooting

## Purpose

This guide covers practical operations and troubleshooting for Direct HTTPS runner mode.

Direct HTTPS is the second runner transport for small or no-IT sites. It does not replace collector-share mode.

## Quick Safety Rules

- Do not print or paste `siteToken`, collector tokens, direct runner tokens, or other secrets.
- Do not print bearer tokens, token hashes, DB credentials, Google credentials, raw CSV contents, command payload JSON, full configs, or full runner GUIDs.
- Do not use a collector token for Direct HTTPS.
- Do not use plain HTTP for the second-network pilot.
- Do not disable TLS validation.
- Do not use `-SkipCertificateCheck`.
- Do not test or enable direct `repair_update`; it remains blocked for Direct HTTPS MVP.
- Keep collector-share mode separate and unaffected.
- Database state is authoritative. CSV files are archived evidence only. Google Drive is backup/sync only.

## Operational Artisan Commands

Run these from the active Laravel host. Official site kits and packages are generated only on Supermicro.

```powershell
php artisan inventory:direct-pilot-status
php artisan inventory:direct-site-kit-audit
php artisan inventory:direct-runner-triage IT-ADMIN
php artisan inventory:direct-runner-triage LAPTOP-I76TA97E
php artisan inventory:production-readiness
php artisan inventory:install-preflight
```

`inventory:direct-pilot-status` summarizes the Direct HTTPS pilot fleet from Laravel database state.

`inventory:direct-site-kit-audit` was completed in Phase 16A. It is read-only and audits generated Direct HTTPS site-kit artifacts for Direct HTTPS transport, HTTPS endpoint, stale HTTP endpoint, placeholder endpoint, runner version `1.0.22`, config/README presence, collector-share isolation, and secret redaction. Supermicro validation passed with an acceptable `WARN` because `collectorName` is present but is not required for Direct HTTPS active transport.

`inventory:direct-runner-triage {runnerId}` was completed in Phase 16B. It is read-only and triages one Direct HTTPS runner using Laravel database state. It shows environment, runner identity, masked GUID, timestamps, command counts, latest command summary, likely status, and safe next checks. Collector-share runners are skipped safely.

Phase 16B.1 refined failed-command diagnosis. Historical failed commands remain visible, but old failed commands superseded by a later succeeded command no longer force `ATTENTION`. Active or recent unresolved failed commands still trigger `ATTENTION`.

Supermicro validation after Phase 16B.1:

- `IT-ADMIN`: runner version `1.0.22`, recent heartbeat, recent direct poll, latest command succeeded, historical failed commands superseded, likely status `OK`, `Result: PASS`.
- `LAPTOP-I76TA97E`: runner version `1.0.21`, stale heartbeat/poll/upload/ACK, latest command succeeded, likely status `stale/offline`, `Result: ATTENTION`.

`inventory:production-readiness` was completed in Phase 17A. It is a Laravel-only read-only checklist for production/cutover readiness risks before moving beyond pilot mode. It distinguishes pilot readiness, Direct HTTPS small-site readiness, and production/cutover readiness, and explicitly states that passing checks is not production approval or cutover approval. It uses plain text `[OK]`, `[WARN]`, `[FAIL]`, and `[INFO]` output and ends with exactly one result line: `Result: PASS`, `Result: WARN`, or `Result: FAIL`.

The command documents these sections:

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

Phase 17A Supermicro validation at `D:\inventory\laravel` produced `Result: WARN` with expected current warnings. Validation state included HTTPS `APP_URL` at `https://inventory-pilot.internal.lan`, `APP_ENV=local`, expected `APP_DEBUG=true` warning, SQLite reachable, migrations table reachable, no pending migrations, writable storage/downloads/raw archive/backup paths, recent backup detected, required operational commands registered, Direct HTTPS runner count 2, stale Direct HTTPS runner count 0, Direct HTTPS runners on expected version `1.0.22` count 2, collector-share runner count 36, collector count 3, collector-share supported as the main HQ/multi-PC mode, and Direct `repair_update` still blocked for Direct HTTPS MVP.

Expected current Phase 17A warnings:

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

Phase 17A validation tests: `Tests\Feature\ProductionReadinessCommandTest` passed with 16 tests and 61 assertions. The full Laravel test suite previously passed after implementation with 224 tests and 1005 assertions.

Safety: the command does not run nested Artisan commands, mutate DB records, write files, clear cache/config, run migrations, touch runner/collector files, generate backups, generate site kits, trigger commands, change Direct HTTPS API contracts, change command polling/ACK semantics, enable Direct `repair_update`, modify collector-share behavior, or print secrets.

Phase 17B production deployment decision is documented in `docs/PRODUCTION_DEPLOYMENT_DECISION.md`. It keeps Direct HTTPS as the small/no-IT transport, keeps collector-share as the main HQ/multi-PC mode, keeps HPE StoreEasy as TLS termination reverse proxy for now, and documents the recommended production DB/web/TLS/backup model without changing runner, collector, API, command lifecycle, Direct `repair_update`, production data, `.env`, generated artifacts, or secrets.

`inventory:install-preflight` was completed in Phase 18B. It is a Laravel-only read-only installer/server preflight for future productized installation, setup wizard, and package/site-kit generation flows. It is not an installer and not the portal setup wizard. It does not generate packages, site kits, backups, or tokens; does not mutate database records; does not write files; does not run migrations or nested Artisan commands; does not change `.env`; and does not expose secrets.

The command checks Environment, Laravel Host, Application URL / HTTPS, Storage and Package Paths, Operational Commands, Package / Site-kit Generation Safety, Direct HTTPS Productization Notes, Collector-share Productization Notes, Setup Wizard Readiness, MVP Manual Boundaries, Security / Secret Redaction, Recommended Next Checks, and Result.

Phase 18B Supermicro validation at `D:\inventory\laravel` produced `Result: WARN`. The approved Supermicro host/path was detected, official package/site-kit generation was allowed only there, HTTPS `APP_URL` was accepted, storage/downloads/raw archive/backup paths were readable and writable, required and optional operational commands were detected, nested Artisan commands were not run, no packages/site kits/backups/tokens were generated, Direct HTTPS remained the small/no-IT transport, collector-share remained the main HQ/multi-PC mode, Setup Wizard MVP was reported as not implemented yet, and secrets were not printed. Expected warnings were `APP_ENV=local`, `APP_DEBUG=true`, pilot/internal hostname, trusted proxy headers not fully provable from CLI, backup/restore rehearsal policy not verified, and Setup Wizard MVP not implemented.

Phase 18B validation tests: `Tests\Feature\InstallPreflightCommandTest` passed with 21 tests and 56 assertions. The full Laravel suite passed after implementation with 245 tests and 1061 assertions.

`/setup-wizard` was completed in Phase 18C. It is an authenticated admin portal page and read-only guided setup MVP for productized setup flow review. It shows the seven-step setup flow, safe `APP_URL` / HTTPS labels, existing site/runner/collector counts only, Direct HTTPS / collector-share / hybrid deployment guidance, links to runners, collectors, command queue, and downloads, verification checklists, and a secret-redaction footer. It references `inventory:install-preflight` and `inventory:direct-site-kit-audit` without running them.

Phase 18C safety: the page does not create users, sites, tokens, token rotation, per-runner enrollment, packages, site kits, migrations, `.env` changes, production approval, or production data mutations. It does not touch runner or collector files, change Direct HTTPS API contracts, change command lifecycle semantics, enable Direct `repair_update`, or expose secrets. Collector-share remains supported and unaffected.

Phase 18C validation: `Tests\Feature\SetupWizardMvpTest` passed with 8 tests and 52 assertions; the full Laravel suite passed after implementation with 253 tests and 1113 assertions. Manual portal validation confirmed `/setup-wizard` loads for authenticated admin, is clearly read-only and not production/cutover approval, has understandable Direct HTTPS / collector-share / hybrid guidance, includes verification links, and displays no secrets.

## Endpoint And DNS Failures

Use these checks from the runner machine.

```powershell
Resolve-DnsName inventory-pilot.internal.lan
Test-NetConnection inventory-pilot.internal.lan -Port 443
Invoke-WebRequest https://inventory-pilot.internal.lan/health -UseBasicParsing
```

Expected:

- DNS resolves to the HPE StoreEasy reverse proxy address.
- TCP 443 succeeds.
- `/health` returns HTTP 200.

Common failures:

- `Resolve-DnsName` fails: fix DNS registration, client DNS server, or local network routing.
- `Test-NetConnection` port 443 fails: check firewall, proxy listener, routing, or reverse proxy service.
- `/health` times out: check reverse proxy upstream to Supermicro and Laravel app availability.
- Browser cannot reach portal: confirm DNS, certificate trust, proxy listener, and endpoint URL.

Do not regenerate a site kit until the runner machine can reach the real HTTPS endpoint.

## TLS And Reverse Proxy Issues

Symptoms:

- Browser certificate warning.
- Browser shows an HTTP form submission warning.
- Login page loads over HTTPS but submits to `http://...`.
- Laravel generates `http://` form actions behind the HTTPS reverse proxy.

Required reverse proxy headers:

```text
Host
X-Forwarded-Host
X-Forwarded-Proto=https
X-Forwarded-Port=443
```

Laravel checks:

- `APP_URL` should match the HTTPS portal URL, for example `https://inventory-pilot.internal.lan`.
- `SESSION_SECURE_COOKIE` should be enabled for HTTPS deployment.
- Trusted proxy handling must honor forwarded HTTPS headers.

After proxy or Laravel environment changes, clear cached Laravel state on the active Laravel host:

```powershell
php artisan config:clear
php artisan cache:clear
php artisan view:clear
```

If the browser still shows stale behavior, close and reopen the browser before retesting login.

## Caddy And Browser Behavior

During pilot work, the browser may keep loading or behave oddly after proxy fixes.

Recommended actions:

- Close and reopen the browser.
- Retry `/health` first, then the login page.
- If HTTP/3 behavior is suspected during the pilot, temporarily disable HTTP/3 at the proxy and retest.

Do not work around TLS problems by using HTTP or skipping certificate validation.

## Wrong Site Kit Or Profile

Symptoms:

- Runner installs but still behaves like collector-share.
- Runner points to a stale generated `SITE-HQ` collector-share profile.
- Direct HTTPS runner never appears in Direct HTTPS portal fields.

Direct HTTPS kit/profile must use:

```text
transport_mode=direct_https
serverBaseUrl=https://inventory-pilot.internal.lan
runnerVersion=1.0.22
```

Generation rules:

- Official generation must happen on Supermicro, the active Laravel host.
- IT-ADMIN is the source repo workflow location.
- Do not edit generated site-kit artifacts by hand.
- Do not use stale generated collector-share profiles for Direct HTTPS deployment.

## Runner Installed But Not Visible

First read the runner config safely. Do not print token fields.

```powershell
$config = Get-Content 'C:\ProgramData\InternalInventoryRunner\config\runner-config.json' -Raw | ConvertFrom-Json
$guid = [string]$config.runnerGuid
[pscustomobject]@{
    runnerId = $config.runnerId
    runnerGuid = if ($guid) { 'Present ending ' + $guid.Substring([Math]::Max(0, $guid.Length - 4)) } else { 'Not present' }
    runnerVersion = $config.runnerVersion
    transport_mode = $config.transport_mode
    serverBaseUrl = $config.serverBaseUrl
    siteId = $config.siteId
    sharedRoot = $config.sharedRoot
    collectorName = $config.collectorName
}
```

Check scheduled tasks:

```powershell
Get-ScheduledTaskInfo -TaskName "InternalInventoryRunner"
Get-ScheduledTaskInfo -TaskName "InternalInventoryRunner-Startup"
```

If the task has not run recently, a manual trigger may be appropriate, but do not trigger it during read-only validation unless explicitly approved.

Check recent files and logs efficiently:

```powershell
Get-ChildItem "C:\ProgramData\InternalInventoryRunner" -Recurse -File |
  Sort-Object LastWriteTime -Descending |
  Select-Object -First 15 FullName, LastWriteTime
```

Inspect only recent relevant logs, especially:

- `C:\ProgramData\InternalInventoryRunner\logs\runner-main.log`
- recent files under `C:\ProgramData\InternalInventoryRunner\data\logs`
- `C:\ProgramData\InternalInventoryRunner\state\runner-state.json`

Check the Runners page directly, not only the dashboard:

```text
https://inventory-pilot.internal.lan/runners
```

## Command State Issues

Direct command polling means delivery. ACK is the execution result.

Portal states:

- `queued`: command is waiting for a runner poll.
- `delivered`: runner received the command.
- `awaiting ACK`: runner has not sent execution result yet.
- `stale/no ACK`: delivered command did not ACK within the expected window.
- `succeeded`: runner ACK reported success.
- `failed`: runner ACK reported failure.

Troubleshooting flow:

- If command remains queued, check direct poll timestamp and runner logs for poll failures.
- If command is delivered but awaiting ACK, check runner execution logs and `state\direct-acks`.
- If command is stale/no ACK, remember direct polling is at-least-once; stale dispatched/unacked commands may be redelivered.
- Do not use direct `repair_update`; it is blocked for MVP.

## Upload And Last Inventory Issues

Direct HTTPS upload signal:

- `last_direct_upload_at` is the Direct HTTPS upload timestamp.
- Runner list and runner detail should show uploaded state after the latest portal fixes.

If Last Inventory is missing:

- Check whether `last_direct_upload_at` is populated in runner state or portal detail.
- Check runner logs for upload attempts and upload success/failure.
- Check `outbox/pending` for unsent upload files.
- Check `outbox/failed` for unrecoverable upload files.
- Check the Devices list; device inventory may prove ingest succeeded even if a UI field needs review.

Expected successful signals:

- Upload status such as `direct_upload_uploaded`.
- `last_direct_upload_at` populated.
- Device appears or updates in Devices list.
- Runner list/detail shows uploaded or a populated Last Inventory value.

## Outbox Cleanup In 1.0.22

Runner package `1.0.22` adds conservative Direct HTTPS outbox cleanup.

Policy:

- Cleanup only runs when `transport_mode=direct_https`.
- Cleanup root is constrained under the resolved Direct HTTPS outbox root.
- `outbox/pending` is never deleted.
- `outbox/sent` cleanup keeps files newer than 14 days and always retains the newest 100 files.
- `outbox/failed` cleanup keeps files newer than 30 days and always retains the newest 100 files.
- Unknown files are skipped.
- Cleanup runs near the end of the Direct HTTPS cycle after upload and ACK retry recovery.
- Cleanup warnings do not fail the runner cycle.

Check outbox state:

```powershell
$root = 'C:\ProgramData\InternalInventoryRunner\outbox'
foreach ($folder in @('pending', 'sent', 'failed')) {
    $path = Join-Path $root $folder
    $files = if (Test-Path $path) { @(Get-ChildItem $path -File) } else { @() }
    $newest = $files | Sort-Object LastWriteTime -Descending | Select-Object -First 1
    [pscustomobject]@{
        Folder = $folder
        Exists = Test-Path $path
        FileCount = $files.Count
        NewestFileTimestamp = if ($newest) { $newest.LastWriteTime } else { $null }
    }
}
```

Check cleanup log evidence:

```powershell
Select-String 'C:\ProgramData\InternalInventoryRunner\logs\runner-main.log' -Pattern 'outbox cleanup summary|outbox cleanup warning' |
  Select-Object -Last 20
```

Expected cleanup summary includes counts similar to:

```text
sent_deleted=0 sent_retained=64 sent_skipped=0 failed_deleted=0 failed_retained=0 failed_skipped=0 pending_deleted=0
```

Any cleanup warning should be investigated, but it should not fail the runner cycle by itself.

## Collector-Disabled Sanity Check

For a Direct HTTPS runner:

- Collector may be disabled intentionally.
- `collectorName` can still exist in config as legacy/site-kit context.
- `sharedRoot` may be empty.
- `transport_mode=direct_https` means collector-share paths should not drive active runner transport.

Quick checks:

```powershell
Get-Process | Where-Object { $_.ProcessName -match 'collector|python' }
Get-ScheduledTask | Where-Object { $_.TaskName -match 'Collector|InventoryCollector' }
```

Do not re-enable collector while validating Direct HTTPS.

## Direct HTTPS Installer MVP

Phase 18D adds `runner/scripts/install_direct_https_runner.ps1` for Direct HTTPS runner packages. Use it only with already generated Direct HTTPS runner configs.

Expected behavior:

- Requires elevated PowerShell.
- Refuses collector-share or unknown transport configs.
- Requires `transport_mode=direct_https`.
- Requires an HTTPS `serverBaseUrl`.
- Refuses HTTP, localhost, loopback, example, placeholder, and `inventory.example.local` endpoints.
- Checks `serverBaseUrl + /health` using normal TLS validation.
- Does not use `-SkipCertificateCheck`.
- Backs up an existing installed config before overwrite.
- Preserves existing `runnerGuid` and prints only a redacted suffix.
- Delegates installation to `install_runner.ps1`.
- Verifies the Scheduled Task and installed Direct HTTPS config.
- Writes an installer log under `C:\ProgramData\InternalInventoryRunner\logs` when available, otherwise `%TEMP%`.

Normal install from an extracted Direct HTTPS package:

```text
INSTALL_THIS_PC_RUNNER_ONLY.cmd
```

That launcher stages the package locally, asks for administrator approval, and invokes the wrapper with `runner-config.template.json`.

Manual wrapper run from an extracted Direct HTTPS package:

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File .\runner\scripts\install_direct_https_runner.ps1 -ConfigPath .\runner\config\runner-config.template.json -UseComputerNameAsRunnerId
```

If installation fails after a backup was created, restore by copying the `.bak-YYYYMMDD-HHMMSS` file back to:

```text
C:\ProgramData\InternalInventoryRunner\config\runner-config.json
```

Do not paste installer logs that contain raw configs. Support summaries should include only runner ID, transport mode, version, server URL, masked runner GUID suffix, Scheduled Task status, HTTPS health result, and log path.

## Portal Checklist

Open:

```text
https://inventory-pilot.internal.lan/runners
```

Expected:

- Runner appears by configured `runnerId`.
- Transport shows Direct HTTPS.
- Version shows `1.0.22`.
- Last heartbeat is recent.
- Direct command poll is recent.
- Last Inventory is populated after scan upload.
- Inventory status shows uploaded.
- Repair/update is blocked for Direct HTTPS MVP.
- Health is healthy or has a clear nonfatal explanation.

## Escalation Notes

Escalate with a concise report containing:

- Runner ID and hostname.
- Masked runner GUID only.
- Runner version.
- Transport mode.
- HTTPS health result.
- Last heartbeat, poll, upload timestamps.
- Outbox folder counts.
- Relevant log lines without payloads or secrets.
- Portal command state.

Do not include tokens, raw payloads, full config, or screenshots that reveal secrets.
