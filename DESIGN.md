---
name: Inventory Admin Portal
description: Dense, calm, light-surface operations console for Windows PC inventory and runner health.
colors:
  ink: "#182230"
  muted-slate: "#667085"
  label-slate: "#475467"
  canvas: "#f4f6f8"
  panel: "#ffffff"
  panel-soft: "#f8fafc"
  track-gray: "#edf1f6"
  chip-gray: "#eef2f6"
  hairline: "#d9e0ea"
  hairline-soft: "#e8edf3"
  field-border: "#c9d3df"
  nav-night: "#0f172a"
  nav-menu: "#111c32"
  nav-muted: "#b7c3d6"
  brand-teal: "#164e63"
  signal-blue: "#1d4ed8"
  signal-blue-soft: "#e8f0ff"
  ok-green: "#047857"
  ok-green-soft: "#e8f7ef"
  ok-green-border: "#a7f3d0"
  ok-green-text: "#065f46"
  warn-amber: "#b45309"
  warn-amber-soft: "#fff4df"
  danger-red: "#b42318"
  danger-red-soft: "#fff0ed"
  danger-red-border: "#fecaca"
  danger-red-text: "#7f1d1d"
  action-graphite: "#344054"
  code-night: "#101828"
typography:
  title:
    fontFamily: "Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif"
    fontSize: "28px"
    fontWeight: 700
    lineHeight: 1.18
    letterSpacing: "0"
  headline:
    fontFamily: "Inter, ui-sans-serif, system-ui, sans-serif"
    fontSize: "17px"
    fontWeight: 700
    lineHeight: 1.25
  body:
    fontFamily: "Inter, ui-sans-serif, system-ui, sans-serif"
    fontSize: "14px"
    fontWeight: 400
    lineHeight: 1.55
  label:
    fontFamily: "Inter, ui-sans-serif, system-ui, sans-serif"
    fontSize: "12px"
    fontWeight: 800
    letterSpacing: "0"
rounded:
  md: "8px"
  pill: "999px"
spacing:
  xs: "8px"
  sm: "12px"
  md: "18px"
  lg: "24px"
components:
  button-primary:
    backgroundColor: "{colors.signal-blue}"
    textColor: "#ffffff"
    rounded: "{rounded.md}"
    padding: "9px 13px"
    height: "38px"
  button-secondary:
    backgroundColor: "{colors.action-graphite}"
    textColor: "#ffffff"
    rounded: "{rounded.md}"
    padding: "9px 13px"
    height: "38px"
  badge-good:
    backgroundColor: "{colors.ok-green-soft}"
    textColor: "{colors.ok-green}"
    rounded: "{rounded.pill}"
    padding: "3px 9px"
  badge-warn:
    backgroundColor: "{colors.warn-amber-soft}"
    textColor: "{colors.warn-amber}"
    rounded: "{rounded.pill}"
    padding: "3px 9px"
  badge-danger:
    backgroundColor: "{colors.danger-red-soft}"
    textColor: "{colors.danger-red}"
    rounded: "{rounded.pill}"
    padding: "3px 9px"
  panel:
    backgroundColor: "{colors.panel}"
    rounded: "{rounded.md}"
    padding: "18px"
  input:
    backgroundColor: "#ffffff"
    textColor: "{colors.ink}"
    rounded: "{rounded.md}"
    height: "38px"
    padding: "8px 10px"
---

# Design System: Inventory Admin Portal

## Overview

**Creative North Star: "The Control Room Ledger"**

A quiet, light-surfaced ledger under a dark navigation bar. Rows, counts, and status chips carry the interface; decoration does not. An IT admin scans it between other tasks, so every screen must answer "what is wrong, where" before anything else. The surface is Operate mode (see PRODUCT.md): consistent, dense, predictable.

Components are calm and friendly. Soft 8px corners, 12-18px padding inside dense layouts, soft-tinted status fills instead of hard solid alarms. Admins open this daily; it stays approachable without losing scan speed. North Star and component character confirmed by the owner.

Color is semantic. Blue means action or information, green healthy, amber attention, red failure. Everything else is cool gray on white. Depth is nearly flat: hairline borders separate, and shadow is reserved for the header and the floating menu.

**Key Characteristics:**
- Cool-gray canvas, white panels, 1px hairline borders, 8px corners everywhere
- Dark slate top navigation with grouped `<details>` dropdown menus
- Soft-fill status badges and banners for every state
- Uppercase 12px column and metric labels over 14px body
- One font family (Inter, system fallback); hierarchy by size and weight only
- Collapse at 980px and 700px; wide tables scroll horizontally

## Colors

Cool slate neutrals plus four semantic signals. No decorative accent.

### Primary
- **Signal Blue** (#1d4ed8): primary buttons, links, info badges, progress fill, metric icons (on Signal Blue Soft #e8f0ff).

### Secondary
- **Brand Teal** (#164e63): the 38px brand mark square in the header only.

### Tertiary
- **Ok Green** (#047857 on #e8f7ef; banner border #a7f3d0, banner text #065f46), **Warn Amber** (#b45309 on #fff4df), **Danger Red** (#b42318 on #fff0ed; banner border #fecaca, banner text #7f1d1d): status and flash banners only.

### Neutral
- **Ink** (#182230) body text. **Muted Slate** (#667085) secondary copy and metadata. **Label Slate** (#475467) table header text and neutral badge text.
- **Canvas** (#f4f6f8) page background. **Panel** (#ffffff) and **Panel Soft** (#f8fafc) surfaces and table headers. **Chip Gray** (#eef2f6) neutral badge fill. **Track Gray** (#edf1f6) progress track.
- **Hairline** (#d9e0ea), **Hairline Soft** (#e8edf3), **Field Border** (#c9d3df): borders and dividers.
- **Nav Night** (#0f172a), **Nav Menu** (#111c32), **Nav Muted** (#b7c3d6): header bar, dropdown, inactive nav text.
- **Action Graphite** (#344054): secondary buttons. **Code Night** (#101828): `pre` blocks with near-white text (#f9fafb).

### Named Rules
**The Signal Only Rule.** Green, amber, and red appear only to report state. If a color does not mean something, remove it.

**The Soft Fill Rule.** A status is a dark text tone on its own soft tint, always with a text label. Color never carries meaning alone.

## Typography

**Display Font:** Inter (with ui-sans-serif, system-ui, Segoe UI, sans-serif)
**Body Font:** same family
**Label/Mono Font:** none in the admin portal; `pre` uses the browser default monospace

**Character:** Neutral and tool-like. Weight and size, not a second typeface, create hierarchy.

### Hierarchy
- **Title** (700, 28px, 1.18; 24px under 700px): page `h2`.
- **Headline** (700, 17px, 1.25): section `h3`; panel titles use 16px.
- **Body** (400, 14px, 1.55): table cells, paragraphs.
- **Label** (800, 12px, uppercase, no tracking): table headers, info-tile labels. Metric labels are 13px/750.
- **Metric value** (700, 32px, line-height 1): dashboard counters. Ops values are 22px.

### Named Rules
**The One Family Rule.** Do not introduce a second typeface. Add hierarchy with size and weight.

## Layout

Content sits in a centered shell up to 1440px wide, 24px side padding (16px under 700px). The header is a three-column grid (brand, nav, user), stacking at 980px. Dashboard sections use auto-fit grids (`minmax(210px, 1fr)`, 14px gap) for metrics, an ops strip, and a two-column layout that collapses on narrow screens. Spacing steps in use: 8, 12, 14, 18, 24. Tables are the primary content form and sit inside `.table-scroll` so wide data scrolls instead of reflowing.

## Elevation & Depth

Hybrid, mostly flat. Hairlines separate surfaces; a faint shadow lifts panels and tables.

### Shadow Vocabulary
- **Panel rest** (`box-shadow: 0 1px 2px rgba(16, 24, 40, .04)`): metrics, panels, tables, form grids.
- **Header** (`box-shadow: 0 10px 24px rgba(15, 23, 42, .18)`): sticky top bar.
- **Floating menu** (`box-shadow: 0 18px 40px rgba(15, 23, 42, .28)`): nav dropdown.

### Named Rules
**The Flat Content Rule.** Content panels never gain a larger shadow. Only the header and floating menus may float.

## Shapes

One radius: 8px on buttons, inputs, panels, tiles, tables, banners, and menus. Pills (999px) only for badges, progress tracks, and status dots. Borders are 1px; a dashed border marks empty states.

## Components

Philosophy: calm and friendly. Rounded forms, soft tints, restrained weight; nothing shouts except a real failure.

### Buttons
- **Shape:** 8px radius, 38px min height.
- **Primary:** Signal Blue fill, white 14px/750 text, padding 9px 13px, faint shadow.
- **Secondary:** Action Graphite fill (#344054).
- **Hover / Disabled:** hover applies `filter: brightness(.96)` and drops the underline; disabled is 55% opacity with `not-allowed` cursor.

### Badges
- 24px min height, pill, 12px/800 text, soft fill by state (`good`, `warn`, `danger`, `info`, neutral gray default). Always carries a text label.

### Banners
- `.notice` (green soft fill, #a7f3d0 border, #065f46 text) and `.error-list` (red soft fill, #fecaca border, #7f1d1d text): 8px radius, 12px 14px padding, 14px text.

### Panels / Cards
- White, 1px Hairline Soft border, 8px corners, 18px padding. Header row: 16px title and 13px muted subtitle left, `.actions` right.

### Inputs / Fields
- 38px min height, 1px #c9d3df border, 8px radius, white. Text input defaults to `min(420px, 100%)`.
- **Focus:** all links, buttons, inputs, selects, and summaries show a 2px Signal Blue `:focus-visible` outline with 2px offset (white inside the dark header). Phone widths set input font-size to 16px to stop iOS zoom.

### Tables
- Collapsed-separate, outer 8px radius, Panel Soft header with uppercase 12px labels, 12px/14px cell padding, soft row dividers, subtle row hover (#fbfcfe).

### Navigation
- Dark bar, 36px-high links, 14px/650 text in Nav Muted; hover or active shows white text on `rgba(255,255,255,.1)`. Grouped items use native `<details>` with a CSS chevron; below 980px menus flow inline.

### Entity cell (signature)
- 8px-radius initials avatar with title and 12px subtitle, used for devices and runners in lists.

### Progress bar
- 7px pill track (#edf1f6) with a Signal Blue fill, used in dashboard summary rows.

## Do's and Don'ts

### Do:
- **Do** express status as a soft-fill badge or banner with a text label.
- **Do** keep every container at 8px radius and 1px hairline borders.
- **Do** wrap wide tables in `.table-scroll` and keep the 980px and 700px collapse points.
- **Do** show a known value over a blank one; use "-" only when truly absent.
- **Do** keep the global `:focus-visible` outline working on every new interactive element.
- **Do** build new screens on `admin/layout.blade.php` tokens (`--blue`, `--green-soft`, `--border-soft`), not new hex values.

### Don't:
- **Don't** use green, amber, or red as decoration or section color.
- **Don't** add a second typeface, gradients, or large shadows on content panels.
- **Don't** use color alone to convey state.
- **Don't** introduce new radii, new accent hues, or hero-style marketing layouts; this is an Operate surface.
- **Don't** fork the palette: `auth/login.blade.php` carries its own copy of the portal tokens and must stay in sync. `welcome.blade.php` is the Laravel scaffold and is not part of the system.
