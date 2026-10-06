<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ?? config('app.name') }}</title>
    <style>
        :root {
            --bg: #f4f6f8;
            --panel: #ffffff;
            --panel-soft: #f8fafc;
            --border: #d9e0ea;
            --border-soft: #e8edf3;
            --text: #182230;
            --muted: #667085;
            --nav: #0f172a;
            --nav-muted: #b7c3d6;
            --blue: #1d4ed8;
            --blue-soft: #e8f0ff;
            --green: #047857;
            --green-soft: #e8f7ef;
            --amber: #b45309;
            --amber-soft: #fff4df;
            --red: #b42318;
            --red-soft: #fff0ed;
            --shadow: 0 14px 32px rgba(15, 23, 42, .08);
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            min-height: 100vh;
            font-family: Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            color: var(--text);
            background: var(--bg);
        }

        a { color: var(--blue); text-decoration: none; }
        a:hover { text-decoration: underline; }

        header {
            position: sticky;
            top: 0;
            z-index: 20;
            color: #fff;
            background: var(--nav);
            border-bottom: 1px solid rgba(255, 255, 255, .08);
            box-shadow: 0 10px 24px rgba(15, 23, 42, .18);
        }

        .topbar {
            display: grid;
            grid-template-columns: minmax(220px, auto) 1fr auto;
            gap: 18px;
            align-items: center;
            width: min(1440px, 100%);
            margin: 0 auto;
            padding: 14px 24px;
        }

        .brand {
            display: flex;
            align-items: center;
            gap: 12px;
            min-width: 0;
        }

        .brand-mark {
            display: grid;
            place-items: center;
            width: 38px;
            height: 38px;
            border-radius: 8px;
            color: #dff6ff;
            background: #164e63;
            border: 1px solid rgba(255, 255, 255, .18);
            font-size: 13px;
            font-weight: 800;
            letter-spacing: 0;
            flex: 0 0 auto;
        }

        header h1 {
            margin: 0;
            font-size: 16px;
            line-height: 1.2;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .timezone-note {
            margin-top: 3px;
            font-size: 12px;
            color: var(--nav-muted);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        nav {
            display: flex;
            gap: 6px;
            flex-wrap: wrap;
            align-items: center;
            justify-content: center;
        }

        .nav-link,
        .nav-menu-label {
            display: inline-flex;
            align-items: center;
            min-height: 36px;
            padding: 8px 10px;
            border-radius: 8px;
            color: var(--nav-muted);
            font-size: 14px;
            font-weight: 650;
            text-decoration: none;
        }

        .nav-link:hover,
        .nav-link.is-active,
        .nav-menu-label:hover,
        .nav-menu[open] > .nav-menu-label,
        .nav-menu-label.is-active {
            color: #fff;
            background: rgba(255, 255, 255, .1);
            text-decoration: none;
        }

        .nav-menu {
            position: relative;
        }

        .nav-menu-label {
            list-style: none;
            cursor: pointer;
            gap: 6px;
        }

        .nav-menu-label::-webkit-details-marker {
            display: none;
        }

        .nav-menu-label::after {
            content: "";
            width: 7px;
            height: 7px;
            border-right: 2px solid currentColor;
            border-bottom: 2px solid currentColor;
            transform: rotate(45deg) translateY(-2px);
            opacity: .72;
        }

        .nav-menu[open] > .nav-menu-label::after {
            transform: rotate(225deg) translateY(-1px);
        }

        .nav-menu-items {
            position: absolute;
            top: calc(100% + 8px);
            left: 0;
            z-index: 40;
            min-width: 210px;
            padding: 8px;
            border: 1px solid rgba(255, 255, 255, .12);
            border-radius: 8px;
            background: #111c32;
            box-shadow: 0 18px 40px rgba(15, 23, 42, .28);
        }

        .nav-menu-items .nav-link {
            display: flex;
            width: 100%;
            justify-content: space-between;
            color: var(--nav-muted);
            white-space: nowrap;
        }

        .nav-button {
            border: 0;
            cursor: pointer;
            font: inherit;
            background: transparent;
        }

        .page-shell {
            width: min(1440px, 100%);
            margin: 0 auto;
            padding: 28px 24px 40px;
        }

        h2 {
            margin: 0 0 18px;
            font-size: 28px;
            line-height: 1.18;
            letter-spacing: 0;
        }

        h3 {
            margin: 26px 0 12px;
            font-size: 17px;
            line-height: 1.25;
        }

        p { line-height: 1.55; }

        .muted { color: var(--muted); }

        .grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(210px, 1fr));
            gap: 14px;
        }

        .metric,
        .panel,
        .form-grid,
        dl,
        table {
            background: var(--panel);
            border: 1px solid var(--border-soft);
            border-radius: 8px;
            box-shadow: 0 1px 2px rgba(16, 24, 40, .04);
        }

        .metric {
            position: relative;
            min-height: 132px;
            padding: 18px;
            overflow: hidden;
        }

        .metric-label {
            color: var(--muted);
            font-size: 13px;
            font-weight: 750;
            text-transform: uppercase;
            letter-spacing: 0;
        }

        .metric strong {
            display: block;
            margin-top: 10px;
            font-size: 32px;
            line-height: 1;
            letter-spacing: 0;
        }

        .metric p {
            margin: 10px 0 0;
            color: var(--muted);
            font-size: 13px;
        }

        .metric-icon,
        .status-dot {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            flex: 0 0 auto;
        }

        .metric-icon {
            width: 34px;
            height: 34px;
            border-radius: 8px;
            background: var(--blue-soft);
            color: var(--blue);
            font-size: 17px;
            font-weight: 800;
        }

        .metric-top {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 12px;
        }

        .panel {
            padding: 18px;
        }

        .panel-header {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 16px;
            margin-bottom: 12px;
        }

        .panel-title {
            margin: 0;
            font-size: 16px;
        }

        .panel-subtitle {
            margin: 4px 0 0;
            color: var(--muted);
            font-size: 13px;
        }

        .actions {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            align-items: center;
        }

        button,
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 38px;
            border: 1px solid transparent;
            border-radius: 8px;
            background: var(--blue);
            color: #fff;
            padding: 9px 13px;
            cursor: pointer;
            text-decoration: none;
            font: inherit;
            font-size: 14px;
            font-weight: 750;
            box-shadow: 0 1px 2px rgba(16, 24, 40, .08);
        }

        button:hover,
        .btn:hover {
            text-decoration: none;
            filter: brightness(.96);
        }

        button:disabled,
        .btn.is-disabled,
        .pagination-link.is-disabled {
            cursor: not-allowed;
            opacity: .55;
            filter: none;
        }

        .btn.secondary,
        button.secondary {
            background: #344054;
            border-color: #344054;
            color: #fff;
        }

        input[type=text],
        input[type=date],
        select {
            min-height: 38px;
            border: 1px solid #c9d3df;
            border-radius: 8px;
            padding: 8px 10px;
            max-width: 100%;
            color: var(--text);
            background: #fff;
        }

        input[type=text] { width: min(420px, 100%); }

        label {
            display: grid;
            gap: 6px;
            font-size: 13px;
            font-weight: 750;
            color: #344054;
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(190px, 1fr));
            gap: 14px;
            padding: 16px;
            margin: 14px 0 24px;
        }

        .form-actions {
            grid-column: 1 / -1;
            display: flex;
            gap: 8px;
            align-items: center;
            flex-wrap: wrap;
        }

        table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
            margin: 14px 0 24px;
            overflow: hidden;
        }

        th,
        td {
            overflow-wrap: break-word;
            border-bottom: 1px solid var(--border-soft);
            padding: 12px 14px;
            text-align: left;
            vertical-align: top;
            font-size: 14px;
        }

        th {
            background: var(--panel-soft);
            color: #475467;
            font-size: 12px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0;
        }

        tr:last-child td { border-bottom: 0; }
        tbody tr:hover td { background: #fbfcfe; }

        .table-scroll {
            width: 100%;
            overflow-x: auto;
        }

        .table-scroll table {
            min-width: 780px;
            margin: 0;
        }

        .panel table {
            border: 0;
            border-radius: 0;
            box-shadow: none;
        }

        .notice,
        .error-list {
            border-radius: 8px;
            padding: 12px 14px;
            margin-bottom: 16px;
            font-size: 14px;
        }

        .notice {
            background: var(--green-soft);
            border: 1px solid #a7f3d0;
            color: #065f46;
        }

        .error-list {
            background: var(--red-soft);
            border: 1px solid #fecaca;
            color: #7f1d1d;
        }

        dl {
            display: grid;
            grid-template-columns: minmax(180px, 240px) 1fr;
            gap: 0;
            padding: 0;
            overflow: hidden;
        }

        dt,
        dd {
            margin: 0;
            padding: 12px 14px;
            border-bottom: 1px solid var(--border-soft);
        }

        dt {
            color: #475467;
            background: var(--panel-soft);
            font-weight: 800;
        }

        dd { word-break: break-word; }
        dt:last-of-type,
        dd:last-of-type { border-bottom: 0; }

        .spec-list {
            display: grid;
            gap: 6px;
            margin: 0;
            padding: 0;
            list-style: none;
        }

        .spec-list li {
            position: relative;
            padding-left: 14px;
            line-height: 1.35;
        }

        .spec-list li::before {
            content: "";
            position: absolute;
            left: 0;
            top: .65em;
            width: 5px;
            height: 5px;
            border-radius: 999px;
            background: var(--blue);
        }

        .spec-list.compact {
            gap: 3px;
        }

        .spec-list.compact li {
            padding-left: 11px;
            font-size: 12px;
            color: var(--muted);
        }

        .spec-list.compact li::before {
            width: 4px;
            height: 4px;
        }

        pre {
            white-space: pre-wrap;
            word-break: break-word;
            background: #101828;
            color: #f9fafb;
            padding: 14px;
            border-radius: 8px;
            overflow-x: auto;
        }

        .badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            min-height: 24px;
            border-radius: 999px;
            padding: 3px 9px;
            font-size: 12px;
            font-weight: 800;
            color: #475467;
            background: #eef2f6;
            white-space: nowrap;
        }

        .badge.good { color: var(--green); background: var(--green-soft); }
        .badge.warn { color: var(--amber); background: var(--amber-soft); }
        .badge.danger { color: var(--red); background: var(--red-soft); }
        .badge.info { color: var(--blue); background: var(--blue-soft); }

        .status-dot {
            width: 8px;
            height: 8px;
            border-radius: 999px;
            background: currentColor;
        }

        .empty-state {
            padding: 28px 18px;
            text-align: center;
            color: var(--muted);
            background: var(--panel);
            border: 1px dashed #cbd5e1;
            border-radius: 8px;
        }

        .dashboard-hero {
            display: grid;
            grid-template-columns: minmax(0, 1fr) auto;
            gap: 18px;
            align-items: end;
            margin-bottom: 18px;
        }

        .dashboard-kicker {
            margin: 0 0 7px;
            color: var(--blue);
            font-size: 13px;
            font-weight: 850;
            text-transform: uppercase;
            letter-spacing: 0;
        }

        .dashboard-title {
            margin-bottom: 8px;
        }

        .dashboard-copy {
            max-width: 760px;
            margin: 0;
            color: var(--muted);
            font-size: 15px;
        }

        .dashboard-toolbar {
            display: flex;
            gap: 8px;
            align-items: center;
            flex-wrap: wrap;
            justify-content: flex-end;
        }

        .dashboard-section {
            margin-top: 18px;
        }

        .dashboard-layout {
            display: grid;
            grid-template-columns: minmax(0, 1.65fr) minmax(320px, .85fr);
            gap: 16px;
            align-items: start;
        }

        .stack {
            display: grid;
            gap: 16px;
        }

        .ops-strip {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 14px;
            margin-bottom: 18px;
        }

        .ops-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            min-height: 82px;
            padding: 16px;
            background: var(--panel);
            border: 1px solid var(--border-soft);
            border-radius: 8px;
        }

        .ops-label {
            display: block;
            color: var(--muted);
            font-size: 13px;
            font-weight: 750;
        }

        .ops-value {
            display: block;
            margin-top: 4px;
            font-size: 22px;
            font-weight: 850;
        }

        .summary-list {
            display: grid;
            gap: 12px;
        }

        .summary-row {
            display: grid;
            grid-template-columns: minmax(0, 1fr) auto;
            gap: 12px;
            align-items: center;
        }

        .summary-name {
            min-width: 0;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            font-weight: 800;
        }

        .summary-meta {
            margin-top: 3px;
            color: var(--muted);
            font-size: 12px;
        }

        .progress-track {
            height: 7px;
            margin-top: 9px;
            overflow: hidden;
            border-radius: 999px;
            background: #edf1f6;
        }

        .progress-fill {
            height: 100%;
            border-radius: inherit;
            background: var(--blue);
        }

        .entity-cell {
            display: flex;
            align-items: center;
            gap: 10px;
            min-width: 0;
        }

        .entity-avatar {
            display: grid;
            place-items: center;
            width: 34px;
            height: 34px;
            border-radius: 8px;
            color: #344054;
            background: #eef2f6;
            font-size: 12px;
            font-weight: 850;
            flex: 0 0 auto;
        }

        .entity-main {
            min-width: 0;
        }

        .entity-title {
            display: block;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            font-weight: 800;
        }

        .entity-subtitle {
            display: block;
            overflow-wrap: anywhere;
            margin-top: 2px;
            color: var(--muted);
            font-size: 12px;
        }

        .detail-hero {
            display: grid;
            grid-template-columns: minmax(0, 1fr) auto;
            gap: 18px;
            align-items: start;
            margin-bottom: 18px;
        }

        .detail-title-row {
            display: flex;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
            margin-bottom: 8px;
        }

        .detail-title-row h2 {
            margin: 0;
        }

        .detail-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            color: var(--muted);
            font-size: 13px;
        }

        .quick-nav {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            justify-content: flex-end;
        }

        .quick-nav .btn {
            min-width: 108px;
            color: #fff;
            background: var(--blue);
            border-color: var(--blue);
        }

        .quick-nav .btn.secondary {
            background: #344054;
            border-color: #344054;
            color: #fff;
        }

        .quick-nav a.btn:hover {
            text-decoration: none;
        }

        .pagination-shell {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            flex-wrap: wrap;
            margin-top: 18px;
            padding-top: 16px;
            border-top: 1px solid var(--border-soft);
        }

        .pagination-summary {
            color: var(--muted);
            font-size: 13px;
        }

        .pagination-list {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
        }

        .pagination-link {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 38px;
            min-height: 38px;
            padding: 8px 12px;
            border: 1px solid var(--border);
            border-radius: 8px;
            background: #fff;
            color: #344054;
            font-size: 14px;
            font-weight: 750;
            text-decoration: none;
            box-shadow: 0 1px 2px rgba(16, 24, 40, .04);
        }

        .pagination-link:hover {
            background: var(--panel-soft);
            text-decoration: none;
        }

        .pagination-link.is-active {
            color: #fff;
            background: var(--blue);
            border-color: var(--blue);
        }

        .pagination-link.is-disabled {
            background: #f8fafc;
            color: #98a2b3;
            border-color: var(--border-soft);
        }

        .detail-grid {
            display: grid;
            grid-template-columns: minmax(0, 1.15fr) minmax(320px, .85fr);
            gap: 16px;
            align-items: start;
        }

        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 12px;
        }

        .info-tile {
            min-height: 96px;
            padding: 14px;
            border: 1px solid var(--border-soft);
            border-radius: 8px;
            background: var(--panel-soft);
        }

        .info-label {
            display: block;
            color: var(--muted);
            font-size: 12px;
            font-weight: 800;
            text-transform: uppercase;
        }

        .info-value {
            display: block;
            margin-top: 8px;
            font-size: 16px;
            font-weight: 850;
            word-break: break-word;
        }

        .info-note {
            display: block;
            margin-top: 5px;
            color: var(--muted);
            font-size: 12px;
            word-break: break-word;
        }

        .section-anchor {
            scroll-margin-top: 96px;
        }

        .compact-dl {
            grid-template-columns: minmax(160px, 220px) minmax(0, 1fr);
        }

        .raw-snapshot {
            max-height: 520px;
            overflow: auto;
        }

        a:focus-visible,
        button:focus-visible,
        .btn:focus-visible,
        input:focus-visible,
        select:focus-visible,
        summary:focus-visible,
        .pagination-link:focus-visible {
            outline: 2px solid var(--blue);
            outline-offset: 2px;
        }

        header a:focus-visible,
        header button:focus-visible,
        header summary:focus-visible {
            outline-color: #fff;
        }

        @media (max-width: 980px) {
            .topbar {
                grid-template-columns: 1fr;
                gap: 12px;
            }

            nav {
                justify-content: flex-start;
                overflow-x: auto;
                flex-wrap: nowrap;
                padding-bottom: 2px;
            }

            .nav-link,
            .nav-menu-label { white-space: nowrap; }

            .nav-menu {
                position: static;
            }

            .nav-menu-items {
                position: fixed;
                top: 86px;
                left: 16px;
                right: 16px;
                min-width: 0;
            }

            .dashboard-hero,
            .detail-hero,
            .detail-grid,
            .dashboard-layout,
            .ops-strip {
                grid-template-columns: 1fr;
            }

            .dashboard-toolbar,
            .quick-nav {
                justify-content: flex-start;
            }

            .pagination-shell {
                align-items: stretch;
            }

            .pagination-list {
                width: 100%;
            }
        }

        @media (max-width: 700px) {
            .page-shell { padding: 22px 16px 32px; }
            .topbar { padding: 12px 16px; }
            h2 { font-size: 24px; }
            input[type=text],
            input[type=date],
            select { font-size: 16px; }
            dl { grid-template-columns: 1fr; }
            dt { border-bottom: 0; }
        }
    </style>
</head>
<body>
<header>
    @php
        $navItems = [
            ['label' => 'Dashboard', 'route' => 'admin.dashboard', 'match' => 'admin.dashboard'],
            ['label' => 'Devices', 'route' => 'admin.devices.index', 'match' => 'admin.devices.*'],
        ];
        $navGroups = [
            [
                'label' => 'Inventory',
                'items' => [
                    ['label' => 'Review', 'route' => 'admin.inventory-review.index', 'match' => 'admin.inventory-review.*'],
                    ['label' => 'Changes', 'route' => 'admin.changes.index', 'match' => 'admin.changes.*'],
                    ['label' => 'Storage Health', 'route' => 'admin.storage-health.index', 'match' => 'admin.storage-health.*'],
                    ['label' => 'Rules', 'route' => 'admin.classification-rules.index', 'match' => 'admin.classification-rules.*'],
                ],
            ],
            [
                'label' => 'Operations',
                'items' => [
                    ['label' => 'Pilot Readiness', 'route' => 'admin.pilot-readiness.index', 'match' => 'admin.pilot-readiness.*'],
                    ['label' => 'Setup Wizard', 'route' => 'admin.setup-wizard.index', 'match' => 'admin.setup-wizard.*'],
                    ['label' => 'Runners', 'route' => 'admin.runners.index', 'match' => 'admin.runners.*'],
                    ['label' => 'Collectors', 'route' => 'admin.collectors.index', 'match' => 'admin.collectors.*'],
                    ['label' => 'Commands', 'route' => 'admin.commands.index', 'match' => 'admin.commands.*'],
                    ['label' => 'Downloads', 'route' => 'admin.downloads.index', 'match' => 'admin.downloads.*', 'admin' => true],
                ],
            ],
            [
                'label' => 'Audit',
                'items' => [
                    ['label' => 'Evidence', 'route' => 'admin.raw-evidence.index', 'match' => 'admin.raw-evidence.*'],
                    ['label' => 'Reports', 'route' => 'admin.reports.index', 'match' => 'admin.reports.*'],
                    ['label' => 'Audit Log', 'route' => 'admin.audit-log.index', 'match' => 'admin.audit-log.*'],
                ],
            ],
        ];
    @endphp
    <div class="topbar">
        <div class="brand">
            <div class="brand-mark" aria-hidden="true">PC</div>
            <div>
                <h1>{{ config('app.name') }}</h1>
                <div class="timezone-note">
                    Locale: {{ \App\Support\InventoryFormat::locale() }} |
                    Time zone: {{ \App\Support\InventoryTime::timezone() }} {{ \App\Support\InventoryTime::label() !== '' ? '(' . \App\Support\InventoryTime::label() . ')' : '' }}
                </div>
            </div>
        </div>
    <nav>
        @foreach($navItems as $item)
            <a
                href="{{ route($item['route']) }}"
                class="nav-link {{ request()->routeIs($item['match']) ? 'is-active' : '' }}"
            >{{ $item['label'] }}</a>
        @endforeach
        @foreach($navGroups as $group)
            @php
                $groupActive = collect($group['items'])->contains(fn ($item) => request()->routeIs($item['match']));
            @endphp
            <details class="nav-menu">
                <summary class="nav-menu-label {{ $groupActive ? 'is-active' : '' }}">{{ $group['label'] }}</summary>
                <div class="nav-menu-items">
                    @foreach($group['items'] as $item)
                        @continue(! empty($item['admin']) && ! auth()->user()?->isAdmin())
                        <a
                            href="{{ route($item['route']) }}"
                            class="nav-link {{ request()->routeIs($item['match']) ? 'is-active' : '' }}"
                        >{{ $item['label'] }}</a>
                    @endforeach
                </div>
            </details>
        @endforeach
    </nav>
        <form method="post" action="{{ route('logout') }}" style="margin:0">
            @csrf
            <button type="submit" class="nav-link nav-button">Logout</button>
        </form>
    </div>
</header>
<main class="page-shell">
    @if(session('status'))
        <div class="notice">{{ session('status') }}</div>
    @endif
    @if($errors->any())
        <div class="error-list">
            @foreach($errors->all() as $error)
                <div>{{ $error }}</div>
            @endforeach
        </div>
    @endif
    @yield('content')
</main>
<script>
    // Block double-submit on POST forms; re-enable after 8s (file downloads never navigate away) and on back-forward restore.
    document.addEventListener('submit', function (e) {
        var form = e.target;
        if (e.defaultPrevented || String(form.method).toLowerCase() !== 'post') return;
        setTimeout(function () {
            form.querySelectorAll('button[type=submit], button:not([type])').forEach(function (b) {
                if (b.disabled) return;
                b.disabled = true;
                b.dataset.locked = '1';
                setTimeout(function () { b.disabled = false; delete b.dataset.locked; }, 8000);
            });
        }, 0);
    });
    window.addEventListener('pageshow', function (e) {
        if (!e.persisted) return;
        document.querySelectorAll('button[data-locked]').forEach(function (b) { b.disabled = false; delete b.dataset.locked; });
    });
</script>
</body>
</html>
