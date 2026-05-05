# Direct HTTPS Second Pilot Runbook

## Purpose

Validate Direct HTTPS on one non-critical second machine or network. Confirm the portal visibility MVP is operationally useful, and confirm collector-share mode remains unaffected.

## Preconditions

- Laravel tests are green.
- `php artisan inventory:doctor` passes, except the known local `APP_DEBUG=true` warning.
- A Direct HTTPS site kit/profile exists.
- A `direct_runner` token exists and is handled securely.
- The Direct HTTPS source profile uses a real `https://` `server_base_url`.
- The pilot Windows runner trusts the TLS certificate used by the portal endpoint.
- Runner package remains version `1.0.21`.
- Direct `repair_update` remains blocked.
- No runner, collector, API, or command semantics changes are part of this pilot.

## Environment Selection

Use one target with:

- Non-critical Windows PC or laptop.
- Different network from the IT-ADMIN pilot.
- Outbound HTTPS available to the Laravel portal.
- No SMB/share/collector dependency.
- Stable enough for multiple runner cycles.

Avoid:

- HQ or multi-PC branch deployment.
- Production-critical PC.
- Collector-share machine.
- Proxy/VPN-heavy environment unless already supported.

## HTTPS Endpoint Preparation

Use a real HTTPS Laravel endpoint before generating the second-pilot site kit. Prefer a VPN/private tunnel plus an internal HTTPS reverse proxy in front of Laravel.

Current second-pilot endpoint:

- `https://inventory-pilot.internal.lan`

Preflight result: `inventory-pilot.internal.lan` resolves to `192.168.100.10`, TCP 443 succeeds, and `/health` returns 200 through the HPE StoreEasy HTTPS reverse proxy.

Important source-of-truth note:

- The active Laravel app is running on Supermicro.
- IT-ADMIN/local repo is only a staging source for profile/docs changes.
- Official Direct HTTPS site-kit generation must be run on Supermicro after this profile is copied/synced there.
- Do not regenerate Direct HTTPS site-kit artifacts on IT-ADMIN.

Do:

- Use `deployment/profiles/site_hq_direct_https.pilot.json` as the source profile after it is copied/synced to Supermicro.
- Use the registered `direct_runner` token on Supermicro; do not store or print token secrets in the source profile.
- Confirm the pilot Windows machine can open `/health` on the HTTPS endpoint before installing the runner.
- Confirm Windows trusts the TLS certificate.
- Regenerate the site kit only from the valid source profile on Supermicro.
- Keep token secrets out of screenshots, logs, command output, and chat.

Do not:

- Use `http://192.168.100.110:8000` for the second-network pilot.
- Use `https://inventory.example.local` as if it were a real configured endpoint.
- Use plain HTTP for the second-network pilot.
- Disable TLS validation.
- Use `-SkipCertificateCheck`.
- Manually edit generated site-kit artifacts.
- Regenerate official site-kit artifacts on IT-ADMIN.

## Pilot Checklist

- Install/configure the Direct HTTPS runner from the prepared site kit.
- Confirm initial heartbeat appears in the portal.
- Confirm first inventory upload succeeds.
- Confirm an empty command poll updates `last_direct_poll_at`.
- Queue `scan_now` / manual scan and observe: queued -> delivered -> awaiting ACK -> succeeded.
- Confirm `result_upload_id` is present when scan upload succeeds.
- Confirm `repair_update` is blocked/unsupported for Direct HTTPS.
- Confirm token secret is not rendered in portal pages.
- Confirm an existing collector-share runner still looks normal.

## Expected Portal Observations

Runners list:

- Runner shows `Direct HTTPS` transport badge.
- Last heartbeat is populated after the runner cycle.
- Direct command poll shows a timestamp after polling, or `No command poll recorded yet` before first poll.
- Version shows `1.0.21`, or `Version not reported` if missing.
- Collector-share runners show `Collector Share` and do not treat missing direct poll as a warning.

Runner detail:

- Direct runner shows the `Direct HTTPS Status` panel.
- Runner GUID is masked, or shows `Runner GUID not reported`.
- Last heartbeat, direct command poll, pending command count, latest command state, and last ACK are readable.
- Direct command support says `scan_now/manual_scan supported`.
- Direct `repair_update` says blocked for Direct HTTPS MVP.

Command queue:

- Direct queued command displays `Queued`.
- Delivered direct command displays `Delivered to runner` and `Awaiting ACK` until ACK arrives.
- Successful ACK displays `Succeeded`.
- Failed ACK displays `Failed`.
- Stale delivered/unacked command displays `Stale - delivered but no ACK received`.
- Backend stored status values remain unchanged.

## Failure Signals

- No heartbeat after expected runner cycle.
- `No command poll recorded yet` remains after multiple cycles.
- Upload is stale or missing.
- Command is delivered but no ACK arrives.
- Command becomes stale with no ACK.
- Token, auth, or guardrail failure appears in Laravel logs.

## Stop Conditions

Stop the pilot if:

- A token secret appears in the portal.
- Collector-share mode is affected.
- Direct `repair_update` becomes available.
- Raw evidence is overwritten or missing.
- CSV assignment fields overwrite portal-managed assignment.
- The runner disrupts the pilot user.

## Evidence To Collect

- Portal screenshots from runners list, runner detail, and command queue.
- Command ID and final status.
- `result_upload_id`.
- Raw evidence ID and scan ID.
- Relevant Laravel log excerpts.
- Runner logs with secrets redacted.
- Local outbox state.
- Task Scheduler status.

## What Not To Test Yet

- Direct `repair_update`.
- Runner self-update over Direct HTTPS.
- Token rotation UI.
- Per-runner tokens.
- Mass rollout.
- HQ or multi-PC Direct HTTPS deployment.
- Outbox cleanup.
- Load testing.
- Runner or collector code changes.
