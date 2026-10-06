@extends('admin.layout')

@php
    $number = fn (mixed $value): string => \App\Support\InventoryFormat::number($value);
    $freshSince = now()->subHours(24);
    $siteMax = max(1, (int) $siteSummaries->max('device_count'));

    $statusFor = function (mixed $date, string $healthy = 'Online') use ($freshSince): array {
        if ($date === null || $date === '') {
            return ['danger', 'No heartbeat'];
        }

        return $date->gte($freshSince)
            ? ['good', $healthy]
            : ['warn', 'Needs attention'];
    };

    $severityClass = function (mixed $severity): string {
        return match (strtolower((string) $severity)) {
            'critical' => 'danger',
            'medium' => 'warn',
            'minor' => 'info',
            default => '',
        };
    };
    $riskClass = fn (mixed $level): string => match (strtolower((string) $level)) {
        'critical' => 'danger',
        'warning' => 'warn',
        'watch' => 'info',
        'healthy' => 'good',
        default => '',
    };

    $initials = function (mixed $value): string {
        $clean = preg_replace('/[^A-Z0-9]/', '', strtoupper((string) $value));

        return substr($clean !== '' ? $clean : 'PC', 0, 2);
    };

    $runnerHealthClass = $counts['total_runners'] === 0
        ? 'info'
        : ($counts['runner_attention'] > 0 ? 'warn' : 'good');
    $runnerHealthLabel = $counts['total_runners'] === 0
        ? 'No runners'
        : ($counts['runner_attention'] > 0 ? $number($counts['runner_attention']) . ' need attention' : 'Healthy');

    $collectorHealthClass = $counts['total_collectors'] === 0
        ? 'info'
        : ($counts['collector_attention'] > 0 ? 'warn' : 'good');
    $collectorHealthLabel = $counts['total_collectors'] === 0
        ? 'No collectors'
        : ($counts['collector_attention'] > 0 ? $number($counts['collector_attention']) . ' need attention' : 'Healthy');
@endphp

@section('content')
    <section class="dashboard-hero">
        <div>
            <p class="dashboard-kicker">Inventory control plane</p>
            <h2 class="dashboard-title">Dashboard</h2>
            <p class="dashboard-copy">
                Current device coverage, scan freshness, runner health, collector visibility, and audit changes from the database source of truth.
            </p>
        </div>
        <div class="dashboard-toolbar">
            <a class="btn secondary" href="{{ route('admin.runners.index') }}">Runners</a>
            <a class="btn" href="{{ route('admin.reports.index') }}">Reports</a>
        </div>
    </section>

    <section class="ops-strip" aria-label="Operations health">
        <div class="ops-item">
            <div>
                <span class="ops-label">Runner health</span>
                <span class="ops-value">{{ $number($counts['active_runners']) }} / {{ $number($counts['total_runners']) }}</span>
            </div>
            <span class="badge {{ $runnerHealthClass }}"><span class="status-dot"></span>{{ $runnerHealthLabel }}</span>
        </div>
        <div class="ops-item">
            <div>
                <span class="ops-label">Collector health</span>
                <span class="ops-value">{{ $number($counts['active_collectors']) }} / {{ $number($counts['total_collectors']) }}</span>
            </div>
            <span class="badge {{ $collectorHealthClass }}"><span class="status-dot"></span>{{ $collectorHealthLabel }}</span>
        </div>
        <div class="ops-item">
            <div>
                <span class="ops-label">Command queue</span>
                <span class="ops-value">{{ $number($counts['pending_commands']) }}</span>
            </div>
            <span class="badge {{ $counts['pending_commands'] > 0 ? 'warn' : 'good' }}"><span class="status-dot"></span>{{ $counts['pending_commands'] > 0 ? 'Pending' : 'Clear' }}</span>
        </div>
    </section>

    <section class="grid" aria-label="Inventory metrics">
        <div class="metric">
            <div class="metric-top">
                <div>
                    <span class="metric-label">Devices</span>
                    <strong>{{ $number($counts['total_devices']) }}</strong>
                </div>
                <span class="metric-icon" aria-hidden="true">D</span>
            </div>
            <p>{{ $number($counts['active_assignments']) }} active assignments tracked.</p>
        </div>
        <div class="metric">
            <div class="metric-top">
                <div>
                    <span class="metric-label">Scans</span>
                    <strong>{{ $number($counts['total_scans']) }}</strong>
                </div>
                <span class="metric-icon" aria-hidden="true">S</span>
            </div>
            <p>{{ $number($counts['scans_today']) }} scans recorded today.</p>
        </div>
        <div class="metric">
            <div class="metric-top">
                <div>
                    <span class="metric-label">Changes</span>
                    <strong>{{ $number($counts['total_changes']) }}</strong>
                </div>
                <span class="metric-icon" aria-hidden="true">C</span>
            </div>
            <p>{{ $number($counts['critical_changes_7d']) }} critical changes in the last 7 days.</p>
        </div>
        <div class="metric">
            <div class="metric-top">
                <div>
                    <span class="metric-label">Storage risk</span>
                    <strong>{{ $number($counts['storage_critical']) }}</strong>
                </div>
                <span class="metric-icon" aria-hidden="true">H</span>
            </div>
            <p>{{ $number($counts['storage_unknown']) }} drive(s) have unreadable or incomplete SMART data.</p>
        </div>
        <div class="metric">
            <div class="metric-top">
                <div>
                    <span class="metric-label">Latest inventory</span>
                    <strong>{{ $latestScanAt ? \App\Support\InventoryTime::format($latestScanAt) : '-' }}</strong>
                </div>
                <span class="metric-icon" aria-hidden="true">T</span>
            </div>
            <p>{{ $number($counts['changes_7d']) }} total changes observed in the last 7 days.</p>
        </div>
    </section>

    <section class="dashboard-layout dashboard-section">
        <div class="stack">
            <div class="panel">
                <div class="panel-header">
                    <div>
                        <h3 class="panel-title">Recent Runner Health</h3>
                        <p class="panel-subtitle">Latest heartbeats from scheduled Windows runners.</p>
                    </div>
                    <a class="badge info" href="{{ route('admin.runners.index') }}">View all</a>
                </div>
                <div class="table-scroll">
                    <table>
                        <tr>
                            <th>Runner</th>
                            <th>Site</th>
                            <th>Version</th>
                            <th>Last seen</th>
                            <th>Last inventory</th>
                            <th>Status</th>
                        </tr>
                        @forelse($recentRunners as $runner)
                            @php([$statusClass, $statusLabel] = $statusFor($runner->last_seen_at))
                            <tr>
                                <td>
                                    <div class="entity-cell">
                                        <span class="entity-avatar" aria-hidden="true">{{ $initials($runner->hostname ?: $runner->runner_id) }}</span>
                                        <span class="entity-main">
                                            <a class="entity-title" href="{{ route('admin.runners.show', $runner) }}">{{ $runner->runner_id }}</a>
                                            <span class="entity-subtitle">{{ $runner->hostname ?: '-' }}</span>
                                        </span>
                                    </div>
                                </td>
                                <td>{{ $runner->site?->site_name ?: ($runner->site_id ?: '-') }}</td>
                                <td>{{ $runner->runner_version ?: '-' }}</td>
                                <td>@inventoryTime($runner->last_seen_at)</td>
                                <td>@inventoryTime(($runner->transport_mode === 'direct_https' && $runner->last_direct_upload_at) ? $runner->last_direct_upload_at : $runner->last_successful_inventory_at)</td>
                                <td>
                                    <span class="badge {{ $statusClass }}"><span class="status-dot"></span>{{ $statusLabel }}</span>
                                    <div class="summary-meta">{{ $runner->last_inventory_status ?: ($runner->last_direct_upload_at && $runner->transport_mode === 'direct_https' ? 'success' : '-') }} / {{ $runner->last_upload_status ?: ($runner->last_direct_upload_at && $runner->transport_mode === 'direct_https' ? 'uploaded' : '-') }}</div>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="6">No runners yet.</td></tr>
                        @endforelse
                    </table>
                </div>
            </div>

            <div class="panel">
                <div class="panel-header">
                    <div>
                        <h3 class="panel-title">Recent Scans</h3>
                        <p class="panel-subtitle">Newest raw evidence imported into current inventory state.</p>
                    </div>
                    <a class="badge info" href="{{ route('admin.devices.index') }}">Devices</a>
                </div>
                <div class="table-scroll">
                    <table>
                        <tr>
                            <th>Time</th>
                            <th>Asset</th>
                            <th>User</th>
                            <th>Location</th>
                            <th>Site</th>
                            <th>Source</th>
                        </tr>
                        @forelse($recentScans as $scan)
                            <tr>
                                <td>@inventoryTime($scan->scan_time)</td>
                                <td>{{ $scan->device?->current_asset_code ?: 'Device #' . $scan->device_id }}</td>
                                <td>{{ $scan->device?->current_user_name ?: '-' }}</td>
                                <td>{{ trim(($scan->device?->current_location ?? '') . ' ' . ($scan->device?->current_room ?? '')) ?: '-' }}</td>
                                <td>{{ $scan->device?->current_site ?: '-' }}</td>
                                <td><span class="badge">{{ $scan->scan_source ?: '-' }}</span></td>
                            </tr>
                        @empty
                            <tr><td colspan="6">No scans yet.</td></tr>
                        @endforelse
                    </table>
                </div>
            </div>
        </div>

        <aside class="stack">
            <div class="panel">
                <div class="panel-header">
                    <div>
                        <h3 class="panel-title">Site Coverage</h3>
                        <p class="panel-subtitle">Device distribution by current site.</p>
                    </div>
                </div>
                @if($siteSummaries->isNotEmpty())
                    <div class="summary-list">
                        @foreach($siteSummaries as $site)
                            <div class="summary-row">
                                <div>
                                    <div class="summary-name">{{ $site->site_name }}</div>
                                    <div class="progress-track">
                                        <div class="progress-fill" style="width: {{ min(100, round(((int) $site->device_count / $siteMax) * 100)) }}%"></div>
                                    </div>
                                </div>
                                <strong>{{ $number($site->device_count) }}</strong>
                            </div>
                        @endforeach
                    </div>
                @else
                    <div class="empty-state">No site data yet.</div>
                @endif
            </div>

            <div class="panel">
                <div class="panel-header">
                    <div>
                        <h3 class="panel-title">Collector Visibility</h3>
                        <p class="panel-subtitle">Branch relays and queued files.</p>
                    </div>
                    <a class="badge info" href="{{ route('admin.collectors.index') }}">View all</a>
                </div>
                @if($recentCollectors->isNotEmpty())
                    <div class="summary-list">
                        @foreach($recentCollectors as $collector)
                            @php([$statusClass, $statusLabel] = $statusFor($collector->last_seen_at))
                            <div class="summary-row">
                                <div>
                                    <div class="summary-name">{{ $collector->site?->site_name ?: ($collector->site_id ?: 'Unassigned site') }}</div>
                                    <div class="summary-meta">
                                        {{ $collector->collector_name ?: '-' }} |
                                        CSV {{ $collector->queue_depth_csv ?? 0 }} |
                                        Heartbeat {{ $collector->queue_depth_heartbeat ?? 0 }}
                                    </div>
                                </div>
                                <span class="badge {{ $statusClass }}"><span class="status-dot"></span>{{ $statusLabel }}</span>
                            </div>
                        @endforeach
                    </div>
                @else
                    <div class="empty-state">No collectors yet.</div>
                @endif
            </div>

            <div class="panel">
                <div class="panel-header">
                    <div>
                        <h3 class="panel-title">Storage Backup Risk</h3>
                        <p class="panel-subtitle">Critical, warning, and unknown drive health observations.</p>
                    </div>
                    <a class="badge info" href="{{ route('admin.storage-health.index') }}">View all</a>
                </div>
                @if($storageRisks->isNotEmpty())
                    <div class="summary-list">
                        @foreach($storageRisks as $observation)
                            <div class="summary-row">
                                <div>
                                    <div class="summary-name">{{ $observation->device?->current_asset_code ?: 'Device #' . $observation->device_id }}</div>
                                    <div class="summary-meta">
                                        {{ $observation->disk_model ?: 'Unknown disk' }} |
                                        {{ $observation->recommended_action }}
                                    </div>
                                </div>
                                <span class="badge {{ $riskClass($observation->risk_level) }}">{{ $observation->risk_level }}</span>
                            </div>
                        @endforeach
                    </div>
                @else
                    <div class="empty-state">No at-risk storage observations yet.</div>
                @endif
            </div>

            <div class="panel">
                <div class="panel-header">
                    <div>
                        <h3 class="panel-title">Recent Change Audit</h3>
                        <p class="panel-subtitle">Latest hardware, assignment, and location changes.</p>
                    </div>
                    <a class="badge info" href="{{ route('admin.changes.index') }}">View all</a>
                </div>
                @if($recentChanges->isNotEmpty())
                    <div class="summary-list">
                        @foreach($recentChanges as $change)
                            <div class="summary-row">
                                <div>
                                    <div class="summary-name">{{ $change->device?->current_asset_code ?: 'Device #' . $change->device_id }}</div>
                                    <div class="summary-meta">
                                        {{ $change->change_group }} / {{ $change->field_name }} |
                                        @inventoryTime($change->observed_at)
                                    </div>
                                </div>
                                <span class="badge {{ $severityClass($change->severity) }}">{{ $change->severity ?: 'Change' }}</span>
                            </div>
                        @endforeach
                    </div>
                @else
                    <div class="empty-state">No changes yet.</div>
                @endif
            </div>
        </aside>
    </section>
@endsection
