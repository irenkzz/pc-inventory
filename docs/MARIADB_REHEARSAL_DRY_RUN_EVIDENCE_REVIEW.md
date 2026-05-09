# Phase 19J - Dry-run Evidence Review

## 1. Purpose

Phase 19J is a read-only evidence review and decision phase before any execute-mode implementation.

It reviews Phase 19I dry-run evidence, especially:

- whether `referenced_evidence_missing_count=353` is a real missing evidence problem or a path-mapping problem
- what path shape is stored in `raw_files` evidence references
- whether `D:\inventory-rehearsal\raw_archive` contains the expected copied evidence files
- whether future execute-mode transfer should preserve raw evidence references as-is, remap them, or store original plus resolved references
- whether execute-mode planning is safe after review or still blocked

## 2. Non-goals

- No code changes.
- No `--execute` mode.
- No data transfer.
- No table reset/truncate.
- No MariaDB dump or restore.
- No web endpoint exposure.
- No `php artisan serve`.
- No live config change.
- No live SQLite read or mutation.
- No package/site-kit generation.
- No runner/collector traffic change.
- No production migration or cutover approval.
- No secret, raw CSV content, command payload JSON, full config, or full runner GUID exposure.

## 3. Starting state

- Rehearsal app: `D:\inventory-rehearsal\laravel`.
- Copied SQLite source: `D:\inventory-rehearsal\source-copy\database.sqlite`.
- Copied raw archive: `D:\inventory-rehearsal\raw_archive`.
- MariaDB target: `inventory_rehearsal`.
- Restore DB: `inventory_rehearsal_restore`.
- Phase 19I dry-run result: `Result: WARN`.
- Key warning: `referenced_evidence_missing_count=353`.
- Phase 19I evidence counts:
  - `raw_files_count=353`
  - `raw_archive_reference_populated_count=353`
  - `missing_archive_path_reference_count=0`
  - `copied_raw_archive_root_exists=yes`
  - `referenced_evidence_missing_count=353`

Raw CSV files are archived evidence only, not operational source of truth. The database remains authoritative. The only allowed SQLite source is `D:\inventory-rehearsal\source-copy\database.sqlite`.

## 4. Read-only review checklist

Run these checks manually on Supermicro only. Keep output count-oriented and redacted.

Confirm rehearsal working directory:

```powershell
cd D:\inventory-rehearsal\laravel
git status --short
```

Rerun the Phase 19I dry-run:

```powershell
php artisan inventory:mariadb-rehearsal-transfer --source="D:\inventory-rehearsal\source-copy\database.sqlite" --dry-run
```

Inspect `raw_files` schema from the copied SQLite source only. Use PHP/PDO or another read-only SQLite method; do not use the live SQLite database. Record column names only, not row contents.

Count `raw_files` rows and populated evidence reference fields:

- total `raw_files` rows
- populated evidence reference count
- blank/missing evidence reference count
- distinct reference shape count

Group evidence reference path shapes without printing full file lists:

- absolute live path count
- absolute rehearsal path count
- storage-relative path count
- archive-relative path count
- filename-only path count
- mixed/unknown path count
- blank path count

Count files under the copied rehearsal raw archive:

```powershell
Get-ChildItem -LiteralPath D:\inventory-rehearsal\raw_archive -Recurse -File | Measure-Object
```

Compare expected referenced evidence count to copied archive file count. Record counts only.

Test a small redacted sample by suffix, basename, hash, or shortened path only. Do not print raw CSV contents or full file contents.

Record whether references can be resolved by remapping from the live archive root to the rehearsal archive root.

Confirm restore DB remains empty:

- `inventory_rehearsal_restore` exists
- table count remains zero

Confirm no data writes occurred:

- no rows inserted/updated/deleted in MariaDB
- no tables reset/truncated
- no dump/restore
- no web endpoint
- no package/site-kit generation
- no runner/collector traffic changes
- no live config changes

## 5. Decision matrix

### Outcome A: path-mapping explanation

All `353` missing references are explained by path mapping.

Decision: execute-mode planning may continue only if future transfer design includes safe raw evidence path handling.

Acceptable future approaches may include:

- preserve original reference and add/derive resolved rehearsal reference
- remap live archive root to rehearsal archive root during validation
- store original plus resolved evidence path metadata if a later code phase approves it

### Outcome B: genuine missing evidence

Some evidence files are genuinely missing from `D:\inventory-rehearsal\raw_archive`.

Decision: execute-mode remains blocked until raw archive copy is corrected or missing evidence is explicitly accepted.

### Outcome C: ambiguous references

`raw_files` references are inconsistent or ambiguous.

Decision: execute-mode remains blocked. Add a future read-only diagnostic improvement or mapping policy before execute-mode planning.

### Outcome D: secret or raw content exposure

Any secret, raw CSV contents, command payload JSON, full config, or full runner GUID appears in docs, logs, chat, screenshots, or review notes.

Decision: stop immediately and do not proceed.

## 6. Execute-mode gate

Execute-mode planning remains blocked unless Phase 19J concludes:

- raw evidence references are understood
- evidence file availability is confirmed or missing evidence is explained
- `classification_rules` pre-existing target rows are handled
- optional missing tables are accepted or mapped
- skipped optional raw/storage columns are understood
- no secret exposure occurred

Even if this gate passes, execute mode remains a later separately approved phase. Phase 19J does not approve transfer execution or cutover.

## 7. Acceptance criteria

Phase 19J is accepted when:

- Phase 19J evidence review document exists.
- `referenced_evidence_missing_count=353` investigation plan is documented.
- Safe read-only checks are documented.
- Decision matrix is documented.
- Execute-mode remains explicitly unapproved unless evidence review later approves planning.
- Production cutover remains explicitly unapproved.
- No code/tests/scripts changed.
- No generated artifacts, rehearsal files, `.env`, or secret-bearing files are staged.
- No secrets or raw CSV contents are documented.

## 8. Cutover-not-approved statement

Phase 19J is not production approval and not cutover approval. It does not approve execute-mode transfer, MariaDB restore rehearsal, live DB driver switching, live `.env` changes, HPE StoreEasy/DNS changes, runner/collector repointing, or package/site-kit generation.
