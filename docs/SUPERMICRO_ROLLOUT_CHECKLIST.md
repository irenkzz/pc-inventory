# Supermicro Rollout Checklist (PRs #52–#57)

Covers rolling the merged work (security hardening, data safety, ops/UX, RBAC, signed updates) onto the Supermicro host at `D:\inventory\laravel`. Run from an elevated PowerShell on Supermicro. Stop at any failed gate and do not continue.

Nothing here approves production cutover (see Phase 17B). New features that change behavior are off by default and are enabled explicitly in section 6.

## 0. Before you start

- [ ] Pick a maintenance window. Runners and collectors tolerate a short server outage (collector now retries), but do not do this during a bulk scan hour.
- [ ] Confirm which database the host uses (SQLite pilot or MariaDB). Several steps differ.
- [ ] Confirm you can reach `https://inventory-pilot.internal.lan` from a test PC.
- [ ] Note the current state: `git log -1`, `php artisan migrate:status`, `php artisan inventory:doctor`. Save the output.

## 1. Backup (gate: restore path is known)

- [ ] `php artisan inventory:backup` completes without FAIL.
- [ ] SQLite: confirm `database.sqlite` copy exists in the backup folder.
- [ ] MariaDB: set `INVENTORY_MYSQLDUMP_PATH` if `mysqldump` is not on PATH, run the backup, confirm `database.sql` exists and is non-empty. If it prints FAIL, fix before continuing (the old behavior wrote only a note).
- [ ] Copy the backup off the host (second disk or the backup share).
- [ ] Back up `.env` and `storage\app\inventory\site_tokens.json` separately (secrets: store them securely, not in Git or chat).
- [ ] Read `docs/BACKUP_RESTORE_MARIADB.md` once so the restore steps are familiar.

## 2. Pull the code

- [ ] Supermicro pulls reviewed changes from Git (it does not take edits from IT-ADMIN directly): `git fetch` then confirm `main` is at the Merge commit of PR #57 or later.
- [ ] `git pull --ff-only` on `main`.
- [ ] `composer install --no-dev --optimize-autoloader` only if `composer.lock` changed (these PRs added no dependencies).
- [ ] `php artisan config:clear` and `php artisan route:clear`.

## 3. Migrations (gate: rollback plan exists)

Two new migrations:
`2026_10_06_000001_add_sort_filter_indexes` and `2026_10_06_100000_add_role_to_users_and_create_audit_log`.

- [ ] `php artisan migrate:status` shows exactly those two as pending.
- [ ] MariaDB only: run them first on the rehearsal database (see the Phase 19 rehearsal docs), not directly on production.
- [ ] `php artisan migrate --force`.
- [ ] `php artisan migrate:status` shows both as Ran.
- [ ] Existing users kept admin access: log in with an existing account and open the dashboard.
- [ ] Rollback if needed: `php artisan migrate:rollback --step=2` (indexes and audit table only; the `users.role` column is dropped too). Restore from backup only if the schema is damaged.

## 4. Configuration (`.env`)

Defaults preserve current behavior. Set these deliberately:

| Variable | Recommended | Notes |
|---|---|---|
| `INVENTORY_REQUIRE_SITE_TOKENS` | `true` | Closes the fail-open intake. Confirm every site has a token first (`php artisan inventory:site-tokens --type=all`). |
| `INVENTORY_CENTRAL_INTAKE_TOKEN` | set a long random value | Empty means `/api/intake/*` is open. |
| `INVENTORY_ALLOW_QUERY_SITE_TOKEN` | `false` after all runners/collectors send headers | Keep `true` until you have verified this; collectors and runners use headers today. |
| `INVENTORY_MAX_UPLOAD_KB` | default 5120 is fine | Raise only if real scans exceed 5 MB. |
| `INVENTORY_RUNNER_TARGET_VERSION` | `1.0.22` (default) | Single source for the "older version" filter. |
| `INVENTORY_BACKUP_SCHEDULE_ENABLED` | `true` | Plus `INVENTORY_BACKUP_RETENTION_DAYS`. |

- [ ] `.env` edited, then `php artisan config:clear`.
- [ ] `php artisan inventory:doctor` shows no FAIL. Review each WARN.
- [ ] `php artisan inventory:production-readiness` reviewed (WARN lines for open intake auth should disappear after the settings above).
- [ ] Confirm the scheduler still runs (Windows Task Scheduler entry for `php artisan schedule:run` every minute).

## 5. Smoke test the server (before touching any client)

- [ ] Portal login works; throttle: 5 wrong passwords in a minute returns 429 (expected).
- [ ] Dashboard, Runners, Devices, Changes, Reports pages load (Changes and reports are now paginated).
- [ ] `/audit-log` loads.
- [ ] Unauthenticated `GET /api/devices` returns 401 or a redirect (it used to be open).
- [ ] CSV exports work: devices, runners, and an existing report.
- [ ] Queue a manual scan for one test runner from the portal; the Commands page shows `requested_by` as your email, not `portal`.
- [ ] Create a read-only account: `php artisan inventory:user-role someone@example.com viewer`. Confirm the viewer sees no mutating buttons and gets 403 on a direct POST.

## 6. Optional features (enable one at a time)

Each is default-off. Enable, observe, then move on.

- [ ] **Stale-runner alert**: set `INVENTORY_ALERT_WEBHOOK_URL`, run `php artisan inventory:check-stale --hours=24` manually first, then set `INVENTORY_SCHEDULE_STALE_CHECK=true`.
- [ ] **Retention**: run `php artisan inventory:prune` (dry run, the default) and review counts per table. Only after a verified backup: `php artisan inventory:prune --force`. Then set `INVENTORY_RETENTION_SCHEDULE_ENABLED=true`. On MariaDB, run the dry run on the rehearsal copy first.
- [ ] **Scheduled doctor**: `INVENTORY_SCHEDULE_DOCTOR=true`.

## 7. Signing key (once, on Supermicro only)

- [ ] `php artisan inventory:update-keygen` (it refuses to overwrite without `--force`).
- [ ] Copy `storage\app\inventory\keys\update-signing-private.pem` to an **offline** location (encrypted USB or safe). Do not put it in Git, chat, or the shared drive. Losing it means a re-key and reinstall of every runner.
- [ ] Verify `git status` shows the key is ignored.
- [ ] Keep the printed public key XML for reference (it is not secret).
- [ ] Read `docs/RUNNER_SIGNED_UPDATES.md` (rollout and rotation).

## 8. Build kits and pilot (gate: no fleet rollout before this passes)

Official kits are generated only on Supermicro.

- [ ] Rotate or confirm site tokens as needed (`inventory:rotate-site-token`, `inventory:revoke-site-token`; rotating invalidates old kits, so regenerate afterwards).
- [ ] `php artisan inventory:build-site-kit --profile=..\deployment\profiles\generated\<SITE>.json` (add `--exe` for a single-file installer; the exe embeds the token, so treat it as a secret and never email it).
- [ ] Confirm the build output reports `signed: true` and no signing warning.
- [ ] Pilot PC A, SYSTEM-mode runner; Pilot PC B, current-user-mode runner. On each:
  - [ ] Run `INSTALL_THIS_PC_RUNNER_ONLY.cmd` (or the exe). It must end with `RESULT: PASS` and print the log path.
  - [ ] The stage folder under `%LOCALAPPDATA%\Temp\InternalInventorySiteKit` is gone afterwards.
  - [ ] `C:\ProgramData\InternalInventoryRunner` is not writable by a standard user, except `config\`, `state\`, `logs\`, `data\` for the task user in current-user mode. `trust\update-public-key.xml` exists.
  - [ ] Run `runner\scripts\check_runner_health.ps1` (exit 0 is healthy).
  - [ ] The portal shows the runner with a recent heartbeat, an upload, and the new "Security & health" fields (blank where unavailable is fine).
  - [ ] Queue a manual scan from the portal; it completes (ACK `completed`).
- [ ] Update test: publish a package signed with the new key and confirm a pilot runner accepts it. Then tamper one file in a copy and confirm the runner rejects it (`update_rejected`, failed ACK).
- [ ] Offline test: block the server for 15 minutes on a pilot PC, restore, and confirm queued uploads arrive (backoff may delay up to roughly 6 hours after a long outage).
- [ ] Collector site (if applicable): run the collector installer on a test branch; confirm a server outage does not move files to `failed\` (and that `relay.py requeue-failed --config <file>` works).

## 9. Fleet rollout

- [ ] Roll out in waves (one site at a time). Watch `/runners`, the stale-check output, and `inventory:direct-pilot-status` between waves.
- [ ] Runners already deployed stay in warn-only update mode until they receive a signed package or a new kit that carries the public key. Plan one bridge update, then confirm `requireSignedUpdates` is true on a sample.
- [ ] Note `FORCE_UPDATE_THIS_PC_RUNNER.cmd` bypasses the runner's signature check; only use it from a trusted kit.
- [ ] Collectors with an `http://` (non-localhost) `server_base_url` now refuse to start; fix their config to HTTPS before updating them.

## 10. After rollout

- [ ] `php artisan inventory:doctor` and `inventory:production-readiness` clean.
- [ ] Next-day check: scheduled backup ran, stale check ran, no growth surprises.
- [ ] After a week: consider `INVENTORY_ALLOW_QUERY_SITE_TOKEN=false`.
- [ ] Record the outcome (date, versions, pilot results) in the project notes.

## Rollback summary

| Problem | Action |
|---|---|
| Portal broken after pull | `git checkout` the previous merge commit, `php artisan config:clear`, restore DB only if migrations were applied and the schema is damaged. |
| A migration fails | Do not retry blindly. Restore the DB from the section 1 backup and review the error. |
| Runners fail after an update | Keep `requireSignedUpdates` false on the affected runners, republish the previous package, and review `check_runner_health.ps1` plus `C:\ProgramData\InternalInventoryRunner\logs`. |
| Collector stopped | Check `server_base_url` scheme and the collector log; run `relay.py requeue-failed` after fixing. |

## Known gaps (not part of this rollout)

- Site tokens are still stored in plaintext (the kit builder needs them).
- Signature check does not cover the initial install or a compromised build host.
- The elevated installer, ACL changes, signed-update flow, scanner additions and backoff were verified statically and with cross-language signature tests only; the pilot in section 8 is the first real-machine test.
