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

## 9. Phase 19J manual Supermicro review result

Manual Phase 19J review was completed on Supermicro using count-only, redacted PowerShell/PHP helper output under `D:\inventory-rehearsal\logs`. Temporary helper/log files were not added to the repository.

First copied raw archive count:

- `raw_archive_file_count=563`

Raw evidence path audit:

- `phase=19J_raw_evidence_review`
- `mode=read_only_count_only`
- `sqlite_source_exists=yes`
- `raw_archive_root_exists=yes`
- `raw_archive_file_count=563`
- `raw_files_row_count=353`
- `raw_files_schema_columns=id,raw_hash,original_filename,saved_path,raw_format,received_at,metadata_json,created_at,updated_at,upload_id`
- `likely_reference_columns=original_filename,saved_path`
- review note: counts only; no raw CSV contents, file lists, or secret values

`original_filename` review:

- populated: `353`
- blank: `0`
- exists as stored under rehearsal root only: `0`
- exists after rehearsal mapping: `0`
- basename seen in archive: `0`
- unresolved after rehearsal mapping: `353`
- shape: filename-only `353`

`saved_path` review:

- populated: `353`
- blank: `0`
- exists as stored under rehearsal root only: `0`
- exists after rehearsal mapping: `0`
- basename seen in archive: `353`
- unresolved after rehearsal mapping: `353`
- shape: relative-or-unknown `353`

Saved-path suffix review:

- `phase=19J_saved_path_suffix_review`
- `mode=read_only_count_only`
- `sqlite_source_exists=yes`
- `raw_archive_root_exists=yes`
- `archive_file_count=563`
- `archive_unique_basename_count=563`
- `archive_duplicate_basename_value_count=0`
- `raw_files_row_count=353`
- `saved_path_populated=353`
- `saved_path_blank=0`
- `basename_exists_in_archive=353`
- `basename_unique_in_archive=353`
- `basename_duplicate_in_archive=0`
- `exact_relative_path_exists=0`
- `unique_suffix_resolved=353`
- `ambiguous_suffix_resolved=0`
- `unresolved_after_suffix_review=0`
- `unique_suffix_segments_1=353`

Decision: Outcome A - path/reference-shape mapping explanation.

Interpretation:

- The copied raw archive is present and contains `563` files.
- `raw_files` contains `353` rows.
- `saved_path` is populated for all `353` `raw_files` rows.
- `saved_path` does not resolve as stored.
- `exact_relative_path_exists=0`.
- All `353` saved-path basenames exist in the copied raw archive.
- All `353` basename matches are unique.
- `unique_suffix_resolved=353`.
- `unresolved_after_suffix_review=0`.
- Therefore, `referenced_evidence_missing_count=353` is explained by path-shape/path-mapping behavior, not by missing raw evidence files.
- The original Phase 19I dry-run path check did not understand the stored `saved_path` shape.

Future design requirement:

- Future execute-mode transfer must include explicit safe raw evidence path handling.
- Do not rely silently on basename-only matching unless uniqueness is proven.
- The safest later design is likely to preserve original `saved_path` and add or derive a resolved copied/rehearsal archive path during validation/transfer logic.
- Implementation is not approved in Phase 19J.

Boundaries:

- Execute-mode transfer remains unapproved.
- Production cutover remains unapproved.
- No code, tests, helper scripts, temporary logs, data transfer, table reset/truncate, dump, restore, web endpoint, package/site-kit generation, live config change, live SQLite read/mutation, MariaDB application-table writes, runner/collector traffic change, command lifecycle change, Direct `repair_update`, secret exposure, raw CSV exposure, command payload JSON exposure, full config exposure, or full runner GUID exposure occurred.

## 10. Phase 19K follow-up

Phase 19J Outcome A feeds into Phase 19K raw evidence path policy in `docs/MARIADB_REHEARSAL_EXECUTE_MODE_DESIGN.md`.

Phase 19K documents that future execute-mode design must preserve original `raw_files.saved_path`, derive rehearsal archive resolution only for validation, require uniqueness checks before basename/suffix matching is trusted, and fail closed on ambiguous or unresolved evidence references.

Phase 19J did not approve execute transfer, restore rehearsal, production migration, or cutover.
