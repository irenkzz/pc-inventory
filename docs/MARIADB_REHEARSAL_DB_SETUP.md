# Phase 19F - MariaDB/MySQL Rehearsal Database Setup

## 1. Purpose and non-goals

Phase 19F prepares the MariaDB/MySQL rehearsal database foundation for a later migration dry-run. It covers controlled MariaDB service validation or installation, empty rehearsal database creation, rehearsal-only app user creation, privilege validation, and non-secret evidence notes.

Approved decision: Option B - controlled MariaDB service and empty rehearsal database preparation.

Phase 19F does not approve production migration or cutover.

Non-goals:

- Do not switch the live Supermicro app to MariaDB/MySQL.
- Do not change live `.env`.
- Do not change live `APP_URL`.
- Do not change live DB driver.
- Do not mutate production data.
- Do not run production migration.
- Do not approve cutover.
- Do not point live runners/collectors to rehearsal.
- Do not expose rehearsal app as a web endpoint.
- Do not run `php artisan serve`.
- Do not generate packages/site kits.
- Do not run Laravel migrations.
- Do not run app data transfer.
- Do not run SQLite-to-MySQL conversion.
- Do not create migration/import code.
- Do not create a migration readiness command.
- Do not create real rehearsal `.env`.
- Do not generate `APP_KEY`.
- Do not change runner behavior.
- Do not change collector behavior.
- Do not change API contracts.
- Do not change command lifecycle semantics.
- Do not enable Direct `repair_update`.
- Do not print `.env` values, `APP_KEY`, DB credentials, token secrets, token hashes, bearer tokens, raw CSV contents, command payload JSON, full configs, or full runner GUIDs.

## 2. Starting state

- Live app: `D:\inventory\laravel`.
- Rehearsal root: `D:\inventory-rehearsal`.
- Rehearsal app: `D:\inventory-rehearsal\laravel`.
- Verified copied SQLite source: `D:\inventory-rehearsal\source-copy\database.sqlite`.
- Copied SQLite source is read-only.
- Copied SQLite source size: `5,910,528` bytes.
- Copied SQLite integrity check returned `ok`.
- Raw archive copied to `D:\inventory-rehearsal\raw_archive`.
- Downloads/site kits copied to `D:\inventory-rehearsal\downloads`.
- `.env.rehearsal.example` exists.
- Real rehearsal `.env` must not exist.
- `D:\inventory-rehearsal\laravel\database\database.sqlite` must not exist.
- Live app still uses SQLite.
- Live `.env` remains unchanged.
- Live `APP_URL` remains `https://inventory-pilot.internal.lan`.
- Direct `repair_update` remains blocked.
- Collector-share remains supported and unaffected.

## 3. DB engine decision

Prefer MariaDB `10.11` LTS for the Supermicro rehearsal target.

Do not use MySQL `8.0` for a new install unless explicitly required by organizational standard. If the organization requires MySQL, use a supported LTS line such as MySQL `8.4` LTS after separate approval.

Phase 19F may install or validate MariaDB `10.11` LTS only on Supermicro after documentation review. It must run as a managed Windows service. Prefer localhost-only binding first if practical.

## 4. Supermicro-only manual setup

Run manual DB setup only on Supermicro after documentation review. Do not run DB setup from IT-ADMIN.

Pre-check live app remains unchanged:

```powershell
cd D:\inventory\laravel
git status --short
git rev-parse HEAD
php artisan inventory:install-preflight
php artisan inventory:production-readiness
```

Do not run migrations from the live path.

Install or validate DB service:

- Install MariaDB `10.11` LTS if no approved MariaDB/MySQL service exists.
- Run it as a Windows service.
- Prefer localhost-only access first.
- Do not expose DB broadly to the network unless separately approved.

Service validation:

```powershell
Get-Service | Where-Object { $_.Name -match 'MariaDB|MySQL' }
Test-NetConnection 127.0.0.1 -Port 3306
mariadb --version
mariadb-admin -u root -p ping
```

Do not print root/admin password.

## 5. Database and user names

Create empty rehearsal database:

```text
inventory_rehearsal
```

Create empty restore-test database:

```text
inventory_rehearsal_restore
```

Create rehearsal-only app user:

```text
inventory_rehearsal_app
```

Do not use names such as `inventory`, `inventory_prod`, `production`, or `live`.

## 6. Recommended SQL shape

Use a MariaDB admin/root session. Do not record passwords in docs, chat, screenshots, logs, or notes.

```sql
CREATE DATABASE inventory_rehearsal
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

CREATE DATABASE inventory_rehearsal_restore
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

CREATE USER 'inventory_rehearsal_app'@'localhost'
  IDENTIFIED BY '<rehearsal-db-password-not-recorded>';

GRANT SELECT, INSERT, UPDATE, DELETE,
      CREATE, ALTER, DROP, INDEX, REFERENCES
ON inventory_rehearsal.*
TO 'inventory_rehearsal_app'@'localhost';

GRANT SELECT, INSERT, UPDATE, DELETE,
      CREATE, ALTER, DROP, INDEX, REFERENCES
ON inventory_rehearsal_restore.*
TO 'inventory_rehearsal_app'@'localhost';
```

If `localhost` resolves differently and `127.0.0.1` is required, create/grant the same scoped privileges for:

```sql
'inventory_rehearsal_app'@'127.0.0.1'
```

Do not grant:

- `GRANT OPTION`
- `SUPER`
- `FILE`
- `PROCESS`
- `SHUTDOWN`
- `RELOAD`
- `CREATE USER`
- `ALTER USER`
- `ALL PRIVILEGES ON *.*`
- access to `mysql.*`
- access to live/production DBs
- `%` remote host access

## 7. Empty database validation

Validate schemas exist:

```sql
SELECT SCHEMA_NAME
FROM information_schema.SCHEMATA
WHERE SCHEMA_NAME IN ('inventory_rehearsal', 'inventory_rehearsal_restore');
```

Validate schemas are empty:

```sql
SELECT TABLE_SCHEMA, COUNT(*) AS table_count
FROM information_schema.TABLES
WHERE TABLE_SCHEMA IN ('inventory_rehearsal', 'inventory_rehearsal_restore')
GROUP BY TABLE_SCHEMA;
```

Expected:

- both schemas exist
- both schemas are empty

If no rows are returned from `information_schema.TABLES`, confirm this means both schemas have zero tables rather than missing schemas.

## 8. App-user isolation validation

Connect as `inventory_rehearsal_app` to `inventory_rehearsal`. Do not print the password.

Run:

```sql
SELECT DATABASE();
CREATE TABLE __phase19f_probe (id INT PRIMARY KEY);
DROP TABLE __phase19f_probe;
```

Confirm the database remains empty afterward.

Run:

```sql
SHOW GRANTS FOR 'inventory_rehearsal_app'@'localhost';
```

Expected:

- grants only on `inventory_rehearsal.*`
- grants only on `inventory_rehearsal_restore.*`
- no global grants
- no `GRANT OPTION`
- no admin privileges

Validate negative access by attempting connection/use of an unrelated/system DB such as `mysql` as `inventory_rehearsal_app`. It should fail.

## 9. Post-check boundaries

Confirm:

- `D:\inventory-rehearsal\laravel\.env` does not exist.
- `D:\inventory-rehearsal\laravel\database\database.sqlite` does not exist.
- `D:\inventory-rehearsal\source-copy\database.sqlite` still exists.
- Copied SQLite source remains read-only.
- No Laravel migrations ran.
- No app data transfer ran.
- No SQLite-to-MySQL conversion ran.
- No package/site-kit generation occurred.
- No `php artisan serve` was run.
- No HPE StoreEasy or DNS changes occurred.
- No runner/collector traffic changed.
- Live `.env`, live `APP_URL`, and live DB driver remain unchanged.
- No secrets were printed or recorded.

## 10. Non-secret notes

Create a notes file under:

```text
D:\inventory-rehearsal\notes
```

Include only:

- Phase 19F date/time.
- DB engine and version.
- Windows service name.
- Database names created.
- User name created.
- Credential storage location label only, not the password.
- Service validation result.
- Empty database validation result.
- App-user isolation validation result.
- Confirmation that migrations/data transfer/web endpoint/live config/package generation/runner-collector changes did not happen.

Do not include real DB credentials, `.env` values, `APP_KEY`, token secrets, token hashes, bearer tokens, raw CSV contents, command payload JSON, full configs, or full runner GUIDs.

## 11. Stop conditions

Stop immediately if:

- live `.env` changes
- live `APP_URL` changes
- live DB driver changes
- production data mutates
- real rehearsal `.env` is created
- Laravel migrations run
- app data transfer starts
- SQLite-to-MySQL conversion starts
- `php artisan serve` starts
- HPE StoreEasy or DNS changes
- runners/collectors are pointed to rehearsal
- packages/site kits are generated
- DB credentials or secrets appear in logs/docs/chat/screenshots
- app user receives global privileges or access outside rehearsal DBs
- empty DBs contain unexpected tables after Phase 19F

## 12. Acceptance criteria

Phase 19F is accepted when:

- MariaDB `10.11` LTS preference is documented.
- Supermicro-only manual setup is documented.
- MariaDB/MySQL service validation is documented.
- `inventory_rehearsal` database is created and empty.
- `inventory_rehearsal_restore` database is created and empty.
- `inventory_rehearsal_app` user exists.
- App user is scoped only to rehearsal databases.
- App user has no global/admin privileges.
- Negative access to unrelated/system DBs fails.
- Non-secret notes are recorded under `D:\inventory-rehearsal\notes`.
- Real rehearsal `.env` is not created.
- Live `.env`, `APP_URL`, and DB driver remain unchanged.
- No Laravel migrations run.
- No app data transfer runs.
- No SQLite-to-MySQL conversion runs.
- No package/site-kit generation occurs.
- No web endpoint is exposed.
- No runner/collector traffic changes.
- No production data is mutated.
- No secrets are printed or recorded.

## 13. Cutover-not-approved statement

Phase 19F prepares only the empty rehearsal database foundation. It does not approve production migration, cutover, live DB driver switching, live `.env` changes, app data transfer, or Laravel migration execution.

## 14. Supermicro runtime validation

Phase 19F status: documented, executed, and Supermicro validated.

Runtime validation facts:

- MariaDB service: `Running`.
- Port `3306` on `127.0.0.1`: reachable.
- MariaDB version: `10.11.16-MariaDB`.
- `inventory_rehearsal`: exists and empty.
- `inventory_rehearsal_restore`: exists and empty.
- `inventory_rehearsal_app`: created.
- Grants are scoped only to `inventory_rehearsal.*` and `inventory_rehearsal_restore.*`.
- Negative access test completed and failed as expected for unrelated/system DB access.
- No DB password, root password, or credential value was printed or recorded.

Final boundary confirmations:

- Real rehearsal `.env` does not exist.
- `D:\inventory-rehearsal\laravel\database\database.sqlite` does not exist.
- `D:\inventory-rehearsal\source-copy\database.sqlite` still exists.
- Copied SQLite source remains read-only.
- No Laravel migrations ran.
- No app data transfer ran.
- No SQLite-to-MySQL conversion ran.
- No package/site-kit generation occurred.
- No `php artisan serve` was run.
- No HPE StoreEasy or DNS changes occurred.
- No runner/collector traffic changed.
- Live `.env`, live `APP_URL`, and live DB driver remain unchanged.
- No secrets were printed or recorded.

## 15. Phase 19G empty Laravel schema migration

Phase 19G status: executed and Supermicro validated.

Runtime facts:

- Real rehearsal `.env` exists only at `D:\inventory-rehearsal\laravel\.env`.
- New rehearsal `APP_KEY` was generated and not printed or recorded.
- DB password was stored only in rehearsal `.env` and the operator password store.
- Laravel migrations were run only from `D:\inventory-rehearsal\laravel`.
- Migration target: `inventory_rehearsal`.
- `php artisan migrate` completed successfully.
- `php artisan migrate:status` showed all migrations as `Ran`.
- `inventory_rehearsal` table count: `20`.
- `inventory_rehearsal_restore` remained empty / no table-count row.
- `D:\inventory-rehearsal\laravel\database\database.sqlite` does not exist.
- `D:\inventory-rehearsal\source-copy\database.sqlite` exists.
- `D:\inventory-rehearsal\source-copy\database.sqlite` remains read-only.
- Live git status was clean after boundary check.

Boundary confirmations:

- Live `D:\inventory\laravel\.env` was not changed.
- Live `APP_URL` was not changed.
- Live DB driver was not changed.
- Live SQLite was not mutated.
- No app data transfer occurred.
- No SQLite-to-MySQL conversion occurred.
- No web endpoint was exposed.
- No `php artisan serve` was run.
- No package/site-kit generation occurred.
- No runner/collector traffic changed.
- No HPE StoreEasy change occurred.
- No DNS change occurred.
- No production cutover was approved.
- No `.env` values, `APP_KEY`, DB password, token secrets, token hashes, bearer tokens, raw CSV contents, command payload JSON, full configs, or full runner GUIDs were printed or recorded.

## 16. Phase 19H transfer planning follow-up

Phase 19H app-aware SQLite-to-MariaDB transfer planning is documented in `docs/MARIADB_REHEARSAL_TRANSFER_PLAN.md`.

Phase 19H is Option A documentation-only. It recommends a future dry-run-only command named `php artisan inventory:mariadb-rehearsal-transfer` with explicit `--source="D:\inventory-rehearsal\source-copy\database.sqlite"` and no silent default source. It does not implement the command, run transfer, reset/truncate tables, dump MariaDB, restore into `inventory_rehearsal_restore`, expose a web endpoint, run operational validation, change live config, or approve cutover.
