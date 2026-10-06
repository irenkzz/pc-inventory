# MariaDB/MySQL backup and manual restore

`php artisan inventory:backup` writes `database.sql` (via `mysqldump --single-transaction --routines --no-tablespaces`) into the backup directory. Set `INVENTORY_MYSQLDUMP_PATH` if `mysqldump` is not on PATH. The command reports FAIL and exits non-zero if the dump fails.

There is deliberately no restore command.

## Restore (manual)

WARNING: restoring overwrites current data. Stop the web server and scheduler first, back up the current database, and restore into an empty or scratch database before touching production.

1. Create an empty database (or drop and recreate the target one).
2. Load the dump, supplying the password via the environment, not the command line:
   ```powershell
   $env:MYSQL_PWD = '<password>'
   Get-Content database.sql -Raw | mysql --host=<host> --user=<user> <database>
   Remove-Item Env:MYSQL_PWD
   ```
3. Restore `raw_archive.zip` into `storage/app/inventory/raw_archive` (and `site_tokens.json` if needed).
4. Verify:
   ```powershell
   php artisan migrate:status
   php artisan inventory:doctor
   ```
   Compare row counts with `counts` in the backup `manifest.json`.
