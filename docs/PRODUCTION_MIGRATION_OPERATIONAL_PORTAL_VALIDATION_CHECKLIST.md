# Phase 19T - Production Migration Operational and Portal Validation Checklist

## 1. Purpose

Phase 19T documents the future operational, portal, command lifecycle, token metadata, and raw evidence validation checklist required before any later production migration or cutover decision.

This is documentation-only. It does not run validation, execute commands, access live SQLite, approve production migration, or approve cutover.

## 2. Non-goals

Phase 19T does not approve or perform:

- Production migration.
- Production cutover.
- Backup execution.
- Copied-source creation.
- Live SQLite read or mutation.
- Live DB driver switching.
- Live `.env` changes.
- SQL execution.
- Dump or restore execution.
- Laravel migrations.
- Package or site-kit generation.
- Runner or collector repointing.
- HPE StoreEasy or DNS changes.
- `php artisan serve`.
- Web endpoint changes.
- Command queue mutation.
- Token reveal, rotation, or regeneration.
- Direct `repair_update`.
- Actual portal validation execution.
- Actual operational command execution.

## 3. Relationship to Phase 19Q and Phase 19R

Phase 19Q documented the production migration readiness gates.

Phase 19R documented the first prerequisite block: maintenance window, write freeze, fresh backup scope, and copied-source policy.

Phase 19T documents the future validation checklist only. It does not approve backup execution, copied-source creation, production migration, or cutover.

Phase 19U follow-up: `docs/PRODUCTION_MIGRATION_BACKUP_AND_COPIED_SOURCE_RUNBOOK.md` references Phase 19T validation as future bracketing evidence only. Phase 19U does not execute operational or portal validation.

Phase 19V follow-up: `docs/PRODUCTION_MIGRATION_BACKUP_COPY_EXECUTION_APPROVAL_CHECKLIST.md` records that Phase 19T validation remains future just-in-time evidence and must not be reused if stale.

## 4. Future Operational Command Checklist

These are future read-only validation candidates only. Do not run them in Phase 19T.

Future candidate commands:

```powershell
php artisan inventory:doctor
php artisan inventory:doctor --production
php artisan inventory:production-readiness
php artisan inventory:install-preflight
php artisan inventory:direct-pilot-status
php artisan inventory:direct-site-kit-audit
php artisan inventory:direct-runner-triage IT-ADMIN
php artisan inventory:direct-runner-triage LAPTOP-I76TA97E
php artisan migrate:status
php artisan --version
```

### Baseline Laravel Health

Commands:

- `php artisan inventory:doctor`
- `php artisan inventory:doctor --production`
- `php artisan migrate:status`
- `php artisan --version`

Purpose:

- Confirm Laravel boots.
- Confirm environment and storage readiness.
- Confirm migration status without running migrations.
- Confirm Laravel version context.

Expected safe evidence shape:

- `PASS`, `WARN`, or `FAIL` labels.
- Count-only summaries.
- Migration status summaries without secrets or row values.
- Version string.

Redaction requirements:

- Do not include `.env` values, `APP_KEY`, DB credentials, full configs, SQL bindings, or row values.

Stop conditions:

- The command would require a live `.env` change.
- The command would switch DB driver.
- The command would run SQL that mutates DB state.
- The command would expose secrets or raw values.

### Production Readiness and Installer Preflight

Commands:

- `php artisan inventory:production-readiness`
- `php artisan inventory:install-preflight`

Purpose:

- Summarize production/cutover readiness risks.
- Confirm required operational commands and safety boundaries are visible.
- Confirm package/site-kit generation safety is still bounded to approved hosts without generating artifacts.

Expected safe evidence shape:

- `PASS`, `WARN`, or `FAIL` result labels.
- Section names and count-only summaries.
- Known warnings with disposition.

Redaction requirements:

- Do not include token values, token hashes, bearer tokens, `.env` values, `APP_KEY`, DB credentials, Google credentials, full configs, or full runner GUIDs.

Stop conditions:

- The command would generate backups, packages, site kits, or tokens.
- The command would mutate DB records.
- The command output exposes secrets or raw values.
- A passing result is treated as cutover approval.

### Direct HTTPS Operational Status

Commands:

- `php artisan inventory:direct-pilot-status`
- `php artisan inventory:direct-site-kit-audit`
- `php artisan inventory:direct-runner-triage IT-ADMIN`
- `php artisan inventory:direct-runner-triage LAPTOP-I76TA97E`

Purpose:

- Confirm Direct HTTPS pilot status from Laravel database state.
- Confirm Direct HTTPS package/site-kit safety wording without generating artifacts.
- Confirm individual Direct HTTPS runner health using existing redacted triage output.

Expected safe evidence shape:

- `PASS`, `WARN`, `FAIL`, or `ATTENTION` labels.
- Runner counts.
- Masked identifiers where already supported by existing commands.
- Safe timestamps.
- Direct `repair_update` blocked status.

Redaction requirements:

- Do not include token material, bearer tokens, token hashes, command payload JSON, raw CSV contents, full configs, full runner GUIDs, raw filenames, or full raw paths.

Stop conditions:

- Direct `repair_update` appears enabled or implied.
- Collector-share mode appears affected by Direct HTTPS checks.
- A command would trigger runner activity or mutate command state.
- Evidence exposes secrets or raw values.

## 5. Future Portal Page Checklist

Future portal validation candidates only:

- Login.
- Dashboard.
- Devices list.
- At least 3 device detail pages.
- Runners page.
- One Direct HTTPS runner detail page.
- One collector-share runner detail page.
- Collectors page.
- Command queue.
- Downloads.
- Storage health.
- Raw evidence/raw files page.
- Setup wizard.
- Reports pages if available.

Portal validation evidence should record safe page/route names, high-level pass/warn/fail notes, and count-only observations where needed.

Screenshots or notes must not expose:

- Secrets.
- Token values.
- Raw filenames.
- Raw paths.
- Raw evidence file lists.
- Full runner GUIDs.
- Command payload JSON.
- Row values.
- `.env` values.
- DB credentials.

Portal validation must not create commands, change assignments, rotate tokens, reveal tokens, generate packages, download secret-bearing artifacts, or alter authoritative DB state.

## 6. Command Lifecycle Validation Checklist

Future checks:

- Queued count.
- Delivered/dispatched count.
- Awaiting ACK count.
- Acknowledged count.
- Completed count.
- Succeeded count.
- Failed count.
- Stale delivered/unacked count.
- `result_upload_id` populated count.
- Payload JSON decodability count without printing payload.

Command semantics:

- Polling means delivery.
- ACK is execution result.
- Validation must not mutate command state.
- Validation must not manually reclassify commands.
- Direct `repair_update` remains blocked.

Allowed evidence:

- Count-only summaries.
- `PASS`, `WARN`, `FAIL`, or `ATTENTION` labels.
- Safe timestamps where needed.

Forbidden evidence:

- Command payload JSON.
- SQL bindings.
- Row values.
- Runner full GUIDs.
- Token material.

## 7. Token Metadata Validation Checklist

Future token metadata validation must be metadata-only and secret-free.

Future checks:

- Token metadata count.
- Token type distribution where safe.
- `direct_runner` metadata presence.
- Collector token metadata presence.
- No token secrets.
- No token hashes.
- No bearer tokens.
- No token reveal.
- No token rotation or regeneration in this phase.

Allowed evidence:

- Count-only summaries.
- Safe token type labels.
- Presence yes/no for expected token classes.

Stop if token values, hashes, bearer values, reveal output, rotation output, or regeneration output would be required.

## 8. Raw Evidence Validation Checklist

Future validation expectations:

- `raw_files` count.
- `saved_path` populated count.
- Raw evidence mapping readiness.
- `suffix_resolved_count`.
- `ambiguous_count`.
- `unresolved_count`.
- `raw_hash_semantics` remains `file_content_hash_unverified` unless later proven.
- Raw evidence validation remains `PASS_WITH_WARN` only if mapping is complete and unambiguous.
- No raw filenames, full paths, file lists, or CSV contents are printed.

The accepted warning remains:

```text
raw_hash_semantics=file_content_hash_unverified
```

Raw evidence is archived evidence only. The database remains authoritative.

## 9. Direct HTTPS Validation Expectations

Future checks:

- Direct HTTPS runner count.
- Recent heartbeat expectations.
- Recent direct poll expectations.
- Recent direct upload / last inventory expectations.
- Runner version visibility.
- Direct HTTPS badge/transport visible.
- Manual scan/`scan_now` support wording remains asynchronous.
- Direct `repair_update` remains blocked.
- `direct_runner` token separation is preserved.
- Collector-share is not affected.

Direct HTTPS validation must not trigger commands, reinstall runners, regenerate packages, change endpoint configuration, reveal tokens, or repoint runner traffic.

## 10. Collector-share Validation Expectations

Future checks:

- Collector-share runner count.
- Collector count.
- Collector status visible.
- Collector-share runners are not treated as missing Direct HTTPS poll.
- Command and ACK wording remains asynchronous.
- Collector token separation is preserved.
- Branch-share mode remains supported.

Collector-share validation must not change collector configs, branch-share paths, scheduled tasks, tokens, command files, ACK files, runner endpoints, or Laravel endpoint configuration.

## 11. Redacted Evidence Shape

Allowed evidence:

- Count-only summaries.
- `PASS`, `WARN`, `FAIL`, or `ATTENTION` result labels.
- Timestamps where safe.
- Masked identifiers where already supported by existing commands.
- Safe page/route names.
- Safe screenshots only if reviewed for secret/raw-value exposure.

Forbidden evidence:

- `.env` values.
- `APP_KEY`.
- DB credentials.
- Token secrets.
- Token hashes.
- Bearer tokens.
- Google credentials.
- Raw CSV contents.
- Command payload JSON.
- Full configs.
- Full runner GUIDs.
- Raw filenames.
- Full raw paths.
- Raw evidence file lists.
- Row values.
- SQL bindings.
- Dump contents.

## 12. Stale-evidence Rule

Operational and portal validation evidence is time-sensitive.

This checklist must be run near the actual migration or cutover decision point in a later approved phase. Old validation evidence must not be treated as current production readiness.

Successful validation is not cutover approval by itself. It is only one input to a later explicit production migration or cutover decision.

## 13. Stop Conditions

Stop if:

- Validation would require live `.env` changes.
- Validation would read or mutate live SQLite directly.
- Validation would switch DB driver.
- Validation would run SQL or mutate DB state.
- Validation would trigger commands or alter command queue state.
- Validation would expose secrets or raw values.
- Validation would repoint runners or collectors.
- Validation would generate packages or site kits.
- Validation would enable or imply Direct `repair_update`.
- Validation evidence is stale or ambiguous.
- Portal screenshots expose sensitive values.

Stop means preserve only safe non-secret evidence, clarify the boundary, and require separate approval before proceeding.

## 14. Acceptance Criteria

Phase 19T is accepted only when:

- The validation checklist document exists.
- Approved future operational command checklist is documented.
- Approved future portal page checklist is documented.
- Command lifecycle validation checklist is documented.
- Token metadata validation checklist without secrets is documented.
- Raw evidence validation checklist is documented.
- Direct HTTPS validation expectations are documented.
- Collector-share validation expectations are documented.
- Redacted evidence shape is documented.
- Stale-evidence rule is documented.
- Stop conditions are documented.
- Production migration remains unapproved.
- Cutover remains unapproved.
- Backup execution remains unapproved.
- Copied-source creation remains unapproved.
- Live DB switch remains unapproved.
- Live `.env` change remains unapproved.
- Live SQLite read/mutation remains unapproved.
- Runner/collector repointing remains unapproved.
- Package/site-kit generation remains unapproved.
- Direct `repair_update` remains blocked.
- No commands are run.
- No code/config/data/generated artifacts are changed.
- No secrets or raw values are documented.
