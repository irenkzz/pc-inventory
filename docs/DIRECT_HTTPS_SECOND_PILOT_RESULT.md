# Direct HTTPS Second Pilot Result

## Summary

The second Direct HTTPS pilot passed end-to-end on runner `LAPTOP-I76TA97E`.

The pilot confirmed that a Windows runner can use Direct HTTPS through the internal HTTPS endpoint, appear in the Laravel portal, upload inventory, poll commands, ACK command execution, and leave collector-share mode unaffected.

Direct HTTPS remains a second transport for small or no-IT sites. It does not replace collector-share mode.

## Environment

- Runner ID: `LAPTOP-I76TA97E`
- Transport: Direct HTTPS
- Runner version: `1.0.21`
- Site: `SITE-HQ`
- HTTPS endpoint: `https://inventory-pilot.internal.lan`
- HTTPS termination: HPE StoreEasy reverse proxy
- Active Laravel app: Supermicro
- Source workflow: IT-ADMIN source repo only; Supermicro pulls reviewed changes from Git

The HPE StoreEasy reverse proxy terminates HTTPS and forwards traffic to the active Laravel app on Supermicro.

## Validation Steps Completed

- Confirmed DNS resolves `inventory-pilot.internal.lan` to the HPE reverse proxy.
- Confirmed TCP 443 connectivity succeeds.
- Confirmed HTTPS `/health` returns 200.
- Confirmed login works over HTTPS after the proxy header fix and browser restart.
- Regenerated the site kit on Supermicro with `serverBaseUrl=https://inventory-pilot.internal.lan`.
- Installed the Direct HTTPS runner successfully.
- Confirmed the runner appeared in the portal.
- Confirmed heartbeat populated.
- Confirmed direct command poll populated.
- Confirmed inventory upload and ingest succeeded.
- Confirmed Devices list shows the laptop inventory.
- Queued a manual scan command.
- Confirmed the manual scan command was delivered, ACKed, and succeeded.
- Confirmed portal cleanup fixed Direct HTTPS Last Inventory display.
- Confirmed portal cleanup fixed the Direct HTTPS repair/update guardrail on runner list/detail surfaces.

## Issues Found And Fixes

- HTTPS login initially needed proxy header handling and a browser restart before portal login behaved correctly through the reverse proxy.
- The runners list did not use `last_direct_upload_at` for Direct HTTPS Last Inventory. Portal cleanup now makes Direct HTTPS rows show the direct upload timestamp, or calm missing-upload wording.
- The runners list still showed an active-looking repair/update action for Direct HTTPS runners. Portal cleanup now shows Direct HTTPS MVP blocked wording instead.

No runner, collector, API payload, command lifecycle, identity matching, ingest/raw archive, assignment, or storage health changes were required for the pilot result.

## Final Pilot Result

Passed.

`LAPTOP-I76TA97E` successfully completed the Direct HTTPS MVP workflow:

- heartbeat
- direct command poll
- inventory upload and ingest
- portal inventory visibility
- manual scan delivery
- command ACK
- successful command completion

Collector-share mode remains unaffected. Direct `repair_update` remains blocked for Direct HTTPS MVP.

## Remaining Limitations

- Direct `repair_update` is still unsupported.
- Token rotation UI is not done.
- Sent/failed outbox retention cleanup is not done.
- Per-runner token enrollment is not done.
- Larger multi-PC/HQ rollout has not been validated.
- Direct mode remains intended first for small/no-IT sites.

## Recommended Next Steps

1. Keep Direct HTTPS limited to small/no-IT pilot candidates until a larger rollout is explicitly validated.
2. Add operational documentation for Direct HTTPS troubleshooting, including heartbeat, upload, poll, ACK, and proxy checks.
3. Define sent/failed outbox retention cleanup behavior before broader pilot use.
4. Plan token rotation UI and token handling runbooks before scaling Direct HTTPS.
5. Evaluate per-runner token enrollment after the site-token MVP is stable.
6. Continue preserving collector-share behavior while improving Direct HTTPS portal visibility.
