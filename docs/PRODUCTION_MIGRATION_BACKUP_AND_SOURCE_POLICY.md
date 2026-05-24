# Phase 19R - Production Migration Backup and Source Policy

## 1. Purpose

Phase 19R documents the maintenance window, write-freeze, fresh live backup, and copied-source policy required before any future production migration phase.

This is planning only. It does not execute backups, create copied sources, access live SQLite, approve production migration, or approve cutover.

## 2. Non-goals

Phase 19R does not approve or perform:

- Production migration.
- Production cutover.
- Live DB driver switching.
- Live `.env` changes.
- Live SQLite read or mutation.
- Actual backup execution.
- Actual copied-source creation.
- SQL execution.
- Dump or restore execution.
- Laravel migrations.
- Web endpoint exposure.
- `php artisan serve`.
- HPE StoreEasy or DNS changes.
- Runner or collector repointing.
- Package or site-kit generation.
- Direct `repair_update`.
- Operational command execution.
- Portal validation execution.
- Command lifecycle mutation.
- Token rotation or token reveal.

## 3. Relationship to Phase 19Q

Phase 19Q documented the remaining production migration readiness gates after the accepted Phase 19P restore rehearsal evidence review.

Phase 19R narrows only the first prerequisite block:

- Maintenance window.
- Write freeze.
- Fresh verified live backups.
- Copied-source policy.

Phase 19R does not satisfy all production migration readiness gates. It does not approve backup execution, production migration, or cutover.

Phase 19T follow-up: `docs/PRODUCTION_MIGRATION_OPERATIONAL_PORTAL_VALIDATION_CHECKLIST.md` defines future validation evidence that should bracket later backup, source-copy, migration, and cutover decisions. Phase 19T does not approve backup execution or copied-source creation.

## 4. Maintenance Window Policy

A maintenance window is required before any later production migration execution because the authoritative database, raw evidence references, runner and collector intake, command lifecycle state, and generated-download references must remain stable while the final source copy and migration preparation occur.

Maintenance window approval must come from generic operational roles, not from this document alone:

- System owner.
- Operations approver.
- Application administrator.
- Business approver where downtime affects users.

Normal production writes must be frozen during any future final source copy and migration preparation. The maintenance window must cover the time needed to stop or control writes, create and verify backups, create and verify the copied source, make the rollback decision, and either continue only under a separately approved migration phase or stop cleanly.

A maintenance window plan is not cutover approval. Exact execution timing remains a future approval item and must not be inferred from Phase 19R.

## 5. Write-freeze Policy

Before any future approved production migration source copy, these write paths must be stopped or controlled:

- Laravel portal writes.
- Runner uploads.
- Collector uploads.
- Command queue creation and dispatch where relevant.
- Scheduled runner and collector traffic where relevant.
- Manual admin edits that mutate authoritative database state.

The write freeze is design-only in Phase 19R. This document intentionally provides no commands that execute service stops, config changes, queue changes, upload changes, task changes, or database mutations.

The freeze must preserve command semantics: polling means delivery, ACK is the execution result, and asynchronous command state must not be manually reclassified to force migration progress.

## 6. Fresh Live Backup Scope

A future approved production migration preparation must capture fresh, verified backups before any source copy or migration execution.

Required scope:

- SQLite database backup.
- Raw archive backup.
- Generated downloads and site kits backup.
- Relevant non-secret config metadata.
- Git revision.
- Token metadata through DB backup only, without exposing token values.
- Rollback reference notes without secrets.

The backup set must be verified for existence, expected size shape, timestamp freshness, and approved integrity evidence before any later migration phase uses it. Token values, hashes, bearer values, `.env` values, credentials, `APP_KEY`, and full configs must not be extracted into notes or evidence.

## 7. Copied-source Policy

Use only a verified copied source for migration or rehearsal transfer.

Rules:

- Never use live SQLite directly as a migration or transfer source.
- Copied source creation must occur only during a future separately approved phase.
- The copied source must live outside the live app path.
- The copied source must be integrity-checked before any future migration phase.
- The copied source should be read-only where practical.
- Copied raw archive and download evidence is supporting evidence only, not operational truth.
- The database remains authoritative.

The copied source must be traceable to the fresh backup/source-copy event without exposing secret-bearing paths, raw filenames, raw evidence file lists, row values, SQL bindings, dump contents, token material, or config values.

## 8. Verification Evidence Shape

Allowed safe evidence:

- File exists yes/no.
- Size.
- Timestamp.
- Safe checksum where approved.
- Count-only summaries.
- Source and target labels without secret-bearing full paths when avoidable.

Forbidden evidence:

- Row values.
- Raw CSV contents.
- Raw filenames.
- Full raw paths.
- Raw evidence file lists.
- SQL bindings.
- Dump contents.
- Command payload JSON.
- Token material.
- Full configs.
- Full runner GUIDs.
- `.env` values.
- `APP_KEY`.
- DB credentials.

Evidence should prefer labels such as `live_sqlite_backup`, `copied_source`, `raw_archive_backup`, and `downloads_backup` over full filesystem paths when full paths are not necessary for decision-making.

## 9. Rollback-source Expectations

Backups must support rollback decision-making before any future execution phase.

Rollback expectations:

- Rollback source must be verified before any future execution phase.
- Rollback decision point must occur before live DB switch or cutover.
- Restore testing evidence remains required.
- Backup readiness alone is not cutover approval.
- Rollback notes must describe the source set, verification status, and decision boundary without exposing secrets or raw values.

If rollback source verification is incomplete, stale, ambiguous, or secret-bearing evidence would be required to prove it, the future execution phase must stop.

## 10. Stop Conditions

Stop if:

- Live `.env` would be changed.
- Live SQLite would be read directly for migration or transfer.
- Live SQLite would be mutated.
- DB driver would be switched.
- Production DB would be created or modified.
- Runner or collector traffic would be repointed.
- Package or site-kit generation would occur.
- Direct `repair_update` would become enabled or implied.
- Evidence collection would expose secrets or raw values.
- Backup policy is treated as migration or cutover approval.
- Copied-source policy is ambiguous.

Stop means pause the phase, preserve only safe non-secret evidence, clarify scope, and require separate approval before proceeding.

## 11. Security and Redaction Rules

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

Counts, yes/no checks, timestamps, sizes, approved checksums, and redacted labels are the preferred evidence forms.

## 12. Acceptance Criteria

Phase 19R is accepted only when:

- The new backup/source policy document exists.
- Maintenance window purpose and approval boundary are documented.
- Write-freeze policy is documented.
- Fresh live backup scope is documented.
- Copied-source policy is documented.
- Verification evidence shape is documented.
- Rollback-source expectations are documented.
- Stop conditions and redaction rules are documented.
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
