# Rename a site id

```powershell
php artisan inventory:rename-site SITE-HQ SITE-BHX1C            # dry-run (default), changes nothing
php artisan inventory:rename-site SITE-HQ SITE-BHX1C --force    # execute
```

Options: `--dry-run` (wins over `--force`), `--skip-tokens` (leave the token store alone).

1. **Back up the database and the token file first.**
2. Run the dry-run and check the per-table counts.
3. Run with `--force`. DB tables (`collector_sites`, `collectors`, `runners`, `runner_commands`) are updated in one transaction; then the token key is renamed for all token types (collector and direct_runner), keeping expiry/revoked/last_used fields. An audit entry `site.renamed` is written (row counts only).

Refuses when: new id is not uppercase letters/numbers/underscore/dash, old is unknown, new already exists (no merge), or tokens come from `INVENTORY_SITE_TOKENS_JSON` (env, read-only; rename the key there by hand and pass `--skip-tokens`).

If the token rename fails after the DB commit, the command exits non-zero and prints the manual fix (rename the key in the token file).

## Follow-up checklist

- Collector config: `site_id` and the site token header.
- Branch share folder `...\sites\OLD` -> `...\sites\NEW`.
- Every runner config: `siteId` (and token).
- Rebuild site kits from profiles using the new id; old kits become invalid.

**Warning:** runners and collectors still using the old id get 401 until updated.
