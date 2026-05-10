# Phase 19K - MariaDB Rehearsal Execute-mode Design

## 1. Purpose

Phase 19K documented execute-mode architecture and design boundaries for the MariaDB rehearsal transfer. Phase 19M implements the first controlled execute import into an empty, approved `inventory_rehearsal` target.

Phase 19M scope:

- `--execute` exists only on `inventory:mariadb-rehearsal-transfer`.
- Data transfer is limited to approved application/domain tables in `inventory_rehearsal`.
- No target reset or truncate.
- No MariaDB dump creation or restore execution.
- No writes to `inventory_rehearsal_restore`.
- No production migration.
- No production cutover approval.

The design builds on Phase 19I dry-run validation and Phase 19J manual evidence review. Phase 19J concluded Outcome A: `referenced_evidence_missing_count=353` is explained by raw evidence path-shape/path-mapping behavior, not missing copied evidence.

## 2. Phase 19K decision

Phase 19M is the first controlled execute implementation. Recommended next prompt after this phase is post-execute validation evidence review, not restore rehearsal or cutover.

Phase 19M.1 adds redacted rollback diagnostics and keeps rollback-on-first-failure behavior. Diagnostics may include failed approved import table, failed stage, rollback reason code, safe exception class, and safe SQLSTATE/category only. SQL text, bindings, row values, raw evidence content, command payload JSON, secrets, full configs, full runner GUIDs, raw filenames, and full paths remain forbidden in output.

Phase 19M.2 fixes the approved import manifest for real migrated `collector_sites`, which uses `site_id` as its primary key and has no `id` column. Primary-key preservation, schema mapping, dependency checks, and auto-increment validation are table-aware; `collectors` and `runners` remain dependent on successful `collector_sites` import and foreign keys remain enabled.

Later work should be split into small separately approved phases:

1. Phase 19L: read-only execute-readiness hardening, diagnostics, and tests only; no writes.
2. Phase 19M: execute import into an empty/approved `inventory_rehearsal` target only, with no self-reset.
3. Phase 19N: post-execute validation evidence review.
4. Later restore phase: restore rehearsal into `inventory_rehearsal_restore`, separately approved.

## 3. Command shape policy

Use the existing command name:

```powershell
php artisan inventory:mariadb-rehearsal-transfer
```

Policy:

- Do not create a separate execute command unless later implementation becomes too large.
- `--source` is always required.
- No silent source defaults.
- `--dry-run` remains read-only planning mode.
- `--readiness` remains read-only readiness mode.
- `--execute` is explicit Phase 19M write mode.
- `--dry-run`, `--readiness`, and `--execute` are mutually exclusive.
- Running without exactly one mode must fail.
- `--execute` requires all Phase 19M confirmation flags.
- Missing confirmation must fail closed.

## 4. Pre-execute gates

Any future execute mode must pass all gates before writes:

- current working/base path is exactly `D:\inventory-rehearsal\laravel`
- base path is not `D:\inventory\laravel`
- source path is exactly `D:\inventory-rehearsal\source-copy\database.sqlite`
- source path is not under `D:\inventory\laravel`
- source exists, is readable, is non-zero, and passes SQLite readability/integrity checks
- live SQLite is never opened
- `DB_CONNECTION=mysql`
- `DB_DATABASE=inventory_rehearsal`
- `APP_URL` does not contain `inventory-pilot.internal.lan`
- MariaDB target is reachable
- migrated schema exists
- `migrations` table exists and remains preserved
- all expected migrations are applied
- `inventory_rehearsal_restore` exists, is empty, and is untouched
- copied raw archive exists
- copied downloads path exists if referenced or needed
- pre-execute MariaDB dump requirement is satisfied
- no web endpoint exposure
- no `php artisan serve`
- no package/site-kit generation
- no runner/collector traffic change

## 5. Dump-before-execute policy

A MariaDB dump of the empty migrated `inventory_rehearsal` schema is mandatory before any future execute mode.

Policy:

- The operator creates the dump manually before execution.
- The transfer command must not create the dump.
- Store the dump under `D:\inventory-rehearsal\backups\mariadb_dumps`.
- Future execute mode must require an explicit dump marker/path argument.
- The command must verify that the dump marker/path:
  - is under the approved dump directory
  - exists
  - is non-zero
  - has a timestamp plausible for the current rehearsal
- Refuse execution if no dump marker/path is supplied.
- Output only safe metadata: dump filename or redacted path label, size, timestamp, and optional checksum.
- Do not print dump contents, DB credentials, command lines containing passwords, `.env` values, or secrets.

## 6. Target reset and destructive-write policy

The safest first execute implementation must not reset or truncate tables itself.

Policy:

- Future execute mode requires `inventory_rehearsal` to be in an approved pre-transfer state.
- Target application/domain tables must be empty before execute writes.
- If unexpected rows exist, fail closed.

Allowed pre-existing rows:

- `migrations` rows from Phase 19G
- `classification_rules` rows only if verified equivalent
- empty framework/transient tables if present
- no other application/domain rows

Tables allowed to receive imported rows only after all gates pass:

- `users`
- `collector_sites`
- `devices`
- `device_identities`
- `device_scans`
- `hardware_snapshots`
- `storage_health_observations`
- `network_observations`
- `peripherals`
- `device_assignments`
- `raw_files`
- `change_log`
- `collectors`
- `runners`
- `runner_commands`

Tables never cleared by execute mode:

- `migrations`
- `classification_rules`
- `inventory_rehearsal_restore` tables/database
- any live/production table
- any table outside `inventory_rehearsal`
- framework/transient tables unless a later reset phase explicitly approves it

Framework/system table policy:

- `migrations`: preserve.
- `classification_rules`: compare-only, preserve target.
- `personal_access_tokens`: do not import by default; block if source has rows unless separate token policy is approved.
- `password_reset_tokens`: do not import; transient.
- `jobs`, `failed_jobs`, `cache`, `sessions`: do not import; target should be empty or ignored only if empty.
- Unexpected transient/framework rows fail closed rather than being cleared.

`users` may be imported because it is application data needed for portal continuity, but only when target `users` is empty. Preserve primary keys.

Transactions and auto-increment:

- Data inserts should run inside a transaction where MariaDB supports it.
- Foreign keys should remain enforced if dependency order allows.
- Temporarily relaxing foreign keys requires later implementation review.
- After preserving primary keys, each imported auto-increment table must advance to `MAX(id)+1`.
- Non-auto-increment imported tables such as `collector_sites` are explicitly skipped by auto-increment validation.
- Auto-increment repair failure stops and requires manual review.

## 7. `classification_rules=8` policy

Treat `classification_rules` as migrated-schema seed/config data, not historical app data.

Policy:

- Do not truncate `classification_rules`.
- Do not duplicate rows.
- Do not upsert rows.
- Preserve target rows.
- Compare source and target by row count, safe stable identifiers, and safe content checksum over non-secret rule fields.
- If source count and target count are both `8` and identifiers/checksums match, continue and print count/checksum status only.
- If source/target checksums or identifiers differ, fail closed.
- If target has `0` while source has rows, block and require manual review or seed correction.
- If source has `0` while target has rows, block unless explicitly documented as expected.
- Never print rule contents if they could expose sensitive patterns.

Expected safe output shape:

```text
classification_rules_source_count=8
classification_rules_target_count=8
classification_rules_safe_identifier_match=yes
classification_rules_safe_checksum_match=yes
classification_rules_action=preserve_target_skip_import
```

## 8. Raw evidence path-handling policy

Raw CSV files are archived evidence only. The database remains authoritative.

Policy:

- Preserve original `raw_files.saved_path` exactly.
- Do not store rehearsal-resolved paths as authoritative data.
- Derive resolved rehearsal archive path only in validation logic.
- Do not persist `D:\inventory-rehearsal` resolved paths into production-style authoritative data.
- Basename-only matching is acceptable only when archive basenames are globally unique for the checked archive.
- Prefer suffix-based matching over pure basename when stored path shape supports it.
- If duplicate basename candidates exist, use suffix uniqueness. `raw_hash` must not be used to disambiguate unless its semantics are explicitly proven.
- If ambiguity remains, fail closed.
- If a copied archive file is missing after mapping, fail closed unless later manual evidence exception is approved.
- Treat `raw_hash` as semantically unverified by default. A 64-character value alone is not proof of file-content SHA-256.
- If `raw_hash` semantics are unverified and path mapping resolves completely, raw evidence mapping readiness is `PASS_WITH_WARN`, not `FAIL`.
- If `raw_hash` is explicitly proven/configured as file-content SHA-256, use it as a stronger validation signal:
  - candidate exists
  - candidate hash matches source `raw_hash`
  - output hash-checked and hash-mismatch counts only

Do not print raw filenames, full raw paths, raw CSV contents, or file lists.

Output counts only:

- `raw_files_count`
- `saved_path_populated_count`
- `archive_file_count`
- `basename_unique_count`
- `suffix_resolved_count`
- `ambiguous_count`
- `unresolved_count`
- `raw_hash_populated_count`
- `raw_hash_length_64_count`
- `raw_hash_semantics`
- `hash_validation_available`
- `hash_checked_count`
- `hash_mismatch_count`
- `hash_algorithm_detected`
- `raw_hash_semantic_warning`

Future production transfer principle:

- preserve original evidence references as historical data
- validate against the then-current archive root by derived mapping
- never make rehearsal-only paths authoritative

## 9. Optional missing table policy

Current absent optional tables are acceptable as WARN/skip only because both source and target are absent:

- `site_tokens`
- `department_cleanup_rules`
- `site_cleanup_rules`

Policy:

- If optional table is absent in both source and target, warn and skip.
- If present in source but absent in target, block; migration or mapping decision required.
- If absent in source but present in target with rows, block unless classified as seed/config and verified safe.
- If present in both, config/rule tables use compare-only or approved import policy.
- Token/auth tables require separate token policy.
- Do not create optional tables during execute transfer.
- Do not invent rows for missing optional tables.
- Do not print token hashes, token secrets, or sensitive values.

Classification:

- `site_tokens`: security/operational auth metadata; separate policy required if present.
- `department_cleanup_rules` and `site_cleanup_rules`: config/normalization rules; compare/import only after explicit policy.

## 10. Schema-difference policy

Known schema differences:

- `raw_files.device_scan_id` / `raw_files.device_id` missing
- `storage_health_observations.raw_json` missing
- skipped optional columns

Policy:

- Known optional missing columns may be skipped with WARN if the target column is absent or nullable/default-safe.
- Do not fabricate relationship values.
- Do not treat missing `raw_files.device_scan_id` / `raw_files.device_id` as a blocker if the schema truly lacks those columns and available historical keys are sufficient.
- Do not require `storage_health_observations.raw_json` if the column is absent.
- Validate available storage-health fields and risk counts instead.
- If target has a required non-null column missing from source with no safe default, block.
- If a relationship-critical column exists in target but cannot be populated safely, block.
- All skipped columns must be listed in count-only validation output.
- Unexpected schema differences block execute mode.

## 11. Transfer order and primary-key preservation

Recommended first implementation order:

1. `users`
2. `collector_sites`
3. `devices`
4. `device_identities`
5. `device_scans`
6. `hardware_snapshots`
7. `storage_health_observations`
8. `network_observations`
9. `peripherals`
10. `device_assignments`
11. `raw_files`
12. `change_log`
13. `collectors`
14. `runners`
15. `runner_commands`

Rules:

- `classification_rules` is not imported; it is compare-only and target-preserved.
- Optional token/config/cleanup tables are skipped if absent in both source and target.
- Primary keys must be preserved for every imported domain/application table.
- If any table cannot preserve IDs exactly, stop before writing.

## 12. Post-execute validation

Future post-execute validation is required before any restore rehearsal:

- row counts match for imported tables
- primary keys preserved
- foreign-key relationships valid
- `created_at` / `updated_at` preserved
- nullable fields preserved
- JSON/text fields decode without printing contents
- command lifecycle fields preserved:
  - queued
  - delivered/dispatched
  - acknowledged
  - completed
  - succeeded
  - failed
  - `acknowledged_at`
  - `completed_at`
  - `completion_status`
  - `result_upload_id`
- command payload exists/decodes without printing payload
- token metadata count only, no secrets/hashes printed
- raw evidence references preserved
- raw archive availability validated by counts
- assignment overrides preserved
- storage health observation counts and risk-level distribution preserved
- latest scan relationships resolve
- Direct HTTPS runner count preserved
- collector-share runner count preserved
- collector count preserved
- pending/delivered/failed/succeeded command counts preserved
- `inventory_rehearsal_restore` remains empty
- no package/site-kit generation
- no runner/collector traffic change
- no web endpoint exposure

## 13. Risks and controls

| Risk | Control |
| --- | --- |
| Accidental live DB/source use | Exact path, DB, and `APP_URL` gates |
| Destructive reset mistake | No reset/truncate in first execute implementation |
| Dirty target rows | Fail closed |
| `classification_rules` duplication/drift | Compare-only, preserve target, block mismatch |
| Raw evidence path mismatch | Preserve original path, derive validation mapping only |
| Duplicate archive basenames | Require suffix/hash uniqueness, block ambiguity |
| Token/security table mishandling | Skip absent, block present until separate policy |
| Schema drift | Known optional differences warn; unexpected/required differences block |
| Mid-run partial writes | Transaction where possible, dump required, no retry over dirty target |
| False cutover confidence | Rehearsal only; restore/cutover separately approved |

## 14. Acceptance criteria before any Codex implementation prompt

Codex must not implement execute mode until all of these are reviewed and approved:

- target empty-state/destructive-write policy
- no-reset first execute policy
- allowed and forbidden tables
- `classification_rules` compare-only policy
- raw evidence path validation policy
- optional table policy
- schema-difference policy
- pre-execute gates
- dump-before-execute requirement
- transfer order
- primary-key preservation rule
- auto-increment repair rule
- post-execute validation rules
- out-of-scope boundaries
- test requirements for guardrails, classification comparison, raw evidence mapping, optional/schema differences, and no-live-path protections

## 15. Final recommendation

Proceed with Phase 19K documentation-only design.

Do not implement `--execute` yet.

After Phase 19K is reviewed and accepted, the safest implementation path is:

1. Phase 19L: read-only execute-readiness hardening/diagnostics/tests only; no writes.
2. Phase 19M: execute import into empty `inventory_rehearsal` target only, dump marker required, no reset/truncate behavior.
3. Phase 19N: post-execute validation evidence review.
4. Later restore phase: separately approved restore into `inventory_rehearsal_restore`.

Production migration and cutover remain unapproved.

## 16. Phase 19L read-only readiness diagnostics

Phase 19L adds read-only execute-readiness diagnostics to the existing dry-run command:

```powershell
php artisan inventory:mariadb-rehearsal-transfer --source="D:\inventory-rehearsal\source-copy\database.sqlite" --dry-run --readiness --dump-marker="D:\inventory-rehearsal\backups\mariadb_dumps\<dump-file-or-marker>"
```

Readiness mode remains non-execute:

- `--readiness` requires `--dry-run`.
- `--source` remains explicit and required.
- `--dump-marker` is validated read-only and must point under `D:\inventory-rehearsal\backups\mariadb_dumps`.
- `--execute` remains absent.
- The command does not transfer/import rows.
- The command does not reset/truncate tables.
- The command does not create MariaDB dumps or restore MariaDB dumps.
- The command does not write to `inventory_rehearsal_restore`.
- The command does not expose a web endpoint, generate packages/site kits, change live config, read live SQLite, mutate live SQLite, or change runner/collector traffic.

Readiness diagnostics preview Phase 19K gates only:

- empty target state with allowed `migrations` rows and compare-only `classification_rules`
- dump marker existence, location, non-zero size, and timestamp plausibility
- compare-only `classification_rules` count/identifier/checksum status
- count-only raw evidence path mapping diagnostics
- optional table and known schema-difference policy checks

Phase 19L still does not approve execute import, restore rehearsal, production migration, or cutover.
