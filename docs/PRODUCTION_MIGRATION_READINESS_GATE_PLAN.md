# Phase 19Q - Production Migration Readiness Gate Plan

## 1. Purpose

Phase 19Q documents the remaining production migration readiness gates after the accepted Phase 19P restore rehearsal evidence review.

This is a planning and gate document only. It does not approve production migration, production cutover, live database switching, live configuration changes, or operational execution.

## 2. Non-goals

Phase 19Q does not approve or perform:

- Production migration.
- Production cutover.
- Live DB driver switching.
- Live `.env` changes.
- Live SQLite read or mutation.
- App data transfer.
- MariaDB restore into production or any live DB.
- Web endpoint exposure for rehearsal or restore.
- `php artisan serve`.
- HPE StoreEasy or DNS changes.
- Runner or collector repointing.
- Package or site-kit generation.
- Direct `repair_update`.
- New code or command implementation.
- Live operational validation execution.
- Portal validation execution.
- Backup execution.

## 3. Accepted Rehearsal State

Accepted state carried into Phase 19Q:

- Phase 19M.2 controlled execute import into `inventory_rehearsal` was accepted with `WARN`.
- Phase 19N post-execute evidence review was accepted as `PASS_WITH_WARN`.
- Phase 19O.1 controlled restore rehearsal was accepted as `PASS_WITH_WARN`.
- Phase 19P documented the restore rehearsal evidence review.
- Restore count validation passed and matched the accepted Phase 19N/19O baseline.
- Relationship validation passed with orphan count `0`.
- The accepted warning `raw_hash_semantics=file_content_hash_unverified` remains carried forward.
- Raw evidence validation remains `PASS_WITH_WARN` only because mapping is complete and unambiguous.

## 4. Remaining Production-migration Readiness Gates

A later production migration phase remains blocked until these gates are completed, reviewed, and separately approved in order:

1. Maintenance window and write freeze plan.
2. Fresh verified live backups.
3. Explicit copied source policy.
4. Accepted restore rehearsal evidence.
5. Current operational command validation.
6. Portal validation evidence.
7. Command lifecycle validation.
8. Token metadata validation without secrets.
9. Raw evidence validation.
10. Rollback decision point.
11. Explicit cutover approval.

These gates do not approve production migration or cutover by themselves.

## 5. Future Phase Guidance

Later phases should remain separately reviewed and approved. Suggested sequence:

- Phase 19R: first prerequisite block policy only: maintenance window, write freeze, fresh backup, and copied-source policy.
- Phase 19T: operational and portal validation checklist.
- Later separately approved production migration execution phase.
- Later separately approved cutover decision phase.

This sequence is guidance only. It does not authorize implementation, execution, migration, or cutover.

Phase 19R follow-up: `docs/PRODUCTION_MIGRATION_BACKUP_AND_SOURCE_POLICY.md` documents only the first prerequisite block: maintenance window, write freeze, fresh verified live backup scope, and copied-source policy. It does not approve backup execution, copied-source creation, production migration, or cutover.

Phase 19T follow-up: `docs/PRODUCTION_MIGRATION_OPERATIONAL_PORTAL_VALIDATION_CHECKLIST.md` documents future operational, portal, command lifecycle, token metadata, Direct HTTPS, collector-share, and raw evidence validation requirements only. It does not execute validation, migration, backup, copied-source creation, or cutover.

## 6. Key Risks

- Scope creep into production migration.
- Accidental live mutation.
- False confidence from rehearsal success.
- Secret leakage.
- Stale evidence.
- Breaking collector-share or Direct HTTPS transport assumptions.
- Breaking asynchronous command semantics.
- Accidentally enabling Direct `repair_update`.

## 7. Stop Conditions

Stop Phase 19Q or any follow-on planning if:

- Any live `.env` would be changed.
- Live SQLite would be read or mutated.
- Production DB or live DB driver would be changed.
- Runner or collector traffic would be repointed.
- Package or site-kit generation is required.
- A command or validation step would expose secrets or raw values.
- Rehearsal evidence is treated as production cutover approval.
- Direct `repair_update` becomes enabled or implied.

## 8. Security and Redaction Rules

Never expose or document:

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

## 9. Acceptance Criteria

Phase 19Q is accepted only when:

- This readiness-gate document exists.
- Phase 19O.1 and Phase 19P are recorded as `PASS_WITH_WARN`.
- `raw_hash_semantics=file_content_hash_unverified` is carried forward.
- Production migration and cutover remain explicitly unapproved.
- Remaining gates are listed in order.
- Future phase guidance is documented without approval.
- Stop conditions and redaction rules are documented.
- No commands are run.
- No code, config, data, or generated artifacts are changed.
- No secrets or raw values are documented.
