# AGENTS.md — Internal Windows PC Inventory System

Always-loaded Codex guardrail. Keep this short. Load `PROJECT_CONTEXT.md` or `docs/DIRECT_HTTPS_RUNNER_CHECKPOINT.md` only when needed.

## Current System

- Laravel 10 central app lives in `laravel/`.
- Windows runner lives in `runner/` and stays PowerShell/client-side.
- Collector lives in `collector/` and stays a lightweight relay.
- Legacy Flask/Python backend is reference-only.
- Database is authoritative.
- CSV snapshots are archived evidence, not operational truth.
- Google Drive is backup/sync only.
- Active Laravel app runs on Supermicro at `D:\inventory\laravel`.
- IT-ADMIN development/Codex repo is `D:\xampp\htdocs\inventaris`.
- Supermicro pulls reviewed Git changes from IT-ADMIN source work.
- Official packages/site kits are generated only on Supermicro.
- Direct HTTPS pilot endpoint: `https://inventory-pilot.internal.lan` via HPE StoreEasy HTTPS reverse proxy.
- Current runner package version: `1.0.22`.

## Non-Negotiable Rules

- Stay on Laravel 10.
- Do not redesign as browser-only scanner or generic CRUD.
- Do not replace runner with PHP.
- Do not move runner/collector responsibilities into Laravel.
- Do not put DB credentials, Google credentials, master secrets, or global tokens on clients.
- Commands are asynchronous; never describe manual scan or repair/update as direct remote execution.

## Component Boundaries

Laravel owns:
- authoritative records, ingest, identity matching, change detection, assignments
- command queue state, runner/collector/direct-runner visibility
- site-token verification, diagnostics, reports, site-kit generation

Runner owns:
- hardware scan, scheduled task, local state
- collector-share file writes/reads
- direct HTTPS heartbeat/upload/poll/ACK/retry
- local repair/update bootstrap

Collector owns:
- site-token relay to Laravel
- branch-share file movement
- heartbeat/CSV/ACK upload and command polling

## Transport Rules

Collector-share mode:
`runner -> local/SMB branch share -> collector -> Laravel`

Direct HTTPS mode:
`runner -> Laravel HTTPS`

Direct HTTPS:
- is second transport for small/no-IT sites
- must not replace/break collector-share mode
- uses `direct_runner` token, not collector token
- runner only uploads, polls, ACKs, retries
- Laravel remains authoritative

## Command Rules

- Polling means delivery, not execution.
- ACK is the execution result.
- Direct command polling is at-least-once; stale dispatched/unacked commands may be redelivered.
- Direct `repair_update` is blocked for MVP.
- Direct MVP supports `scan_now` / `manual_scan`.

## Identity / Assignment / Storage Rules

- Use weighted identity; never one serial field alone.
- Strong signals: valid UUID, motherboard serial, MAC, asset code.
- Placeholder/generic identity values are invalid or weak.
- Department/site/location/room are portal-managed.
- CSV assignment fields are evidence only.
- Storage health uses bundled `smartctl.exe` only.
- TBW is best-effort; missing SMART/NVMe/TBW is blank/NULL, never zero.
- Prefer `risk_level`, `risk_reasons`, `recommended_action` over TBW alone.

## Development Rules

- Inspect before editing.
- Make small, reviewable changes.
- Add targeted tests before business-logic changes.
- Preserve collector-share regressions when changing direct mode.
- Preserve direct-mode regressions when changing auth/upload/command logic.
- Preserve raw archive behavior, duplicate/idempotency behavior, site-token boundary, command state transitions, heartbeat semantics, identity weighting, and invalid serial handling.

## Useful Commands

```powershell
php artisan migrate
php artisan migrate:status
php artisan inventory:doctor
php artisan inventory:direct-pilot-status
php artisan inventory:production-readiness
php artisan inventory:site-tokens --type=all
php artisan inventory:register-site-token SITE-HQ --type=direct_runner --reveal
php artisan inventory:build-site-kit --profile=..\deployment\profiles\generated\SITE-HQ.json
```
