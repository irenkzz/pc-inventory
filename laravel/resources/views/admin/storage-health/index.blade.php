@extends('admin.layout')

@php
    $riskClass = fn (string $level): string => match ($level) {
        'healthy' => 'good',
        'watch' => 'info',
        'warning' => 'warn',
        'critical' => 'danger',
        default => '',
    };
    $na = fn (mixed $value, string $label = 'Not available'): string => trim((string) $value) !== '' ? (string) $value : $label;
@endphp

@section('content')
    <section class="dashboard-hero">
        <div>
            <p class="dashboard-kicker">Storage Health</p>
            <h2 class="dashboard-title">Backup Risk Monitor</h2>
            <p class="dashboard-copy">Latest per-disk SMART observations from the Windows runner. TBW is best-effort telemetry; risk level and recommended action are the primary operating signals.</p>
        </div>
    </section>

    <section class="grid" aria-label="Storage risk summary">
        @foreach(['critical', 'warning', 'watch', 'unknown', 'healthy'] as $level)
            <a class="metric" href="{{ route('admin.storage-health.index', ['risk' => $level]) }}">
                <span class="metric-label">{{ ucfirst($level) }}</span>
                <strong>{{ (int) ($counts[$level] ?? 0) }}</strong>
                <p>{{ $level === 'unknown' ? 'SMART unreadable or incomplete' : 'latest drive observation(s)' }}</p>
            </a>
        @endforeach
    </section>

    <section class="panel dashboard-section">
        <div class="panel-header">
            <div>
                <h3 class="panel-title">At-Risk Drives</h3>
                <p class="panel-subtitle">Showing latest observation per disk. Filter: {{ $risk ?: 'all risk levels' }}.</p>
            </div>
            <div class="actions">
                <a class="btn secondary" href="{{ route('admin.storage-health.index') }}">All</a>
                <a class="btn secondary" href="{{ route('admin.storage-health.index', ['risk' => 'critical']) }}">Critical</a>
                <a class="btn secondary" href="{{ route('admin.storage-health.index', ['risk' => 'unknown']) }}">Unknown</a>
            </div>
        </div>
        <div class="table-scroll">
            <table>
                <tr>
                    <th>Device</th>
                    <th>Site / Department</th>
                    <th>Disk</th>
                    <th>Risk</th>
                    <th>Reason</th>
                    <th>TBW</th>
                    <th>Used</th>
                    <th>Hours</th>
                    <th>Temp</th>
                    <th>Last scan</th>
                    <th>Action</th>
                </tr>
                @forelse($observations as $observation)
                    <tr>
                        <td>
                            <a href="{{ route('admin.devices.show', $observation->device) }}">{{ $observation->device->current_asset_code ?: 'Device #' . $observation->device_id }}</a>
                            <div class="muted">{{ $na($observation->device->current_user_name, 'No assigned user') }}</div>
                        </td>
                        <td>
                            {{ $na($observation->device->current_site, 'Unassigned site') }}
                            <div class="muted">{{ $na($observation->device->current_department, 'No department') }}</div>
                        </td>
                        <td>
                            {{ $na($observation->disk_model, 'Unknown disk') }}
                            <div class="muted">{{ $na($observation->disk_serial, 'No serial') }} · {{ $na($observation->disk_type, 'unknown type') }} · {{ $na($observation->capacity_gb) }} GB</div>
                        </td>
                        <td><span class="badge {{ $riskClass($observation->risk_level) }}">{{ $observation->risk_level }}</span></td>
                        <td>{{ implode('; ', $observation->risk_reasons ?? []) }}</td>
                        <td>{{ $observation->tbw_gb === null ? 'Not available' : \App\Support\InventoryFormat::decimal($observation->tbw_gb, 1) . ' GB' }}</td>
                        <td>{{ \App\Support\InventoryFormat::percent($observation->percentage_used) }}</td>
                        <td>{{ \App\Support\InventoryFormat::number($observation->power_on_hours) }}</td>
                        <td>{{ $observation->temperature_c === null ? '-' : $observation->temperature_c . ' C' }}</td>
                        <td>@inventoryTime($observation->observed_at)</td>
                        <td>{{ $observation->recommended_action }}</td>
                    </tr>
                @empty
                    <tr><td colspan="11">No storage health observations have been imported yet.</td></tr>
                @endforelse
            </table>
        </div>
        {{ $observations->links('pagination.admin') }}
    </section>
@endsection
