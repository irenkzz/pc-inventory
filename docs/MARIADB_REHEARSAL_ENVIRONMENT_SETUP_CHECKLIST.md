# Phase 19C - MariaDB/MySQL Rehearsal Filesystem Setup Checklist

## 1. Purpose and non-goals

Phase 19C provides a reviewed manual checklist for preparing the non-production MariaDB/MySQL rehearsal filesystem environment on Supermicro without touching the live app and without starting DB or migration work.

Phase 19C is documentation/checklist only in Git.

Phase 19D follow-up: `tools/Prepare-MariaDbRehearsalFilesystem.ps1` adds an optional helper for this checklist. The helper is dry-run by default and must be copied/run manually on Supermicro for execution. It does not install MariaDB/MySQL, create DB/users, run migrations, create a real `.env`, transfer app data, expose a web endpoint, generate packages, or touch live runner/collector traffic.

Phase 19D status: implemented, tested, pushed, Supermicro dry-run validated, and Supermicro filesystem execution validated with expected `WARN`.

Non-goals:

- Do not write application code.
- Do not add PowerShell automation scripts.
- Do not install MariaDB/MySQL.
- Do not create MariaDB databases or users.
- Do not run Laravel migrations.
- Do not run app data transfer.
- Do not switch the live Supermicro app to MariaDB/MySQL.
- Do not change the live `.env`.
- Do not change live `APP_URL`.
- Do not mutate production data.
- Do not approve cutover.
- Do not point live runners or collectors to rehearsal.
- Do not publish rehearsal URL in DNS.
- Do not point HPE StoreEasy live proxy to rehearsal.
- Do not generate official packages from rehearsal.
- Do not generate official packages from IT-ADMIN.
- Do not change runner behavior.
- Do not change collector behavior.
- Do not change Direct HTTPS API contracts.
- Do not change collector API contracts.
- Do not change command lifecycle semantics.
- Do not enable Direct `repair_update`.
- Do not expose secrets.

## 2. Relationship to Phase 19A and Phase 19B

Phase 19A documents the migration dry-run and rehearsal plan in `docs/MARIADB_MIGRATION_DRY_RUN_RUNBOOK.md`.

Phase 19B documents the rehearsal environment preparation rules in `docs/MARIADB_REHEARSAL_ENVIRONMENT_PREP.md`.

Phase 19C makes the next setup decision: only controlled filesystem preparation is allowed after review. It does not start database installation, database/user creation, Laravel migrations, data transfer, web binding, or cutover work.

## 3. Approved Phase 19C decision: Option B

Approved decision: Option B - controlled filesystem-only rehearsal preparation on Supermicro.

Phase 19C allows only:

- Create `D:\inventory-rehearsal` folder structure on Supermicro after review.
- Create/copy reviewed Laravel source into `D:\inventory-rehearsal\laravel` after review.
- Copy verified SQLite backup to `D:\inventory-rehearsal\source-copy\database.sqlite`.
- Copy raw archive to `D:\inventory-rehearsal\raw_archive`.
- Copy downloads/site kits to `D:\inventory-rehearsal\downloads`.
- Create `.env.rehearsal.example` only, with placeholders and no real secrets.
- Keep the rehearsal app unexposed as a web endpoint.

Phase 19C does not choose:

- Option A: documentation-only with no setup guidance.
- Option C: full rehearsal environment base with MariaDB install, DB/user creation, real `.env`, or migrations.

Do not create a real rehearsal `.env` yet unless a later phase approves it.

## 4. Live versus rehearsal boundaries

Live app:

```text
D:\inventory\laravel
```

Rehearsal app:

```text
D:\inventory-rehearsal\laravel
```

Boundary rules:

- Never replace live `.env` with rehearsal `.env`.
- Never run rehearsal commands from the live path.
- Never expose rehearsal through `https://inventory-pilot.internal.lan`.
- Never use the live pilot hostname in rehearsal `.env` or templates.
- Never point HPE StoreEasy live proxy to rehearsal.
- Never point live runners or collectors to rehearsal.
- Never treat copied raw archive/download files as Git-tracked artifacts.

## 5. Supermicro manual setup checklist

Manual checklist after review:

1. Confirm current live app remains at `D:\inventory\laravel`.
2. Confirm current branch/revision to copy from reviewed source.
3. Create `D:\inventory-rehearsal` root.
4. Create the rehearsal directory structure listed in section 6.
5. Create/copy reviewed Laravel source into `D:\inventory-rehearsal\laravel`.
6. Create `.env.rehearsal.example` in the rehearsal Laravel path with placeholders only.
7. Copy a verified SQLite backup or approved controlled snapshot to `D:\inventory-rehearsal\source-copy\database.sqlite`.
8. Mark the copied SQLite file read-only if practical.
9. Copy the live raw archive to `D:\inventory-rehearsal\raw_archive`.
10. Copy the live downloads/site kits to `D:\inventory-rehearsal\downloads`.
11. Preserve timestamps where practical.
12. Record source backup timestamp and Git commit/revision in `D:\inventory-rehearsal\notes` without secrets.
13. Confirm no DNS, HPE StoreEasy, runner, or collector configuration references rehearsal.
14. Confirm no generated artifacts or secret-bearing files are staged in Git.

This checklist does not require executing rehearsal application commands.

Optional helper after Phase 19D review:

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File .\tools\Prepare-MariaDbRehearsalFilesystem.ps1
powershell -NoProfile -ExecutionPolicy Bypass -File .\tools\Prepare-MariaDbRehearsalFilesystem.ps1 -Execute
powershell -NoProfile -ExecutionPolicy Bypass -File .\tools\Prepare-MariaDbRehearsalFilesystem.ps1 -Execute -SqliteBackupPath "D:\path\to\verified-backup.sqlite"
powershell -NoProfile -ExecutionPolicy Bypass -File .\tools\Prepare-MariaDbRehearsalFilesystem.ps1 -Execute -SqliteBackupPath "D:\path\to\verified-backup.sqlite" -CopyRawArchive -CopyDownloads
```

Run `-Execute` only after manually copying the helper to Supermicro and confirming it targets Supermicro paths. IT-ADMIN may run static tests only.

## 6. Rehearsal directory structure

Recommended directory structure:

```text
D:\inventory-rehearsal\laravel
D:\inventory-rehearsal\backups
D:\inventory-rehearsal\backups\sqlite
D:\inventory-rehearsal\backups\raw_archive
D:\inventory-rehearsal\backups\downloads
D:\inventory-rehearsal\backups\mariadb_dumps
D:\inventory-rehearsal\backups\command_outputs_redacted
D:\inventory-rehearsal\source-copy
D:\inventory-rehearsal\raw_archive
D:\inventory-rehearsal\downloads
D:\inventory-rehearsal\logs
D:\inventory-rehearsal\notes
```

The copied SQLite source should live at:

```text
D:\inventory-rehearsal\source-copy\database.sqlite
```

Do not place rehearsal files under `D:\inventory\laravel`.

## 7. Rehearsal Laravel source copy rules

Rules:

- Use reviewed source only.
- Keep the rehearsal source copy separate from the live app.
- Do not use the live codebase with only a separate `.env` as the default.
- Do not copy live `.env` into rehearsal.
- Do not create a real rehearsal `.env` in Phase 19C.
- Do not run Composer, Artisan migrations, tests, package generation, or web serving unless a later phase approves it.
- Do not copy live scheduled tasks into rehearsal.
- Do not change runner or collector files for rehearsal.

The goal is a reviewed filesystem layout, not a runnable rehearsal app.

## 8. .env.rehearsal.example rules

Phase 19C may create `.env.rehearsal.example` only.

The Phase 19D helper creates `.env.rehearsal.example` only. It must not create a real rehearsal `.env`, copy live `.env`, copy or print `APP_KEY`, or generate `APP_KEY`.

## Phase 19D Supermicro validation

Supermicro dry-run command:

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File .\tools\Prepare-MariaDbRehearsalFilesystem.ps1
```

Dry-run result:

```text
Result: WARN
```

Expected dry-run warnings:

- SQLite backup copy skipped because no `-SqliteBackupPath` was supplied.
- Raw archive copy skipped because `-CopyRawArchive` was not supplied.
- Downloads/site kits copy skipped because `-CopyDownloads` was not supplied.

Supermicro filesystem execution command:

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File .\tools\Prepare-MariaDbRehearsalFilesystem.ps1 -Execute
```

Execution result:

```text
Result: WARN
```

Observed:

- `D:\inventory-rehearsal` created.
- `D:\inventory-rehearsal\laravel` created/copied.
- `D:\inventory-rehearsal\backups` created.
- `D:\inventory-rehearsal\source-copy` created.
- `D:\inventory-rehearsal\raw_archive` created.
- `D:\inventory-rehearsal\downloads` created.
- `D:\inventory-rehearsal\logs` created.
- `D:\inventory-rehearsal\notes` created.
- `.env.rehearsal.example` exists.
- Real `.env` does not exist.
- `D:\inventory-rehearsal\laravel\database\database.sqlite` does not exist.
- `D:\inventory-rehearsal\source-copy\database.sqlite` does not exist yet because no verified SQLite backup was supplied.
- Non-secret setup notes file was created: `setup-notes-20260509-214250.txt`.
- Robocopy exit code `1` was treated as non-fatal because files were copied.

Confirmed:

- No MariaDB/MySQL installation.
- No DB/user creation.
- No Laravel migrations.
- No app data transfer.
- No real `.env`.
- No live `.env` change.
- No live `APP_URL` change.
- No production data mutation.
- No package/site-kit generation.
- No runner/collector traffic changes.
- No secrets exposed.

## Phase 19E verified source/evidence copy

Phase 19E status: executed and validated with expected `WARN`.

Summary:

- Verified SQLite backup copied to `D:\inventory-rehearsal\source-copy\database.sqlite`.
- The copied SQLite source is the rehearsal migration source copy.
- The live SQLite database was not used directly as the transfer source.
- Copied SQLite file exists.
- Copied SQLite file size: `5,910,528` bytes.
- Copied SQLite file is read-only: `True`.
- SQLite integrity check on the copied DB returned `ok`.
- Raw archive copied to `D:\inventory-rehearsal\raw_archive`.
- Downloads/site kits copied to `D:\inventory-rehearsal\downloads`.
- Robocopy warnings were non-fatal: source copy exit code `3`, raw archive copy exit code `1`, downloads/site kits copy exit code `1`.
- Non-secret setup notes were written: `D:\inventory-rehearsal\notes\setup-notes-20260509-220015.txt`.

Important note: `sqlite3` CLI was not available on Supermicro, so SQLite integrity validation was completed using PHP/PDO against only `D:\inventory-rehearsal\source-copy\database.sqlite`.

Confirmed:

- Real rehearsal `.env` does not exist.
- `D:\inventory-rehearsal\laravel\database\database.sqlite` does not exist.
- No MariaDB/MySQL installation occurred.
- No DB/user creation occurred.
- No Laravel migrations ran.
- No app data transfer ran.
- No SQLite-to-MySQL conversion ran.
- No package/site-kit generation occurred.
- No runner/collector traffic changed.
- Live `.env` was not changed.
- Live `APP_URL` was not changed.
- Live DB driver was not changed.
- Production data was not mutated.
- No secrets were printed or recorded.

Placeholder example:

```env
APP_ENV=local
APP_DEBUG=true
APP_URL=http://127.0.0.1:8090

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=inventory_rehearsal
DB_USERNAME=inventory_rehearsal_app
DB_PASSWORD=<rehearsal-db-password-not-committed>
```

Rules:

- Do not commit `.env`.
- Do not copy live `APP_KEY`.
- Do not print `APP_KEY`.
- Do not document real DB credentials.
- Do not generate `APP_KEY` in Phase 19C.
- Do not use `https://inventory-pilot.internal.lan`.
- Do not put token secrets, token hashes, bearer tokens, full configs, or full runner GUIDs in the example.

## 9. SQLite backup/source-copy rules

Rules:

- Use verified SQLite backup or approved controlled snapshot.
- Do not use live SQLite directly as transfer source.
- Copy to `D:\inventory-rehearsal\source-copy\database.sqlite`.
- Mark read-only if practical.
- Record source backup timestamp and Git commit/revision in notes without secrets.
- Do not mutate live SQLite.
- Do not run data transfer in Phase 19C.
- Do not run SQLite-to-MySQL conversion in Phase 19C.

For final production migration later, require a maintenance window or write freeze.

## 10. Raw archive and downloads copy rules

Raw archive copy:

```text
Source: D:\inventory\laravel\storage\app\inventory\raw_archive
Target: D:\inventory-rehearsal\raw_archive
```

Downloads/site-kit copy:

```text
Source: D:\inventory\laravel\storage\app\inventory\downloads
Target: D:\inventory-rehearsal\downloads
```

Rules:

- Preserve timestamps where practical.
- Do not overwrite live raw archive.
- Do not overwrite live downloads/site kits.
- Do not stage copied files in Git.
- Do not print raw CSV contents.
- Do not generate official packages from rehearsal.
- Do not generate official packages from IT-ADMIN.
- Treat copied raw files as archived evidence references only.

## 11. No web binding / local-only APP_URL preparation

Phase 19C does not expose the rehearsal app as a web endpoint.

The placeholder `APP_URL` for the example file is:

```env
APP_URL=http://127.0.0.1:8090
```

Rules:

- Do not run `php artisan serve`.
- Do not publish rehearsal URL in DNS.
- Do not point HPE StoreEasy live proxy to rehearsal.
- Do not expose rehearsal through `https://inventory-pilot.internal.lan`.
- Do not place the rehearsal URL in runner or collector config.
- Do not use a future HTTPS rehearsal hostname until separately approved.

## 12. No live runner/collector traffic guardrails

Guardrails:

- Do not generate site kits/packages from rehearsal.
- Do not install runners or collectors against rehearsal.
- Do not copy live scheduled tasks into rehearsal.
- Do not place rehearsal URL in any runner config.
- Do not place rehearsal URL in any collector config.
- Keep HPE StoreEasy proxy pointed to live app only.
- Keep live Direct HTTPS runners pointed only at the approved live endpoint.
- Keep collector-share collectors pointed only at the approved live endpoint.
- Keep command and ACK semantics unchanged.
- Keep Direct `repair_update` blocked.

## 13. Safe live-path commands

From `D:\inventory\laravel`, only list these as safe read-only checks:

```powershell
php artisan inventory:install-preflight
php artisan inventory:production-readiness
php artisan inventory:doctor
php artisan inventory:direct-pilot-status
php artisan inventory:direct-site-kit-audit
git status --short
git rev-parse HEAD
```

Do not run these from the live path in Phase 19C:

```powershell
php artisan migrate
php artisan config:clear
php artisan config:cache
php artisan key:generate
php artisan inventory:backup
php artisan inventory:build-site-kit
```

## 14. Safe rehearsal-path commands

From `D:\inventory-rehearsal\laravel`, allowed only:

```powershell
php artisan --version
php artisan list
git status --short
git rev-parse HEAD
```

Do not run these from the rehearsal path in Phase 19C:

```powershell
php artisan migrate
php artisan migrate:fresh
php artisan inventory:doctor
php artisan inventory:production-readiness
php artisan inventory:install-preflight
php artisan inventory:backup
php artisan inventory:build-site-kit
php artisan serve
php artisan test
```

The rehearsal app is not yet expected to be fully configured or runnable.

## 15. Stop conditions

Abort if:

- Live `.env` is changed.
- Live `APP_URL` is changed.
- Live DB driver is switched.
- Live SQLite is modified unexpectedly.
- Production data is mutated.
- Rehearsal `.env` replaces live `.env`.
- Rehearsal `APP_URL` uses `https://inventory-pilot.internal.lan`.
- HPE StoreEasy proxy is pointed to rehearsal.
- Rehearsal URL is published in DNS.
- Runners or collectors are pointed to rehearsal.
- Generated artifacts are staged in Git.
- Secret-bearing files are staged in Git.
- Any `.env` value appears in docs, logs, chat, or screenshots.
- Any `APP_KEY` appears in docs, logs, chat, or screenshots.
- Any DB credential appears in docs, logs, chat, or screenshots.
- Any token secret appears in docs, logs, chat, or screenshots.
- Any token hash appears in docs, logs, chat, or screenshots.
- Any bearer token appears in docs, logs, chat, or screenshots.
- Any raw CSV content appears in docs, logs, chat, or screenshots.
- Any command payload JSON appears in docs, logs, chat, or screenshots.
- Any full config appears in docs, logs, chat, or screenshots.
- Any full runner GUID appears in docs, logs, chat, or screenshots.

Stop means preserve evidence, identify what boundary was crossed, and do not continue setup until reviewed.

## 16. Out of scope

Out of scope for Phase 19C:

- MariaDB/MySQL installation.
- MariaDB service configuration.
- DB/user creation.
- Real DB credentials.
- Real rehearsal `.env`.
- Laravel migrations.
- App data transfer.
- SQLite-to-MySQL conversion.
- Migration/import code.
- Migration readiness command.
- Production migration.
- Production cutover.
- Live `.env` changes.
- Live `APP_URL` changes.
- Live DB driver switch.
- Live data mutation.
- Package/site-kit generation from rehearsal.
- Package/site-kit generation from IT-ADMIN.
- HPE StoreEasy proxy changes.
- DNS publication for rehearsal.
- Runner/collector repointing.
- Runner behavior changes.
- Collector behavior changes.
- API contract changes.
- Command lifecycle changes.
- Direct `repair_update`.
- Token rotation UI.
- Per-runner token enrollment.
- IIS + PHP FastCGI implementation.

## 17. Acceptance criteria

Phase 19C is accepted when:

- Purpose and non-goals are documented.
- Relationship to Phase 19A and Phase 19B is documented.
- Approved Option B decision is documented.
- Live versus rehearsal boundaries are documented.
- Supermicro manual setup checklist is documented.
- Rehearsal directory structure is documented.
- Rehearsal Laravel source copy rules are documented.
- `.env.rehearsal.example` rules are documented.
- SQLite backup/source-copy rules are documented.
- Raw archive and downloads copy rules are documented.
- No web binding / local-only `APP_URL` preparation is documented.
- No live runner/collector traffic guardrails are documented.
- Safe live-path commands are documented.
- Safe rehearsal-path commands are documented.
- Stop conditions are documented.
- Out-of-scope boundaries are documented.
- No application code is changed.
- No PowerShell automation scripts are added.
- No MariaDB/MySQL installation is performed.
- No MariaDB databases or users are created.
- No Laravel migrations are run.
- No app data transfer is run.
- No live `.env` is changed.
- No live `APP_URL` is changed.
- No live DB driver is switched.
- No production data is mutated.
- No cutover is approved.
- No live runners or collectors are pointed to rehearsal.
- No rehearsal URL is published in DNS.
- No HPE StoreEasy live proxy change is made.
- No official packages are generated from rehearsal or IT-ADMIN.
- No runner or collector behavior is changed.
- No API contracts are changed.
- No command lifecycle semantics are changed.
- Direct `repair_update` remains blocked.
- No generated artifacts are staged.
- No secret-bearing files are staged.
