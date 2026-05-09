# Phase 17B - Production Deployment Decision

## 1. Decision Summary

Phase 17B is a planning and decision document only. It is not production approval, cutover approval, or permission to migrate production data.

Decisions:

- SQLite remains pilot/local only.
- The recommended production database target is MariaDB/MySQL on Supermicro.
- The current SQLite database remains in place until a separately approved migration phase.
- The recommended Windows production web-server model is IIS + PHP FastCGI.
- HPE StoreEasy remains the TLS termination reverse proxy for now.
- Backup and restore testing are required before any production cutover.

Phase 17B does not execute a production migration, change `.env`, mutate production data, modify runner or collector code, change Direct HTTPS API contracts, change command lifecycle semantics, enable Direct `repair_update`, add token rotation UI, add per-runner enrollment, generate packages/site kits, or expose secrets.

Follow-up: Phase 19A documents the MariaDB/MySQL migration dry-run and restore rehearsal process in `docs/MARIADB_MIGRATION_DRY_RUN_RUNBOOK.md`. It remains documentation-only and does not approve production migration, live `.env` changes, DB driver switching, or cutover.

Phase 19B follow-up documents rehearsal environment preparation in `docs/MARIADB_REHEARSAL_ENVIRONMENT_PREP.md`, recommending a separate Supermicro rehearsal path at `D:\inventory-rehearsal` and local-only rehearsal binding before any dry-run migration is executed.

Phase 19C follow-up documents the filesystem-only rehearsal setup checklist in `docs/MARIADB_REHEARSAL_ENVIRONMENT_SETUP_CHECKLIST.md`. It is still pre-migration planning and does not approve MariaDB/MySQL installation, DB/user creation, migrations, data transfer, web binding, live `.env` changes, or cutover.

Phase 19E follow-up: the verified SQLite rehearsal source and evidence files have been copied into `D:\inventory-rehearsal` and validated without migration or cutover approval. MariaDB/MySQL installation, DB/user creation, migrations, app data transfer, live `.env`/`APP_URL` changes, and production cutover remain unapproved.

Phase 19F follow-up: controlled MariaDB/MySQL rehearsal database setup is documented in `docs/MARIADB_REHEARSAL_DB_SETUP.md`. This prepares only empty rehearsal databases and scoped rehearsal app user validation; it does not approve Laravel migrations, app data transfer, live DB driver switching, live `.env` changes, or production cutover.

Phase 19F Supermicro validation confirms the rehearsal MariaDB foundation is ready: MariaDB `10.11.16-MariaDB` is running on localhost, rehearsal schemas exist and are empty, and the rehearsal app user is scoped only to rehearsal databases. This remains rehearsal-only and does not approve live DB driver switching, app data transfer, migrations, or cutover.

Phase 19G follow-up: the empty Laravel schema was migrated only into the MariaDB rehearsal database `inventory_rehearsal`; all migrations show `Ran`, with `20` rehearsal tables and an empty restore-test database. This remains rehearsal-only and does not approve app data transfer, live DB driver switching, live `.env` changes, web exposure, runner/collector traffic changes, or production cutover.

Phase 19H follow-up: app-aware transfer planning is documented in `docs/MARIADB_REHEARSAL_TRANSFER_PLAN.md`. This remains planning-only and does not approve transfer execution, restore rehearsal, live DB driver switching, live `.env` changes, package generation, runner/collector traffic changes, or production cutover.

Phase 19I follow-up: dry-run-only rehearsal transfer command is implemented for validation/preview only. It has no execute mode and does not approve transfer execution, restore rehearsal, live DB driver switching, live `.env` changes, package generation, runner/collector traffic changes, or production cutover.

## 2. Current Deployment Context

- Active Laravel app runs on Supermicro at `D:\inventory\laravel`.
- IT-ADMIN development/Codex repo is `D:\xampp\htdocs\inventaris`.
- Git workflow: IT-ADMIN prepares source changes; Supermicro pulls reviewed changes.
- Official packages and site kits are generated only on Supermicro.
- Direct HTTPS endpoint is active at `https://inventory-pilot.internal.lan`.
- HPE StoreEasy terminates HTTPS and reverse-proxies to Laravel on Supermicro.
- Direct HTTPS is the second transport for small/no-IT sites.
- Collector-share remains supported and remains the main HQ/multi-PC mode.
- Laravel database state is authoritative.
- CSV files are archived evidence only.
- Google Drive is backup/sync only.
- Commands are asynchronous: polling means delivery, and ACK is the execution result.
- Direct `repair_update` remains blocked for Direct HTTPS MVP.

Current operational commands:

```powershell
php artisan inventory:doctor
php artisan inventory:direct-pilot-status
php artisan inventory:direct-site-kit-audit
php artisan inventory:direct-runner-triage {runnerId}
php artisan inventory:production-readiness
```

Phase 17A validated `php artisan inventory:production-readiness` on Supermicro with final `Result: WARN`. Current warnings include non-production env/debug state, SQLite production DB decision unresolved, pilot/internal hostname, trusted proxy uncertainty, token rotation UI not done, per-runner token enrollment not done, advanced rate limiting not done, manual Direct HTTPS rollout, larger rollout not validated, production web-server/process/TLS not finalized, Direct `repair_update` unsupported for Direct HTTPS, and backup policy not verified.

## 3. Database Options Reviewed

### SQLite pilot-only

SQLite is already working and has low administration overhead. It is acceptable for local validation and the current pilot.

Conclusion: acceptable for pilot/local only. Do not choose SQLite as the final production DB for broader operational use.

### SQLite small internal production with strict backup/maintenance rules

SQLite could be used only as a fallback for a very small internal deployment with one Laravel app instance writing to it, tested automated backups, clear downtime tolerance, and no near-term medium rollout expectation.

Conclusion: fallback only. Not recommended for broader use.

### MySQL/MariaDB

MariaDB/MySQL fits Laravel 10 well, is practical on Windows/Supermicro, has mature backup/restore tooling, and gives this internal portal a balanced production path without excessive operational complexity.

Conclusion: recommended production database target for this project.

### PostgreSQL

PostgreSQL is technically strong and robust. It is a good database, but it adds operational overhead unless the team already operates PostgreSQL.

Conclusion: technically strong, but secondary recommendation unless already used internally.

### SQL Server

SQL Server can be a good fit in Windows-heavy organizations that already standardize on it, but Laravel/PHP driver and ODBC/PDO management add deployment risk for this project.

Conclusion: choose only if SQL Server is already the organization standard.

## 4. Recommended DB Path

- Keep the current SQLite database for pilot.
- Plan a MariaDB/MySQL migration as a separate approved phase.
- Do not run a DB migration or DB driver switch in Phase 17B.
- Require a migration rehearsal before production cutover.
- Require no pending migrations before migration.
- Require green Laravel tests before migration.
- Require pre-migration backups of database, raw archive, downloads/site kits, and token metadata through DB backup.
- Require post-migration validation:

```powershell
php artisan inventory:doctor
php artisan inventory:doctor --production
php artisan inventory:production-readiness
```

## 5. Backup Policy Required Before Cutover

No restore test means no production cutover.

Required backup scope:

- Database backup: authoritative Laravel data, including devices, scans, snapshots, raw file records, runners, collectors, commands, sites, assignments, change logs, and token metadata.
- Raw archive backup: `laravel/storage/app/inventory/raw_archive/`.
- Generated site kits/downloads backup: `laravel/storage/app/inventory/downloads/`.
- Site token metadata backup through the database backup.
- Restore test to a non-production path before cutover.

Minimum backup policy for MariaDB/MySQL:

- Daily full database dump.
- Pre-deployment database dump.
- Pre-migration database dump.
- Retention: daily 14 days, weekly 8 weeks, monthly 6-12 months.
- Backup location: local protected folder plus external/NAS/backup target.

Restore rehearsal must verify:

```powershell
php artisan inventory:doctor
php artisan inventory:production-readiness
```

Also verify the portal opens and devices, runners, collectors, and raw evidence exist.

Rules:

- Backups are not operational source of truth; the Laravel database remains authoritative.
- Raw files are evidence and must not be overwritten.
- Backup must preserve raw archive timestamps and folder structure where practical.
- Do not export plaintext token secrets unless a separate sealed-secret procedure is approved.
- Token secrets, bearer tokens, token hashes, DB credentials, Google credentials, raw CSV contents, command payload JSON, `.env` values, `APP_KEY` values, full configs, and full runner GUIDs must not be printed in runbooks, screenshots, logs, or support notes.

## 6. Web-server / Process Model

### `php artisan serve`

Use only for local validation, short pilot work, or emergency testing.

Conclusion: pilot/local only. Not acceptable for production service or always-on internal portal use.

### IIS + PHP FastCGI

Recommended Windows production model:

```text
HPE StoreEasy TLS reverse proxy
        -> Supermicro IIS site
        -> PHP FastCGI
        -> Laravel public/index.php
        -> MariaDB/MySQL service
```

Conclusion: recommended for Windows/Supermicro production.

### Caddy/Nginx reverse proxy to PHP-FPM

This can be a good model but adds more moving parts on Windows. Use it only if the team intentionally wants that stack on Supermicro.

Conclusion: acceptable alternative, not the primary recommendation.

### Apache

Apache is acceptable if existing operations already use it, but it is not preferred for this Windows/Supermicro path.

Conclusion: acceptable only with existing operational preference.

### Windows service/supervisor concerns

- IIS should run as a managed Windows service.
- MariaDB/MySQL should run as a managed Windows service.
- Scheduled Laravel tasks can be added later only if needed.
- Do not introduce Laravel queue workers unless the app later requires them.

## 7. TLS / Proxy Model

Recommendation: keep HPE StoreEasy as the TLS termination reverse proxy for now.

Reasons:

- It already works for the pilot.
- Direct HTTPS second pilot passed through it.
- It centralizes certificate handling.
- It avoids changing too many production layers at once.

Required forwarded headers:

```text
Host
X-Forwarded-Host
X-Forwarded-Proto=https
X-Forwarded-Port=443
X-Forwarded-For
X-Real-IP
```

Rules:

- Windows runners must trust the certificate chain.
- No HTTP for Direct HTTPS runners.
- Do not disable TLS validation.
- Do not use `-SkipCertificateCheck`.
- Laravel must use HTTPS `APP_URL` in production.
- Laravel must use `SESSION_SECURE_COOKIE=true` in production.
- Trusted proxy handling must honor forwarded HTTPS headers.
- For pilot, `https://inventory-pilot.internal.lan` is acceptable.
- For production, approve a final HTTPS hostname such as `https://inventory.internal.lan`.

## 8. Production Laravel Environment Policy

Required production values:

```env
APP_ENV=production
APP_DEBUG=false
APP_URL=https://inventory.internal.lan
SESSION_SECURE_COOKIE=true
```

`APP_URL` may use a different final HTTPS hostname only after approval.

After changing production environment configuration on Supermicro:

```powershell
cd D:\inventory\laravel
php artisan config:clear
php artisan cache:clear
php artisan route:clear
php artisan view:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

Rules:

- `APP_DEBUG=true` is acceptable only in pilot/local.
- `APP_DEBUG=true` in production is a hard blocker.
- `.env` values must never be printed.
- `APP_KEY` presence can be checked, but its value must never be printed.

## 9. Monitoring / Log Review Required Before Production

Minimum pre-production monitoring window:

- 3-7 days for pilot.
- Longer before HQ/multi-PC expansion.

Review daily:

- Laravel logs: `storage/logs/laravel.log`, HTTP 500 errors, auth/token failures, Direct HTTPS heartbeat/poll/upload/ACK errors, collector intake errors, raw archive write failures, and backup failures.
- Web server logs: IIS or chosen web-server access/error logs.
- HPE StoreEasy reverse proxy logs: TLS/certificate warnings, upstream timeout, 502/503 errors, and HTTP-to-HTTPS mismatch.
- Database state: backup completion, restore test evidence, DB service status, disk free space, and slow/error logs if available.
- Direct HTTPS checks: heartbeat, poll, upload, and ACK status for pilot runners.
- Collector-share sanity: collector heartbeat, runner count, command flow, and no Direct HTTPS poll warnings for collector-share runners.

Run from Supermicro:

```powershell
php artisan inventory:doctor --production
php artisan inventory:production-readiness
php artisan inventory:direct-pilot-status
php artisan inventory:direct-site-kit-audit
php artisan inventory:direct-runner-triage IT-ADMIN
php artisan inventory:direct-runner-triage LAPTOP-I76TA97E
```

## 10. Out of Scope for Phase 17B

Phase 17B is documentation/planning only.

Out of scope:

- No production DB migration execution.
- No DB driver switch.
- No token rotation UI.
- No per-runner token enrollment.
- No advanced rate limiting implementation.
- No Direct `repair_update`.
- No runner code changes.
- No collector code changes.
- No Direct HTTPS API contract changes.
- No command lifecycle changes.
- No generated packages or site kits from IT-ADMIN.
- No portal UI changes.
- No production data mutation.
- No `.env` value printing.
- No secret export.
- No update to `inventory:production-readiness`.
- No backup job implementation unless separately approved.
- No IIS/Caddy/Apache implementation unless separately approved.

## 11. Risks

- Migration without restore test: highest operational risk because the database is authoritative.
- SQLite complacency: SQLite is fine for pilot, but indefinite scaling increases concurrency, backup, and recovery risk.
- TLS/proxy header drift: forwarded headers, `APP_URL`, trusted proxy handling, and secure cookies must stay consistent or login/generated URLs can break.
- Secret exposure: operators must not paste `.env`, token values, bearer tokens, raw configs, raw payloads, `APP_KEY`, DB credentials, or full runner GUIDs into support output.
- Changing too many layers at once: changing DB, web server, TLS termination, token model, and runner packages together makes failures hard to isolate.
- False production approval: this decision document recommends a production model but does not approve cutover.

## 12. Acceptance Criteria

Phase 17B is accepted when:

- DB decision is documented.
- Web-server/process decision is documented.
- TLS/proxy decision is documented.
- Backup/restore policy is documented.
- `APP_ENV` / `APP_DEBUG` / config-cache policy is documented.
- Monitoring checklist is documented.
- Out-of-scope boundaries are documented.
- No application code is changed.
- No runner code is changed.
- No collector code is changed.
- No Direct HTTPS API contract or command lifecycle semantics are changed.
- Direct `repair_update` remains blocked.
- No production data is mutated.
- No generated artifacts are staged.
- No secret-bearing files are staged.
