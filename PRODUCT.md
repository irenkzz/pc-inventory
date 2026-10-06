# Product

<!-- impeccable:product-schema 1 -->

## Platform

web

## Users

Internal IT admins at TVRI Papua who run a fleet of Windows PCs across branch sites. They open the portal during routine checks (are runners reporting, which PCs are stale or at risk) and during rollout work (build site kits, rotate tokens, send manual scans). Viewers have read-only access to the dashboard; Downloads and token management are admin-only. Admins check the dashboard from desktop and also from a phone.

## Product Purpose

Central inventory of Windows PCs. A PowerShell runner on each PC scans hardware and uploads via a branch collector (collector-share) or directly over HTTPS. Laravel is the authoritative record: ingest, identity matching, change detection, assignments, command queue, runner health, site kits. Success: admins trust what the dashboard says about each PC and runner without opening a log file.

## Positioning

An inventory built around runner health and asynchronous commands (manual scan is queued and ACKed, never "remote execution"), with two transports per site and signed runner updates. A generic CRUD asset list or browser-only scanner cannot show runner, collector, and transport state.

## Operating Context

Pilot deployment on a Supermicro server (Laravel 10, SQLite) behind an internal reverse proxy and a Cloudflare Tunnel for remote sites. Site ids are UPPERCASE (e.g. SITE-BHX1C). Admins work with runner ids, site tokens, kits, heartbeats, and ACK states daily.

## Capabilities and Constraints

- Stay on Laravel 10 Blade; the runner stays PowerShell, the collector stays Python.
- Commands are asynchronous: wording must say queued, delivered, ACKed, never "executed now".
- Missing SMART/TBW values are blank, never zero. Risk is shown via risk_level, risk_reasons, recommended_action.
- Direct HTTPS runners and collector-share runners report different fields; the UI must show the right one per transport.
- Roles: admin and viewer.

## Brand Commitments

None recorded. Interface language is bilingual: English for technical terms and existing labels, Indonesian for help and explanatory text.

## Evidence on Hand

Live data from the pilot (about 3 active runners among 38 rows). No testimonials, benchmarks, or marketing assets exist; do not fabricate any.

## Product Principles

1. Truthful state first: never show a blank or stale value where a known one exists.
2. Async honesty: delivery, ACK, and execution are distinct states and are labeled so.
3. Scan fast, act safely: dense tables for scanning, confirmation only on irreversible actions (revoke, rotate, delete).
4. Both transports are first-class; neither is a special case of the other.
5. Pilot-grade scope: small reviewable changes over new surfaces.

## Accessibility & Inclusion

Desktop primary, usable on a phone for dashboard checks. Target WCAG AA contrast and keyboard-reachable controls; no further requirement established (open decision).
