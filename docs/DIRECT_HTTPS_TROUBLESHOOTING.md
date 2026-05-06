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
```

`inventory:direct-pilot-status` summarizes the Direct HTTPS pilot fleet from Laravel database state.

`inventory:direct-site-kit-audit` was completed in Phase 16A. It is read-only and audits generated Direct HTTPS site-kit artifacts for Direct HTTPS transport, HTTPS endpoint, stale HTTP endpoint, placeholder endpoint, runner version `1.0.22`, config/README presence, collector-share isolation, and secret redaction. Supermicro validation passed with an acceptable `WARN` because `collectorName` is present but is not required for Direct HTTPS active transport.

`inventory:direct-runner-triage {runnerId}` was completed in Phase 16B. It is read-only and triages one Direct HTTPS runner using Laravel database state. It shows environment, runner identity, masked GUID, timestamps, command counts, latest command summary, likely status, and safe next checks. Collector-share runners are skipped safely.

Phase 16B.1 refined failed-command diagnosis. Historical failed commands remain visible, but old failed commands superseded by a later succeeded command no longer force `ATTENTION`. Active or recent unresolved failed commands still trigger `ATTENTION`.

Supermicro validation after Phase 16B.1:

- `IT-ADMIN`: runner version `1.0.22`, recent heartbeat, recent direct poll, latest command succeeded, historical failed commands superseded, likely status `OK`, `Result: PASS`.
- `LAPTOP-I76TA97E`: runner version `1.0.21`, stale heartbeat/poll/upload/ACK, latest command succeeded, likely status `stale/offline`, `Result: ATTENTION`.

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
