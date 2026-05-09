# Phase 18A - Installation Productization Strategy

## 1. Purpose

Phase 18A is documentation and planning only. It explains how installation should be simplified and productized so another company, school, hospital, office, or institution can reuse the Internal Windows PC Inventory System without needing to understand every internal component first.

This is not an installer implementation. It does not add a portal setup wizard, server installer, runner installer changes, collector installer changes, token rotation UI, per-runner enrollment, Direct `repair_update`, or any application behavior.

This document also does not approve production cutover. Phase 17B remains the current production deployment decision record, and any production migration, web-server change, backup rehearsal, or cutover must be approved in a separate phase.

## 2. Current Installation Pain

The current installation flow works for the original environment because the implementer already understands the system shape. It is too technical for reuse by another organization because installation requires many separate decisions and commands across multiple components:

- Backend setup requires Laravel, PHP, Composer, storage permissions, scheduler behavior, app health, environment configuration, and operational commands.
- Portal/frontend setup is tied to Laravel app readiness, authentication setup, first user setup, URL configuration, and production web-server readiness.
- Database setup requires choosing between pilot SQLite and production MariaDB/MySQL, preparing credentials, running migrations only at the right time, and preserving backup/restore discipline.
- HTTPS/proxy setup requires DNS, certificates, trusted proxy behavior, HPE StoreEasy or equivalent reverse proxy configuration, `APP_URL`, TLS validation, and certificate trust on clients.
- Runner installation requires package selection, mode-specific configuration, Scheduled Task installation, local state handling, GUID preservation, TLS checks, and support diagnostics.
- Collector installation requires branch-share folder structure, collector identity, collector token configuration, scheduled execution, share read/write checks, relay verification, and runner package staging.
- Site kit and package generation must happen from the approved host. In the current workflow, official packages and site kits are generated only on Supermicro.
- Token handling is easy to mishandle because site tokens, collector tokens, Direct runner tokens, bearer tokens, hashes, and config files must not be exposed in logs or support chats.
- Direct HTTPS versus collector-share mode choice is a real deployment decision, not a cosmetic option. The user must understand the operational difference without needing to learn internal API details.
- Operational checks are currently CLI-heavy and require knowing which command answers which question.
- Backup and restore are not optional production details, but they can be skipped if installation feels like a simple app setup.
- Manual commands are practical for administrators and recovery, but they are too fragile as the normal installation path for a new institution.

## 3. Productization Principles

- The user should choose real-world intent, not technical jargon. For example: "small site with no local IT server" or "branch office with many PCs and a shared folder" is clearer than asking the user to choose internal transport terms first.
- Internal complexity should be hidden behind guided packages and verification steps.
- Direct HTTPS and collector-share boundaries must stay clear. Direct HTTPS is a second transport, not a replacement for collector-share mode.
- The database remains authoritative.
- CSV files remain archived evidence only, not operational truth.
- Google Drive remains backup/sync only, not the live database or command channel.
- Secrets must never be exposed in installer output, support summaries, logs, portal screens, or generated documentation.
- The installer and wizard must avoid false production confidence. A successful setup check is not the same as production cutover approval.
- CLI diagnostics and recovery commands should remain available for administrators, but normal first-time setup should become wizard-guided.

## 4. Target User-Facing Installation Flow

The target productized installation flow should be:

1. Install central server.
2. Open portal setup wizard.
3. Create first admin, company, and site.
4. Choose deployment mode.
5. Download generated installer or package.
6. Install runner or collector.
7. Verify from the portal.

The flow should present decisions in operational terms and then generate the correct technical artifacts behind the scenes. The user should not need to manually map Laravel, runner, collector, tokens, transport mode, and verification commands before the first successful inventory post.

## 5. Installation Modes

### Small/no-IT Direct HTTPS mode

Direct HTTPS mode is for small sites, remote offices, or institutions without a local IT server or reliable branch-share operation. Each runner talks outward to the Laravel HTTPS endpoint, uploads inventory, polls for commands, ACKs execution results, and retries as needed.

Rules:

- Use Direct HTTPS mode for small/no-IT sites.
- Use the `direct_runner` token type, not collector tokens.
- Keep Direct HTTPS API contracts unchanged.
- Keep commands asynchronous: polling means delivery, and ACK is the execution result.
- Direct `repair_update` remains blocked for the Direct HTTPS MVP.

### HQ/multi-PC collector-share mode

Collector-share mode is for headquarters, labs, branch offices, or multi-PC environments where a local share and collector are practical. Runners write to the local or SMB branch share, and the collector relays heartbeat, CSV, ACK, and command data to Laravel.

Rules:

- Use collector-share mode for HQ and multi-PC branches.
- Preserve the `runner -> local/SMB branch share -> collector -> Laravel` transport.
- Keep collector tokens separate from Direct runner tokens.
- Do not introduce Direct HTTPS assumptions into collector-share runner packages.

### Hybrid mode

Hybrid mode allows one organization to use both Direct HTTPS and collector-share modes across different sites. For example, headquarters may use collector-share mode while a small remote office uses Direct HTTPS mode.

Rules:

- Hybrid mode allows both modes in one organization.
- Hybrid does not mean mixing Direct HTTPS and collector-share inside one runner installation.
- Each site package and runner package remains mode-specific.
- Portal wording should make clear which sites use which transport.

## 6. Future Inventory Server Installer Responsibilities

A future Inventory Server Installer should guide the central Laravel server setup and produce a safe readiness report. Eventual responsibilities include:

- Check Windows/server prerequisites.
- Check PHP, Composer, and runtime prerequisites.
- Check database availability.
- Create or check required folders.
- Check storage permissions.
- Check Laravel app health.
- Check `APP_URL` and HTTPS readiness.
- Check backup path readiness.
- Check operational command availability.
- Check whether site kit and package generation is allowed on this host.
- Prepare first-run portal setup state.
- Open the portal setup URL.
- Run a safe readiness/preflight report.

It must not silently:

- Auto-migrate production data.
- Overwrite `.env`.
- Generate real tokens without user confirmation.
- Disable TLS validation.
- Claim production approval.

The server installer can make installation easier, but it must preserve the difference between "the server is reachable" and "production cutover has been approved."

## 7. Future Portal Setup Wizard Responsibilities

A future Portal Setup Wizard should handle the first-use configuration that is currently too manual. Eventual responsibilities include:

- Create the first admin user.
- Create the company or organization profile.
- Create the first site.
- Choose deployment mode.
- Explain Direct HTTPS versus collector-share in plain language.
- Collect display names for site, collector, and runner package.
- Generate or prepare the site token flow.
- Generate package download.
- Show a verification checklist.
- Show what to do next.
- Show safe health/readiness status.

The wizard should describe operational choices without leaking token values or requiring the operator to understand every Laravel, runner, collector, and reverse proxy detail.

Phase 18C added the Portal Setup Wizard MVP at `GET /setup-wizard`. Status: implemented, validated on Supermicro, documented.

The MVP is an authenticated admin portal page and remains read-only. It shows the seven-step productized setup flow, safe `APP_URL` / HTTPS labels, existing site/runner/collector counts only, Direct HTTPS / collector-share / hybrid deployment-mode guidance, links to existing runners, collectors, command queue, and downloads pages, verification checklists, and a secret-redaction safety footer. It references `inventory:install-preflight` and `inventory:direct-site-kit-audit` without running them.

Phase 18C does not create users, sites, tokens, token rotation, per-runner enrollment, packages, site kits, migrations, `.env` changes, production approval, or production data mutations. It does not touch runner/collector files, change Direct HTTPS API contracts, change command lifecycle semantics, or enable Direct `repair_update`.

## 8. Future Direct HTTPS Runner Installer Responsibilities

A future Direct HTTPS Runner Installer should install a mode-specific runner package and verify that the client can safely talk to the Laravel HTTPS endpoint. Eventual responsibilities include:

- Import generated runner configuration.
- Validate `transport_mode=direct_https`.
- Validate HTTPS `serverBaseUrl`.
- Check that Windows trusts the certificate.
- Install runner files under `ProgramData`.
- Register or update the Scheduled Task.
- Preserve or create runner GUID.
- Run local preflight.
- Trigger or wait for the first normal runner cycle.
- Show portal verification instructions.
- Produce a redacted support summary.

It must not:

- Enable Direct `repair_update`.
- Change Direct HTTPS API contracts.
- Expose tokens.
- Print full configs.
- Disable TLS validation.
- Change asynchronous command semantics.

## 9. Future Collector-site Installer Responsibilities

A future Collector-site Installer should prepare a branch collector installation and mode-specific runner staging area. Eventual responsibilities include:

- Create or validate branch-share folder structure.
- Install collector files.
- Configure collector site identity.
- Install collector scheduled task or service wrapper.
- Stage runner package for branch PCs.
- Validate Laravel HTTPS endpoint.
- Validate collector token presence without printing it.
- Validate share read/write access.
- Validate first collector status post.
- Validate runner heartbeat relay path.
- Show portal verification checklist.
- Produce a redacted support summary.

Collector-share behavior must be preserved. The collector-site installer must not introduce Direct HTTPS assumptions into collector-share mode.

## 10. Built-in Preflight/Readiness Checks

Future installer and wizard checks may reuse or align with existing operational commands:

```powershell
php artisan inventory:doctor
php artisan inventory:install-preflight
php artisan inventory:production-readiness
php artisan inventory:direct-site-kit-audit
php artisan inventory:direct-runner-triage {runnerId}
```

The productized setup should present these checks in portal or installer language while keeping the CLI available for diagnostics and recovery.

Phase 18B added `php artisan inventory:install-preflight` as the first server-side preflight for this productization path. It is a Laravel-only read-only Artisan command that checks Environment, Laravel Host, Application URL / HTTPS, Storage and Package Paths, Operational Commands, Package / Site-kit Generation Safety, Direct HTTPS and collector-share notes, Setup Wizard readiness, MVP manual boundaries, Security / Secret Redaction, Recommended Next Checks, and Result.

The command is not an installer and is not the portal setup wizard. It does not generate packages, site kits, backups, or tokens; it does not mutate database records, write files, run migrations, run nested Artisan commands, change `.env`, or expose secrets.

Supermicro validation at `D:\inventory\laravel` produced `Result: WARN` with expected current warnings for `APP_ENV=local`, `APP_DEBUG=true`, pilot/internal `APP_URL`, CLI inability to fully prove trusted proxy headers, unverified backup/restore rehearsal policy, and the not-yet-implemented Portal Setup Wizard MVP. The approved Supermicro host/path was detected, HTTPS `APP_URL` was accepted, storage/downloads/raw archive/backup paths were readable and writable, required and optional operational commands were detected, and no packages, site kits, backups, or tokens were generated.

## 11. MVP Manual Boundaries

These remain manual in the MVP:

- MariaDB/MySQL installation.
- DB migration execution.
- IIS + PHP FastCGI setup.
- HPE StoreEasy/proxy configuration.
- DNS/certificate setup.
- Windows certificate trust deployment.
- Backup target setup.
- Restore rehearsal.
- `.env` changes.
- `APP_KEY` handling.
- Production cutover approval.
- Token rotation.
- Per-runner token enrollment.
- Direct `repair_update`.
- Official package generation outside Supermicro.

These boundaries prevent the productization work from accidentally becoming an unreviewed production migration or security-sensitive automation phase.

## 12. Secret Redaction and Support-output Rules

Support output must be useful without leaking operational secrets. Never expose:

- Token secrets.
- `siteToken`.
- Collector tokens.
- Direct runner tokens.
- Bearer tokens.
- Token hashes.
- DB credentials.
- Google credentials.
- Raw CSV contents.
- Command payload JSON.
- `.env` values.
- `APP_KEY` values.
- Full configs.
- Full runner GUIDs.

Support summaries should use redacted labels, partial non-secret identifiers, timestamps, hostnames where safe, mode names, status results, and next-step instructions. Full runner GUIDs should not be printed; use a short redacted form only when needed for correlation.

## 13. Future Phase Roadmap

- Phase 18A - Installation Productization Strategy. Status: done.
- Phase 18B - Installer/server preflight command. Status: implemented, validated on Supermicro, documented.
- Phase 18C - Portal Setup Wizard MVP. Status: implemented, validated on Supermicro, documented.
- Phase 18D - Direct HTTPS Runner Installer MVP. Status: next candidate.
- Phase 18E - Collector-site Installer MVP. Status: after 18D.
- Then return to MariaDB migration runbook and rehearsal.

The roadmap intentionally keeps setup productization separate from production database migration and cutover approval.

## 14. Risks if Installation Is Simplified Too Aggressively

- The wrong deployment mode may be chosen because operational tradeoffs are hidden.
- TLS/proxy details may remain hidden until first failure.
- Token handling may become unsafe if installers print or store secrets loosely.
- Support output may leak secrets.
- The installer may give false production confidence.
- Database migration may feel one-click without backup and restore rehearsal.
- Collector-share and Direct HTTPS boundaries may blur.
- Direct `repair_update` may accidentally become implied.
- Command lifecycle semantics may be misunderstood.

## 15. Risks if Current Technical Install Flow Remains

- Adoption outside the original environment stays low.
- Support burden stays high.
- Installs become inconsistent across institutions.
- Operators may paste secrets into support chats or logs.
- Wrong packages may be generated from the wrong machine.
- Direct HTTPS and collector-share may get mixed accidentally.
- Backup and restore may be skipped.
- MariaDB, IIS, and TLS setup may become tribal knowledge.
- Future implementation phases may optimize the wrong workflow.

## 16. Acceptance Criteria

- `docs/INSTALLATION_PRODUCTIZATION_STRATEGY.md` is created.
- Simplified installation model is documented.
- Three installation modes are documented.
- Installer and wizard responsibilities are documented.
- MVP manual boundaries are documented.
- Secret redaction rules are documented.
- Phase 18B-18E roadmap is documented.
- Risks of over-simplification are documented.
- Risks of current manual flow are documented.
- Phase 18B read-only installer/server preflight command is documented.
- Phase 18C read-only Portal Setup Wizard MVP is documented.
- No application code is changed.
- No runner code is changed.
- No collector code is changed.
- No Direct HTTPS API contract is changed.
- No command lifecycle semantics are changed.
- Direct `repair_update` remains blocked.
- No production data is mutated.
- No `.env` values are changed or printed.
- No generated packages or site kits are created or modified.
- No secret-bearing files are staged.

## 17. Explicit Out-of-scope List

- No installer implementation.
- No portal setup wizard implementation.
- No installer behavior beyond the read-only server preflight command.
- No runner installer changes.
- No collector installer changes.
- No MariaDB migration execution.
- No production cutover.
- No token rotation UI.
- No per-runner token enrollment.
- No Direct `repair_update`.
