# Phase 19B - MariaDB/MySQL Rehearsal Environment Preparation

## 1. Purpose and non-goals

Phase 19B defines the safe MariaDB/MySQL rehearsal environment layout and rules before any dry-run migration is executed.

Phase 19B is documentation-only.

Non-goals:

- Do not install MariaDB/MySQL.
- Do not create MariaDB/MySQL databases or users.
- Do not run Laravel migrations.
- Do not run data transfer.
- Do not add a migration readiness command.
- Do not switch the live Supermicro app to MariaDB/MySQL.
- Do not change the live `.env`.
- Do not edit the live `APP_URL`.
- Do not mutate production data.
- Do not approve production cutover.
- Do not point live runners or collectors to a rehearsal environment.
- Do not change runner behavior.
- Do not change collector behavior.
- Do not change Direct HTTPS API contracts.
- Do not change collector API contracts.
- Do not change command lifecycle semantics.
- Do not enable Direct `repair_update`.
- Do not generate official packages from IT-ADMIN or rehearsal.
- Do not stage generated artifacts.
- Do not expose secrets.

## 2. Relationship to Phase 19A runbook

Phase 19A documented the full MariaDB/MySQL migration dry-run and rehearsal plan in `docs/MARIADB_MIGRATION_DRY_RUN_RUNBOOK.md`.

Phase 19B narrows the next planning step to environment preparation:

- where the rehearsal files should live
- how the live and rehearsal paths stay separate
- how the copied SQLite source, raw archive, and downloads/site kits should be handled
- how rehearsal `.env` handling avoids touching the live `.env`
- which commands are safe to document for later validation
- which stop conditions abort preparation

Phase 19B does not execute the Phase 19A dry-run.

Phase 19C follow-up: `docs/MARIADB_REHEARSAL_ENVIRONMENT_SETUP_CHECKLIST.md` documents Option B, a controlled filesystem-only setup checklist for Supermicro. It allows reviewed folder/source/copy preparation only and still excludes MariaDB installation, DB/user creation, real rehearsal `.env`, migrations, data transfer, web binding, runner/collector repointing, package generation, and cutover approval.

Phase 19D follow-up: `tools/Prepare-MariaDbRehearsalFilesystem.ps1` is an optional helper for the Phase 19C filesystem-only checklist. It is dry-run by default and must be copied/run manually on Supermicro for execution. It creates folder structure, copies reviewed source/evidence only when requested, creates `.env.rehearsal.example` placeholders only, and writes non-secret notes; it does not install DB software, create DB/users, run migrations, create a real `.env`, transfer app data, expose a web endpoint, generate packages, or touch live runner/collector traffic.

Phase 19D Supermicro validation completed with expected `WARN` for both dry-run and `-Execute`. The execution created `D:\inventory-rehearsal`, copied the rehearsal Laravel source, created `.env.rehearsal.example`, created non-secret setup notes, and skipped SQLite/raw archive/download copies because no optional copy inputs were supplied.

Phase 19E completed the verified SQLite source and evidence copy into the rehearsal filesystem with expected `WARN`. The verified SQLite backup now exists at `D:\inventory-rehearsal\source-copy\database.sqlite`, is read-only, has size `5,910,528` bytes, and passed SQLite integrity check `ok` via PHP/PDO because `sqlite3` CLI was unavailable. Raw archive and downloads/site kits were copied to their rehearsal paths; no MariaDB/MySQL install, DB/user creation, migrations, data transfer, real `.env`, live `.env`/`APP_URL` change, package generation, runner/collector traffic change, production data mutation, or secret exposure occurred.

Phase 19F MariaDB/MySQL rehearsal database setup is documented in `docs/MARIADB_REHEARSAL_DB_SETUP.md`. It approves only controlled MariaDB service and empty rehearsal database preparation on Supermicro: MariaDB `10.11` LTS preference, empty `inventory_rehearsal` and `inventory_rehearsal_restore` databases, scoped `inventory_rehearsal_app` user, service/empty DB/app-user isolation validation, and non-secret notes. It does not approve migrations, app data transfer, real rehearsal `.env`, live config changes, package generation, web exposure, runner/collector traffic changes, or cutover.

Phase 19F Supermicro runtime validation is complete: MariaDB `10.11.16-MariaDB` service is running, `127.0.0.1:3306` is reachable, `inventory_rehearsal` and `inventory_rehearsal_restore` exist and are empty, `inventory_rehearsal_app` exists with grants scoped only to those rehearsal databases, and negative access to unrelated/system DBs failed as expected. No credentials or secrets were printed or recorded.

## 3. Recommended rehearsal environment layout

Recommended layout: same Supermicro host with a separate Laravel copy/path.

Recommended rehearsal root:

```text
D:\inventory-rehearsal
```

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
D:\inventory-rehearsal\source-copy\database.sqlite
D:\inventory-rehearsal\raw_archive
D:\inventory-rehearsal\downloads
D:\inventory-rehearsal\logs
D:\inventory-rehearsal\notes
```

Live versus rehearsal boundary:

- Live app: `D:\inventory\laravel`
- Rehearsal app: `D:\inventory-rehearsal\laravel`
- Never replace live `.env` with rehearsal `.env`.
- Never run rehearsal commands from the live path.
- Never expose the rehearsal app through the live HTTPS endpoint.

Do not use the same live codebase with only a separate `.env` as the default. It is too easy to run commands from the wrong path and mutate the wrong database.

## 4. Supermicro versus IT-ADMIN responsibilities

IT-ADMIN responsibilities:

- Source and documentation work only.
- Prepare reviewed Git changes.
- Do not install MariaDB/MySQL.
- Do not create rehearsal databases or users.
- Do not generate official packages or site kits.
- Do not handle real DB passwords, token secrets, or live `.env` values in docs or chat.

Supermicro responsibilities after separate approval:

- Host or coordinate the future rehearsal path at `D:\inventory-rehearsal`.
- Keep live app at `D:\inventory\laravel`.
- Keep live `.env` unchanged.
- Keep live runners and collectors pointed only at the approved live endpoint.
- Keep HPE StoreEasy live reverse proxy pointed at the live app unless a separate rehearsal hostname is approved.
- Coordinate future MariaDB/MySQL installation, database/user creation, copied source data, and validation.

## 5. MariaDB/MySQL installation assumptions

Phase 19B does not install MariaDB/MySQL.

Recommended future rehearsal target:

- Prefer MariaDB `10.11` LTS for the Supermicro rehearsal target.
- Use MySQL `8.0` LTS-compatible line only if the organization standardizes on MySQL.

Future installation assumptions:

- MariaDB/MySQL runs as a managed service on Supermicro or an approved internal DB host.
- The rehearsal database is separate from any production/live database.
- Credentials are stored only in the rehearsal `.env` copy.
- DB credentials are never committed, pasted into chat, written into docs, or printed in screenshots/logs.

## 6. Rehearsal database and user naming

Recommended names for future approval:

- Database: `inventory_rehearsal`
- User: `inventory_rehearsal_app`
- Optional restore-test database: `inventory_rehearsal_restore`

Avoid names that imply production or live use:

- `inventory`
- `inventory_prod`
- `production`
- `live`

Privilege rules:

- The app user should have only the needed privileges on rehearsal databases.
- Do not use root/admin DB credentials in Laravel `.env`.
- Do not reuse live production credentials.
- Do not document real passwords.

Credential placeholders only:

```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=inventory_rehearsal
DB_USERNAME=inventory_rehearsal_app
DB_PASSWORD=<rehearsal-db-password-not-committed>
```

## 7. Rehearsal Laravel copy/path

Recommended future rehearsal app path:

```text
D:\inventory-rehearsal\laravel
```

Rules:

- The rehearsal Laravel copy must be separate from `D:\inventory\laravel`.
- The rehearsal copy should be created from reviewed Git source after separate approval.
- Rehearsal commands must be run only from `D:\inventory-rehearsal\laravel`.
- Live commands must be run only from `D:\inventory\laravel`.
- Operators should make the prompt/path visible before running any command.
- Do not copy live scheduled tasks into rehearsal.
- Do not generate official packages or site kits from the rehearsal app.

## 8. Rehearsal .env handling rules

Rules:

- Never commit rehearsal `.env`.
- Never replace live `.env` with rehearsal `.env`.
- Never paste DB password into docs, chat, screenshots, tickets, or command output.
- Never print DB credentials in command output.
- Store credentials only in the rehearsal `.env` copy.
- Keep live `.env` unchanged.
- Keep live DB driver unchanged.
- Keep rehearsal `.env` in `D:\inventory-rehearsal\laravel\.env` only.

The rehearsal `.env` may use placeholder documentation like the example in section 6, but real values must stay outside Git and outside written notes.

## 9. SQLite source copy procedure

Use a verified SQLite backup or controlled snapshot. Do not use the live SQLite database directly for transfer.

Future preparation procedure:

1. Identify a verified SQLite backup or approved controlled snapshot.
2. Copy the backup to:

   ```text
   D:\inventory-rehearsal\source-copy\database.sqlite
   ```

3. Mark the copied file read-only if practical.
4. Confirm the copied SQLite database is readable.
5. Record the source backup timestamp.
6. Record the Git commit/revision used for the rehearsal.

For final production migration later, require a maintenance window or write freeze before taking the final source copy.

## 10. Raw archive and downloads/site-kit copy procedure

Future raw archive copy:

```text
Source: D:\inventory\laravel\storage\app\inventory\raw_archive
Target: D:\inventory-rehearsal\raw_archive
```

Future downloads/site-kit copy:

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
- Treat copied raw files as evidence references, not operational source of truth.

## 11. Rehearsal APP_URL / binding rule

Prefer local-only binding first:

```env
APP_URL=http://127.0.0.1:8090
```

Rules:

- Do not use `https://inventory-pilot.internal.lan` for rehearsal.
- Do not publish the rehearsal URL in DNS during Phase 19B.
- Do not point HPE StoreEasy live reverse proxy to the rehearsal app.
- Do not expose rehearsal through the live Direct HTTPS endpoint.
- Keep rehearsal local-only until a separate approval expands access.

A future HTTPS rehearsal hostname may be approved separately, for example:

```text
https://inventory-rehearsal.internal.lan
```

That future hostname must not replace the live pilot hostname.

## 12. No live runner/collector traffic rule

Guardrails:

- Rehearsal app uses local-only URL by default.
- Do not generate site kits/packages from rehearsal.
- Do not install runners/collectors against rehearsal.
- Do not copy live scheduled tasks into rehearsal.
- Do not place rehearsal URL in any runner or collector config.
- Keep HPE StoreEasy proxy pointed to live app only.
- Keep live runners and collectors pointed only at the approved live endpoint.
- Do not point live Direct HTTPS runners at rehearsal.
- Do not point collector-share collectors at rehearsal.

Live runner/collector traffic must never become rehearsal traffic by accident.

## 13. Safe validation commands

For documentation work on IT-ADMIN:

```powershell
git status --short
git diff --stat
git diff --name-only
git diff --check
git diff --cached --stat
git diff --cached --name-only
git diff --cached --check
```

For later manual Supermicro live-state review, read-only only:

```powershell
cd D:\inventory\laravel
php artisan inventory:install-preflight
php artisan inventory:production-readiness
php artisan inventory:doctor
php artisan inventory:direct-pilot-status
php artisan inventory:direct-site-kit-audit
```

For future rehearsal Laravel copy after it exists:

```powershell
cd D:\inventory-rehearsal\laravel
php artisan --version
php artisan migrate:status
php artisan inventory:doctor
```

Phase 19B documentation implementation does not require executing Supermicro live-state or rehearsal commands.

## 14. Stop conditions

Abort environment preparation if:

- Live `.env` is changed.
- Live DB driver is switched.
- Live SQLite database is modified unexpectedly.
- Production data is mutated.
- Rehearsal `.env` replaces live `.env`.
- Rehearsal `APP_URL` uses the live pilot hostname.
- Runners or collectors are pointed to rehearsal.
- HPE StoreEasy proxy is pointed to rehearsal without approval.
- Any secret appears in docs, logs, screenshots, chat, or command output.
- Generated artifacts are staged in Git.
- Secret-bearing files are staged in Git.

Stop means preserve evidence, identify the boundary failure, and do not continue preparation until the cause is understood.

## 15. Out of scope

Out of scope for Phase 19B:

- Installing MariaDB/MySQL.
- Creating MariaDB databases/users.
- Running Laravel migrations.
- Running data transfer.
- Adding migration readiness command.
- Switching live Supermicro DB driver.
- Changing live `.env`.
- Editing live `APP_URL`.
- Mutating production data.
- Pointing runners/collectors to rehearsal.
- Publishing rehearsal endpoint through HPE StoreEasy.
- Generating official packages from rehearsal or IT-ADMIN.
- Changing runner behavior.
- Changing collector behavior.
- Changing API contracts.
- Changing command lifecycle semantics.
- Enabling Direct `repair_update`.
- Token rotation UI.
- Per-runner token enrollment.
- IIS + PHP FastCGI implementation.
- Production cutover approval.

Secret rules:

- Never print or document real `.env` values.
- Never print or document the real `APP_KEY` value.
- Never print or document DB credentials.
- Never print or document token secrets.
- Never print or document token hashes.
- Never print or document bearer tokens.
- Never print or document raw CSV contents.
- Never print or document command payload JSON.
- Never print or document full configs.
- Never print or document full runner GUIDs.

## 16. Phase 19B acceptance criteria

Phase 19B is accepted when:

- Purpose and non-goals are documented.
- Relationship to Phase 19A runbook is documented.
- Recommended rehearsal environment layout is documented.
- Supermicro versus IT-ADMIN responsibilities are documented.
- MariaDB/MySQL installation assumptions are documented without installing anything.
- Rehearsal database and user naming is documented.
- Rehearsal Laravel copy/path is documented.
- Rehearsal `.env` handling rules are documented.
- SQLite source copy procedure is documented.
- Raw archive and downloads/site-kit copy procedure is documented.
- Rehearsal `APP_URL` / binding rule is documented.
- No live runner/collector traffic rule is documented.
- Safe validation commands are documented.
- Stop conditions are documented.
- Out-of-scope boundaries are documented.
- No application code is changed.
- No migration readiness command is added.
- No MariaDB/MySQL installation is performed.
- No MariaDB/MySQL database or user is created.
- No Laravel migrations are run.
- No app data transfer is run.
- No live `.env` is changed.
- No live DB driver is switched.
- No production data is mutated.
- No production cutover is approved.
- No live runners or collectors are pointed to rehearsal.
- No runner behavior is changed.
- No collector behavior is changed.
- No Direct HTTPS or collector API contracts are changed.
- No command lifecycle semantics are changed.
- Direct `repair_update` remains blocked.
- No official packages are generated from IT-ADMIN or rehearsal.
- No generated artifacts are staged.
- No secret-bearing files are staged.
