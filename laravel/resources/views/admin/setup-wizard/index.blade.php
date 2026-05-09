@extends('admin.layout')

@section('content')
    @php
        $number = fn (?int $value): string => $value === null ? 'Not available in MVP' : number_format($value);
    @endphp

    <div class="dashboard-hero">
        <div>
            <p class="dashboard-kicker">Read-only guided setup MVP</p>
            <h2 class="dashboard-title">Portal Setup Wizard MVP</h2>
            <p class="dashboard-copy">
                This page guides the future productized setup flow without provisioning anything. It is not production cutover approval, and official packages/site kits are generated only on Supermicro.
            </p>
        </div>
        <div class="dashboard-toolbar">
            <span class="badge info">Read-only</span>
            <span class="badge warn">No production approval</span>
        </div>
    </div>

    <section class="panel dashboard-section">
        <div class="panel-header">
            <div>
                <h3 class="panel-title">Target Setup Flow</h3>
                <p class="panel-subtitle">The MVP explains the sequence; it does not persist choices or create installation artifacts.</p>
            </div>
        </div>
        <div class="grid">
            @foreach([
                'Install central server',
                'Open portal setup wizard',
                'Confirm first admin/company/site setup status',
                'Choose deployment mode',
                'Download generated installer/package',
                'Install runner or collector',
                'Verify from portal',
            ] as $index => $step)
                <div class="info-tile">
                    <span class="info-label">Step {{ $index + 1 }}</span>
                    <span class="info-value">{{ $step }}</span>
                </div>
            @endforeach
        </div>
    </section>

    <section class="panel dashboard-section">
        <div class="panel-header">
            <div>
                <h3 class="panel-title">Step 1 - Server Readiness</h3>
                <p class="panel-subtitle">Run the preflight from the active Laravel host. This page does not run nested Artisan commands.</p>
            </div>
        </div>
        <div class="info-grid">
            <div class="info-tile">
                <span class="info-label">APP_URL host</span>
                <span class="info-value">{{ $appUrlHost }}</span>
                <span class="info-note">Host label only; raw `.env` values are not displayed.</span>
            </div>
            <div class="info-tile">
                <span class="info-label">HTTPS readiness</span>
                <span class="info-value">{{ $httpsReady ? 'HTTPS configured' : 'HTTPS not confirmed' }}</span>
                <span class="info-note">{{ $httpsReady ? 'APP_URL scheme is HTTPS.' : 'Review APP_URL before package generation.' }}</span>
            </div>
            <div class="info-tile">
                <span class="info-label">Pilot/internal hostname</span>
                <span class="info-value">{{ $usesPilotOrInternalHost ? 'Review warning expected' : 'No pilot/internal host detected' }}</span>
                <span class="info-note">Pilot/internal hostnames can be valid during pilot but need rollout review.</span>
            </div>
        </div>
        <pre>php artisan inventory:install-preflight</pre>
        <p class="muted">Expected current result may be WARN during pilot. FAIL must be reviewed before continuing.</p>
    </section>

    <section class="panel dashboard-section">
        <div class="panel-header">
            <div>
                <h3 class="panel-title">Step 2 - Admin / Organization Status</h3>
                <p class="panel-subtitle">Phase 18C assumes the portal can already authenticate an administrator.</p>
            </div>
        </div>
        <ul class="spec-list">
            <li>Current authenticated user/admin exists: {{ auth()->user()?->email ?? 'authenticated user' }}.</li>
            <li>First-admin creation is assumed complete for Phase 18C MVP.</li>
            <li>Company/organization profile is planned for a later phase.</li>
            <li>No new company/organization schema is added in Phase 18C.</li>
        </ul>
    </section>

    <section class="panel dashboard-section">
        <div class="panel-header">
            <div>
                <h3 class="panel-title">Step 3 - Site Setup Status</h3>
                <p class="panel-subtitle">Read-only counts from existing tables only. Missing tables are reported without adding schema.</p>
            </div>
        </div>
        <div class="ops-strip">
            <div class="ops-item"><span class="ops-label">Collector sites</span><span class="ops-value">{{ $number($counts['collector_sites']) }}</span></div>
            <div class="ops-item"><span class="ops-label">Runners</span><span class="ops-value">{{ $number($counts['runners']) }}</span></div>
            <div class="ops-item"><span class="ops-label">Collectors</span><span class="ops-value">{{ $number($counts['collectors']) }}</span></div>
        </div>
        <div class="grid">
            <div class="info-tile">
                <span class="info-label">Direct HTTPS runners</span>
                <span class="info-value">{{ $number($counts['direct_runners']) }}</span>
            </div>
            <div class="info-tile">
                <span class="info-label">Collector-share runners</span>
                <span class="info-value">{{ $number($counts['collector_share_runners']) }}</span>
            </div>
        </div>
        <p class="muted">No sites, collector_sites, or tokens are created by this page.</p>
    </section>

    <section class="panel dashboard-section">
        <div class="panel-header">
            <div>
                <h3 class="panel-title">Step 4 - Choose Deployment Mode</h3>
                <p class="panel-subtitle">Mode selection is visual only in Phase 18C and is not persisted.</p>
            </div>
        </div>
        <div class="grid">
            <div class="info-tile">
                <h3 class="panel-title">Small/no-IT Direct HTTPS</h3>
                <p class="muted">For one PC, small site, remote office, or no local collector/share.</p>
                <ul class="spec-list">
                    <li>Flow: Runner -&gt; Laravel HTTPS.</li>
                    <li>Polling means delivery.</li>
                    <li>ACK means execution result.</li>
                    <li>Uses direct_runner token, not collector token.</li>
                    <li>Direct repair_update remains blocked.</li>
                </ul>
            </div>
            <div class="info-tile">
                <h3 class="panel-title">HQ/multi-PC collector-share</h3>
                <p class="muted">For many PCs, HQ, branch, lab, or any site with a practical local share.</p>
                <ul class="spec-list">
                    <li>Flow: Runner -&gt; local/SMB branch share -&gt; Collector -&gt; Laravel.</li>
                    <li>Collector-share remains main HQ/multi-PC mode.</li>
                    <li>Keep collector tokens separate from Direct runner tokens.</li>
                </ul>
            </div>
            <div class="info-tile">
                <h3 class="panel-title">Hybrid</h3>
                <p class="muted">Use collector-share for HQ/branches and Direct HTTPS for small remote sites.</p>
                <ul class="spec-list">
                    <li>Each site/package remains mode-specific.</li>
                    <li>Do not mix Direct HTTPS and collector-share inside one runner install.</li>
                </ul>
            </div>
        </div>
    </section>

    <section class="panel dashboard-section">
        <div class="panel-header">
            <div>
                <h3 class="panel-title">Step 5 - Package / Download Guidance</h3>
                <p class="panel-subtitle">Use existing package and audit flows. This page does not generate site kits or packages.</p>
            </div>
            @if(\Illuminate\Support\Facades\Route::has('admin.downloads.index'))
                <a class="btn" href="{{ route('admin.downloads.index') }}">Downloads</a>
            @endif
        </div>
        <pre>php artisan inventory:direct-site-kit-audit</pre>
        <ul class="spec-list">
            <li>Audit generated Direct HTTPS artifacts before installing more Direct HTTPS runners.</li>
            <li>Official packages/site kits must be generated only on Supermicro.</li>
            <li>Do not generate official packages from IT-ADMIN.</li>
            <li>Do not run build-site-kit from this page.</li>
        </ul>
    </section>

    <section class="panel dashboard-section">
        <div class="panel-header">
            <div>
                <h3 class="panel-title">Step 6 - Install Runner Or Collector</h3>
                <p class="panel-subtitle">Installation remains manual in the MVP.</p>
            </div>
        </div>
        <div class="grid">
            <div class="info-tile">
                <span class="info-label">Direct HTTPS</span>
                <ul class="spec-list compact">
                    <li>Install Direct HTTPS runner package on target PC.</li>
                    <li>Confirm HTTPS endpoint works.</li>
                    <li>Confirm Windows trusts certificate.</li>
                    <li>Confirm runner appears in portal.</li>
                </ul>
            </div>
            <div class="info-tile">
                <span class="info-label">Collector-share</span>
                <ul class="spec-list compact">
                    <li>Prepare branch-share folder.</li>
                    <li>Install collector.</li>
                    <li>Install runners using collector-share package.</li>
                    <li>Confirm collector and runner status in portal.</li>
                </ul>
            </div>
        </div>
    </section>

    <section class="panel dashboard-section">
        <div class="panel-header">
            <div>
                <h3 class="panel-title">Step 7 - Verify From Portal</h3>
                <p class="panel-subtitle">Use existing portal views to verify transport, upload, and command behavior.</p>
            </div>
            <div class="actions">
                @if(\Illuminate\Support\Facades\Route::has('admin.runners.index'))
                    <a class="btn secondary" href="{{ route('admin.runners.index') }}">Runners</a>
                @endif
                @if(\Illuminate\Support\Facades\Route::has('admin.collectors.index'))
                    <a class="btn secondary" href="{{ route('admin.collectors.index') }}">Collectors</a>
                @endif
                @if(\Illuminate\Support\Facades\Route::has('admin.commands.index'))
                    <a class="btn secondary" href="{{ route('admin.commands.index') }}">Command queue</a>
                @endif
                @if(\Illuminate\Support\Facades\Route::has('admin.downloads.index'))
                    <a class="btn secondary" href="{{ route('admin.downloads.index') }}">Downloads</a>
                @endif
            </div>
        </div>
        <div class="grid">
            <div class="info-tile">
                <h3 class="panel-title">Direct HTTPS checklist</h3>
                <ul class="spec-list">
                    <li>Runner appears on runners page.</li>
                    <li>Transport shows Direct HTTPS.</li>
                    <li>Runner version visible.</li>
                    <li>Last heartbeat populated.</li>
                    <li>Direct command poll populated.</li>
                    <li>Last Inventory/upload populated.</li>
                    <li>Manual scan command succeeds.</li>
                    <li>ACK received.</li>
                    <li>Direct repair_update blocked.</li>
                </ul>
            </div>
            <div class="info-tile">
                <h3 class="panel-title">Collector-share checklist</h3>
                <ul class="spec-list">
                    <li>Collector appears on collectors page.</li>
                    <li>Collector status recent.</li>
                    <li>Runner heartbeat relayed.</li>
                    <li>CSV upload/ingest works.</li>
                    <li>Command delivery/ACK works.</li>
                    <li>Collector-share runners are not treated as missing Direct HTTPS poll.</li>
                </ul>
            </div>
            <div class="info-tile">
                <h3 class="panel-title">Safety checklist</h3>
                <ul class="spec-list">
                    <li>No token secrets shown.</li>
                    <li>No full runner GUID shown.</li>
                    <li>No command payload JSON shown.</li>
                    <li>No .env / APP_KEY / DB credential shown.</li>
                    <li>No generated package created from IT-ADMIN.</li>
                </ul>
            </div>
        </div>
    </section>

    <section class="panel dashboard-section">
        <div class="panel-header">
            <div>
                <h3 class="panel-title">Security / Redaction</h3>
                <p class="panel-subtitle">Never paste or expose token secrets, siteToken, collector tokens, Direct runner tokens, bearer tokens, token hashes, DB credentials, Google credentials, raw CSV contents, command payload JSON, .env values, APP_KEY values, full configs, or full runner GUIDs.</p>
            </div>
        </div>
    </section>
@endsection
