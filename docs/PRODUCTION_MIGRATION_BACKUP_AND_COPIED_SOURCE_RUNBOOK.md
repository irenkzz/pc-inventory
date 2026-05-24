# Phase 19U - Production Migration Backup and Copied-source Runbook Design

## 1. Purpose

Phase 19U designs a future fresh live backup and copied-source creation runbook for the Internal Windows PC Inventory System.

Phase 19U is design-only. This document is not approval to run the runbook, execute backups, create copied sources, read live SQLite, approve production migration, or approve cutover.

## 2. Non-goals

Phase 19U does not approve or perform:

- Production migration.
- Production cutover.
- Actual backup execution.
- Actual copied-source creation.
- Live SQLite read or mutation.
- Live DB driver switching.
- Live `.env` changes.
- SQL execution.
- Dump or restore execution.
- Laravel migrations.
- Operational validation execution.
- Portal validation execution.
- Command lifecycle mutation.
- Token reveal, rotation, or regeneration.
- Runner or collector repointing.
- Package or site-kit generation.
- HPE StoreEasy or DNS changes.
- `php artisan serve`.
- Direct `repair_update`.
- Any secret-bearing or raw-value evidence.

## 3. Relationship to Phase 19Q, 19R, and 19T

Phase 19Q defines the production migration readiness gate model.

Phase 19R defines the maintenance window, write-freeze, fresh backup scope, and copied-source policy.

Phase 19T defines future operational, portal, command lifecycle, token metadata, Direct HTTPS, collector-share, and raw evidence validation requirements.

Phase 19U designs the future backup and copied-source execution runbook only.

These gates do not approve production migration or cutover by themselves.

## 4. Future Execution Preconditions

A later execution phase must require:

- Explicit approval for backup/copy-source execution.
- Confirmed maintenance window.
- Confirmed write-freeze plan.
- Confirmed operator roles.
- Confirmed live app path and source-of-truth path boundaries.
- Confirmed destination paths for backup/copy-source artifacts.
- Confirmed redaction rules.
- Confirmed rollback-source expectations.
- Confirmed Phase 19T validation timing.
- Confirmed stop conditions.
- Clean Git/source state where applicable.
- No pending ambiguity about whether execution is approved.

If any precondition is missing, stale, disputed, or requires secret-bearing evidence to prove, the future execution phase must stop before touching live sources.

## 5. Required Approvals Before Future Backup/copy Execution

Approval checkpoints must use generic role names only:

- System owner approval.
- Operations approval.
- Application administrator approval.
- Business/downtime approval where relevant.
- Explicit phase approval for backup/copy-source execution.

Approval for backup/copy-source execution is not production migration approval and not cutover approval.

## 6. Maintenance Window and Write-freeze Confirmation Sequence

This is a design sequence only. It intentionally provides no executable commands that stop services, change schedules, alter configs, repoint traffic, or mutate live systems.

Future sequence:

1. Announce maintenance window.
2. Confirm no active portal write activity.
3. Confirm runner/collector upload handling plan.
4. Confirm command queue creation/dispatch handling plan.
5. Confirm no manual admin edits during source copy.
6. Confirm writes remain frozen during future backup/copy-source work.
7. Confirm command semantics are preserved:
   - Polling means delivery.
   - ACK is execution result.

The write-freeze boundary must cover Laravel portal writes, runner uploads, collector uploads, command queue creation/dispatch where relevant, scheduled runner/collector traffic where relevant, and manual admin edits that mutate authoritative database state.

## 7. Fresh Backup Scope

The future runbook must use the Phase 19R backup scope:

- SQLite database backup.
- Raw archive backup.
- Generated downloads/site kits backup.
- Relevant non-secret config metadata.
- Git revision.
- Token metadata through DB backup only, without exposing token values.
- Rollback reference notes without secrets.

Phase 19U must not include actual backup artifacts or command output.

## 8. Copied-source Creation Boundary

Future copied-source rules:

- Copied source must be created only in a later approved execution phase.
- Never use live SQLite directly as migration/transfer source.
- Copied source must live outside the live app path.
- Copied source must be integrity-checked before use.
- Copied source should be read-only where practical.
- Copied raw archive/download evidence is supporting evidence only.
- Database remains authoritative.
- Source labels should avoid secret-bearing full paths when possible.

The copied-source destination must be explicit before execution starts. Ambiguous destination, live-app-path destination, or secret-bearing path evidence is a stop condition.

## 9. Safe Backup/copy Evidence Shape

Allowed future evidence:

- Exists yes/no.
- Size.
- Timestamp.
- Safe checksum where approved.
- Count-only summaries.
- Source/destination labels without secret-bearing full paths when avoidable.
- Git commit/revision.
- `PASS`, `WARN`, or `FAIL` labels.

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
- Generated package/site-kit contents.

## 10. Integrity Verification Rules

Future verification expectations:

- Verify backup/source-copy existence.
- Verify expected size shape.
- Verify timestamp freshness.
- Verify safe checksum where approved.
- Verify copied SQLite integrity without printing rows or values.
- Verify raw archive/download backup count shape without file lists.
- Verify rollback source readiness.
- Fail closed on missing, stale, ambiguous, or secret-bearing evidence.

Integrity verification must prove enough to support a go/no-go decision without exposing raw evidence values, raw filenames, row values, SQL bindings, dump contents, token material, or secret-bearing configs.

## 11. Rollback-source Verification Rules

Rollback-source expectations:

- Rollback source must be verified before any later execution phase proceeds.
- Rollback decision point must occur before live DB switch/cutover.
- Backup readiness alone is not cutover approval.
- Restore testing evidence remains required.
- Rollback notes must not expose secrets or raw values.

If rollback source verification is incomplete, stale, ambiguous, or dependent on prohibited evidence, the future execution phase must stop.

## 12. Phase 19T Validation Bracketing

Phase 19T validation should bracket a future execution window:

- Pre-window operational/portal validation should be run near the actual future execution/cutover decision point.
- Validation evidence becomes stale if run too early.
- Post-copy validation timing should be defined by a later approved phase.
- Successful validation is not cutover approval by itself.
- No Phase 19T validation is executed in Phase 19U.

The future runbook should treat Phase 19T evidence as time-sensitive decision support, not as standing approval.

## 13. Stop Conditions

Stop if:

- Anyone treats this runbook design as execution approval.
- Live `.env` would be changed.
- Live SQLite would be read directly for migration/transfer.
- Live SQLite would be mutated.
- DB driver would be switched.
- SQL would be executed.
- Dump/restore would be executed.
- Operational or portal validation would be run now.
- Runner/collector traffic would be repointed.
- Packages/site kits would be generated.
- HPE StoreEasy or DNS would change.
- Direct `repair_update` would become enabled or implied.
- Evidence would expose secrets, raw values, row values, raw filenames, full paths, file lists, SQL bindings, or dump contents.
- Write-freeze boundary is ambiguous.
- Rollback source is ambiguous.
- Copied-source destination is ambiguous.
- Backup readiness is treated as migration/cutover approval.

Stop means pause, preserve only safe non-secret evidence, clarify scope, and require separate approval before any execution.

## 14. Security and Redaction Rules

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

Use count-only summaries, yes/no checks, timestamps, sizes, approved checksums, safe labels, Git revision, and `PASS`/`WARN`/`FAIL` labels instead.

## 15. Acceptance Criteria

Phase 19U is accepted only when:

- The new runbook design document exists.
- Future execution preconditions are documented.
- Required approvals are documented.
- Maintenance window and write-freeze confirmation sequence is documented.
- Fresh backup scope is documented.
- Copied-source creation boundary is documented.
- Safe backup/copy evidence shape is documented.
- Integrity verification rules are documented.
- Rollback-source verification rules are documented.
- Phase 19T validation bracketing is documented.
- Stop conditions are documented.
- Redaction rules are documented.
- Production migration remains unapproved.
- Production cutover remains unapproved.
- Backup execution remains unapproved.
- Copied-source creation remains unapproved.
- Live DB switch remains unapproved.
- Live `.env` change remains unapproved.
- Live SQLite read/mutation remains unapproved.
- SQL execution remains unapproved.
- Dump/restore execution remains unapproved.
- Operational validation execution remains unapproved.
- Portal validation execution remains unapproved.
- Runner/collector repointing remains unapproved.
- Package/site-kit generation remains unapproved.
- Direct `repair_update` remains blocked.
- No commands are run.
- No code/config/data/generated artifacts are changed.
- No secrets or raw values are documented.
