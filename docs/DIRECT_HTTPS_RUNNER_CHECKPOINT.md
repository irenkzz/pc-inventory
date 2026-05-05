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

### Laravel

- `direct_runner` token type
- `DirectRunnerAuthService`
- `POST /api/direct-runner/heartbeat`
- `POST /api/direct-runner/scans`
- `POST /api/direct-runner/commands/poll`
- `POST /api/direct-runner/commands/ack`
- Direct command redelivery safety for stale dispatched/unacked commands
- Portal blocks `repair_update` for direct HTTPS runners

### Runner

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

### Deployment / Site Kit

- `transport_mode=collector_share|direct_https`
- direct HTTPS site profile validation
- direct HTTPS site-kit generation
- runner-only direct site kit by default
- new direct HTTPS sample profile

## Safety Rules

- Laravel remains authoritative.
- Runner only scans, uploads, polls, ACKs, and retries.
- CSV remains archived evidence.
- Direct runner uses `direct_runner` site token, not collector token.
- Collector-share mode must remain unchanged.
- No DB credentials, Google credentials, master secrets, or global tokens in runner config.
- Direct command polling is at-least-once; commands must tolerate redelivery.
- Direct `repair_update` is blocked for MVP.

## Validated Pilot Result

Manual test on runner `IT-ADMIN` passed end-to-end:

- Direct heartbeat succeeded
- Direct scheduled scan upload succeeded
- Direct `scan_now` command was delivered
- Runner executed scan command
- Scan upload created `result_upload_id`
- Direct ACK succeeded
- Laravel command reached `status=succeeded`

Confirmed command fields:

- `acknowledged_at` filled
- `completed_at` filled
- `completion_status=succeeded`
- `result_upload_id` filled

## Known Limitations

- Direct `repair_update` is not supported yet.
- Token rotation UI is not done.
- Sent/failed retention cleanup is not done.
- Advanced rate limiting is not done.
- Per-runner token enrollment is not done.
- Portal visibility for direct mode still needs improvement.
- Direct mode is intended first for small/no-IT sites, not large branches.

## Next Recommended Steps

1. Improve portal visibility for direct HTTPS runners.
2. Add sent/failed retention cleanup policy.
3. Pilot one more direct HTTPS runner on a different machine/network.
4. Add direct-mode troubleshooting docs.
5. Later evaluate direct `repair_update` support.