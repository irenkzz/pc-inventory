# Runner Release 1.0.22

## Summary

Runner package `1.0.22` adds conservative Direct HTTPS local outbox retention cleanup.

The cleanup is intentionally limited to Direct HTTPS runner history folders and does not change upload retry behavior, ACK retry behavior, command lifecycle, Laravel ingest, Laravel raw archive behavior, collector-share mode, or Direct HTTPS API contracts.

## Scope

Included:

- Direct HTTPS `outbox/sent` cleanup.
- Direct HTTPS `outbox/failed` cleanup.
- Cleanup summary logging.
- Warning-only cleanup failure handling.

Not included:

- Direct `repair_update`.
- Runner self-update over Direct HTTPS.
- Token rotation UI.
- Per-runner token enrollment.
- Collector-share outbox or share cleanup.
- Laravel raw archive cleanup.

## Retention Policy

Cleanup runs only when `transport_mode=direct_https`.

Path safety:

- Cleanup is constrained under the resolved Direct HTTPS outbox root.
- Files outside the Direct HTTPS outbox root are not eligible for deletion.
- Unknown or unclassified files are skipped.

Folder policy:

- `outbox/pending` is never deleted or cleaned.
- `outbox/sent` files are eligible only when older than 14 days and outside the newest 100 retained files.
- `outbox/failed` files are eligible only when older than 30 days and outside the newest 100 retained files.

Cycle timing:

- Cleanup runs near the end of the Direct HTTPS runner cycle.
- Upload retry recovery runs before cleanup.
- ACK retry recovery runs before cleanup.
- Cleanup errors are logged as warnings and do not fail the runner cycle.

## Validation Result

IT-ADMIN was refreshed as a Direct HTTPS runner on package `1.0.22`.

Observed validation:

- HTTPS endpoint `https://inventory-pilot.internal.lan/health` returned 200.
- Runner config showed `transport_mode=direct_https`.
- Runner config showed `serverBaseUrl=https://inventory-pilot.internal.lan`.
- Scheduled task `LastTaskResult=0`.
- Heartbeat success was observed.
- Direct command poll success was observed.
- Upload status `direct_upload_uploaded` was observed.
- Cleanup summary log entries were observed.
- `outbox/pending` had 0 files and was not deleted or touched.
- No cleanup warnings or cleanup errors were observed.
- Collector stayed disabled and was not involved.
- Direct `repair_update` remained blocked.

Validation result: passed for the Direct HTTPS outbox cleanup release.

## Rollout Notes

- Official package and site-kit regeneration should happen on Supermicro, the active Laravel host.
- IT-ADMIN remains the source repo workflow location; do not modify generated site kits on IT-ADMIN.
- Direct HTTPS runner rollout is currently a manual package refresh or reinstall process.
- After refreshing a runner, verify the portal shows runner version `1.0.22`, Direct HTTPS transport, recent heartbeat, recent direct poll, and uploaded inventory state.
- Review `runner-main.log` for cleanup summary lines after at least one normal Direct HTTPS runner cycle.

## Safety Boundaries

- No Laravel business logic changes are part of this release.
- No collector changes are part of this release.
- No Direct HTTPS API payload contract changes are part of this release.
- No command lifecycle changes are part of this release.
- No direct `repair_update` enablement is part of this release.
- Collector-share mode remains unaffected.
- Laravel raw archive behavior remains unchanged.
- Token secrets must not be printed in logs, reports, screenshots, or support notes.

## Remaining Limitations

- Direct `repair_update` is still unsupported.
- Rollout to Direct HTTPS runners is manual package refresh or reinstall for now.
- Cleanup does not touch pending files.
- Cleanup does not touch Laravel raw archive.
- Cleanup does not affect collector-share mode.
- Token rotation UI is still not done.
- Per-runner token enrollment is still not done.
