@extends('admin.layout')

@php
    $number = fn (mixed $value): string => \App\Support\InventoryFormat::number($value);
    $counts = $report['summary']['counts'];
    $latest = $report['summary']['latest'];
    $versions = $report['summary']['runner_versions'];

    $badgeClass = fn (string $status): string => match ($status) {
        'pass' => 'good',
        'warn' => 'warn',
        'fail' => 'danger',
        default => '',
    };

    $overallClass = $report['status'] === 'ok' ? 'good' : 'danger';

    $checkLinks = [
        'Recent collector heartbeat' => route('admin.collectors.index'),
        'Recent runner heartbeat' => route('admin.runners.index', ['status' => 'recent']),
        'Latest raw intake' => route('admin.raw-evidence.index'),
        'Stale runners' => route('admin.runners.index', ['status' => 'stale']),
        'Older runner versions' => route('admin.runners.index', ['status' => 'older-version']),
        'Stuck active commands' => route('admin.commands.index', ['status' => 'active']),
        'Failed commands' => route('admin.commands.index', ['status' => 'attention']),
        'Collector errors' => route('admin.collectors.index'),
        'Latest backup' => route('admin.downloads.index'),
        'Critical storage risk' => route('admin.storage-health.index', ['risk' => 'critical']),
        'Unknown storage telemetry' => route('admin.storage-health.index', ['risk' => 'unknown']),
    ];
@endphp

@section('content')
    <section class="dashboard-hero">
        <div>
            <p class="dashboard-kicker">Rollout control</p>
            <h2 class="dashboard-title">Pilot Readiness</h2>
            <p class="dashboard-copy">
                Operational readiness from collector heartbeat, runner freshness, command flow, inventory intake, backup state, and storage risk.
            </p>
        </div>
        <div class="dashboard-toolbar">
            <span class="badge {{ $overallClass }}"><span class="status-dot"></span>{{ strtoupper($report['status']) }}</span>
            <a class="btn secondary" href="{{ route('admin.commands.index') }}">Commands</a>
            <a class="btn" href="{{ route('admin.runners.index') }}">Runners</a>
        </div>
    </section>

    <section class="ops-strip" aria-label="Pilot readiness summary">
        <div class="ops-item">
            <div>
                <span class="ops-label">Recent runners</span>
                <span class="ops-value">{{ $number($counts['runners_recent']) }} / {{ $number($counts['runners_total']) }}</span>
            </div>
            <span class="badge {{ $counts['runners_stale'] > 0 ? 'warn' : 'good' }}">{{ $number($counts['runners_stale']) }} stale</span>
        </div>
        <div class="ops-item">
            <div>
                <span class="ops-label">Recent collectors</span>
                <span class="ops-value">{{ $number($counts['collectors_recent']) }} / {{ $number($counts['collectors_total']) }}</span>
            </div>
            <span class="badge {{ $counts['collectors_with_errors'] > 0 ? 'warn' : 'good' }}">{{ $number($counts['collectors_with_errors']) }} errors</span>
        </div>
        <div class="ops-item">
            <div>
                <span class="ops-label">Command issues</span>
                <span class="ops-value">{{ $number($counts['commands_stuck']) }}</span>
            </div>
            <span class="badge {{ $counts['commands_failed'] > 0 ? 'warn' : 'good' }}">{{ $number($counts['commands_failed']) }} failed</span>
        </div>
    </section>

    <section class="grid" aria-label="Pilot readiness metrics">
        <div class="metric">
            <div class="metric-top">
                <div>
                    <span class="metric-label">Older runners</span>
                    <strong>{{ $number($counts['runners_below_target_version']) }}</strong>
                </div>
                <span class="metric-icon" aria-hidden="true">V</span>
            </div>
            <p>Runners below {{ $counts['runner_target_version'] }} need repair or reinstall before wider rollout.</p>
        </div>
        <div class="metric">
            <div class="metric-top">
                <div>
                    <span class="metric-label">Raw files</span>
                    <strong>{{ $number($counts['raw_files_total']) }}</strong>
                </div>
                <span class="metric-icon" aria-hidden="true">R</span>
            </div>
            <p>{{ $number($counts['device_scans_total']) }} device scans ingested.</p>
        </div>
        <div class="metric">
            <div class="metric-top">
                <div>
                    <span class="metric-label">Critical storage</span>
                    <strong>{{ $number($counts['storage_critical']) }}</strong>
                </div>
                <span class="metric-icon" aria-hidden="true">H</span>
            </div>
            <p>{{ $number($counts['storage_unknown']) }} drive observations have unknown telemetry.</p>
        </div>
        <div class="metric">
            <div class="metric-top">
                <div>
                    <span class="metric-label">Latest inventory</span>
                    <strong>{{ $latest['successful_inventory_at'] ? \App\Support\InventoryTime::format($latest['successful_inventory_at']) : '-' }}</strong>
                </div>
                <span class="metric-icon" aria-hidden="true">T</span>
            </div>
            <p>Latest raw intake: {{ $latest['raw_file_received_at'] ? \App\Support\InventoryTime::format($latest['raw_file_received_at']) : '-' }}</p>
        </div>
    </section>

    <section class="dashboard-layout dashboard-section">
        <div class="panel">
            <div class="panel-header">
                <div>
                    <h3 class="panel-title">Readiness Checks</h3>
                    <p class="panel-subtitle">Warnings can be acceptable for a small pilot, but failures block readiness.</p>
                </div>
            </div>
            <div class="table-scroll">
                <table>
                    <tr>
                        <th>Status</th>
                        <th>Check</th>
                        <th>Value</th>
                        <th>Meaning</th>
                        <th>Action</th>
                    </tr>
                    @foreach($report['checks'] as $check)
                        <tr>
                            <td><span class="badge {{ $badgeClass($check['status']) }}"><span class="status-dot"></span>{{ strtoupper($check['status']) }}</span></td>
                            <td>{{ $check['name'] }}</td>
                            <td>{{ is_numeric($check['value']) ? $number($check['value']) : $check['value'] }}</td>
                            <td>{{ $check['message'] }}</td>
                            <td>
                                @if(isset($checkLinks[$check['name']]))
                                    <a class="badge info" href="{{ $checkLinks[$check['name']] }}">Open</a>
                                @else
                                    -
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </table>
            </div>
        </div>

        <aside class="stack">
            <div class="panel">
                <div class="panel-header">
                    <div>
                        <h3 class="panel-title">Runner Versions</h3>
                        <p class="panel-subtitle">Version distribution across reporting runners.</p>
                    </div>
                </div>
                @if($versions !== [])
                    <div class="summary-list">
                        @foreach($versions as $version => $count)
                            <div class="summary-row">
                                <div>
                                    <div class="summary-name">{{ $version }}</div>
                                    <div class="summary-meta">{{ version_compare((string) $version, '1.0.15', '<') ? 'Needs update' : 'Pilot-capable or newer' }}</div>
                                </div>
                                <strong>{{ $number($count) }}</strong>
                            </div>
                        @endforeach
                    </div>
                @else
                    <div class="empty-state">No runner versions yet.</div>
                @endif
            </div>

            <div class="panel">
                <div class="panel-header">
                    <div>
                        <h3 class="panel-title">Latest Signals</h3>
                        <p class="panel-subtitle">Newest operational timestamps.</p>
                    </div>
                </div>
                <dl class="compact-dl">
                    <dt>Collector seen</dt>
                    <dd>{{ $latest['collector_seen_at'] ? \App\Support\InventoryTime::format($latest['collector_seen_at']) : '-' }}</dd>
                    <dt>Runner seen</dt>
                    <dd>{{ $latest['runner_seen_at'] ? \App\Support\InventoryTime::format($latest['runner_seen_at']) : '-' }}</dd>
                    <dt>Scan ingested</dt>
                    <dd>{{ $latest['device_scan_ingested_at'] ? \App\Support\InventoryTime::format($latest['device_scan_ingested_at']) : '-' }}</dd>
                    <dt>Backup manifest</dt>
                    <dd>{{ $latest['backup_manifest'] ?: '-' }}</dd>
                </dl>
            </div>
        </aside>
    </section>
@endsection
