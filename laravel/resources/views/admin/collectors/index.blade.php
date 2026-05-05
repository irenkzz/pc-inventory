@extends('admin.layout')

@php
    $number = fn (mixed $value): string => \App\Support\InventoryFormat::number($value);

    $statusFor = function (mixed $date, string $healthy = 'Active') use ($freshSince): array {
        if ($date === null || $date === '') {
            return ['danger', 'No heartbeat'];
        }

        return $date->gte($freshSince)
            ? ['good', $healthy]
            : ['warn', 'Stale'];
    };

    $collectorStatusClass = function (mixed $status): string {
        return match (strtolower((string) $status)) {
            '', 'ok', 'success', 'healthy' => 'good',
            'warning', 'warn' => 'warn',
            default => 'danger',
        };
    };

    $commandLabel = fn (?string $type): string => match ($type) {
        'scan_now' => 'Manual scan',
        'repair_update' => 'Repair/update',
        default => $type ?: '-',
    };

    $commandStatusClass = fn (?string $status): string => match ($status) {
        'pending' => 'warn',
        'dispatched' => 'info',
        default => '',
    };
@endphp

@section('content')
    <section class="dashboard-hero">
        <div>
            <p class="dashboard-kicker">Branch relay layer</p>
            <h2 class="dashboard-title">Collector Visibility</h2>
            <p class="dashboard-copy">
                Monitor site collectors, queued runner uploads, branch relay status, linked runners, and active command flow.
            </p>
        </div>
        <div class="dashboard-toolbar">
            <a class="btn secondary" href="{{ route('admin.runners.index') }}">Runners</a>
            <a class="btn" href="{{ route('admin.commands.index') }}">Command history</a>
        </div>
    </section>

    <section class="ops-strip" aria-label="Collector operations health">
        <div class="ops-item">
            <div>
                <span class="ops-label">Sites</span>
                <span class="ops-value">{{ $number($summary['total_sites']) }}</span>
            </div>
            <span class="badge info">Configured</span>
        </div>
        <div class="ops-item">
            <div>
                <span class="ops-label">Collectors</span>
                <span class="ops-value">{{ $number($summary['active_collectors']) }} / {{ $number($summary['total_collectors']) }}</span>
            </div>
            <span class="badge {{ $summary['active_collectors'] === $summary['total_collectors'] && $summary['total_collectors'] > 0 ? 'good' : 'warn' }}">
                <span class="status-dot"></span>{{ $summary['total_collectors'] > 0 ? '24h health' : 'No collectors' }}
            </span>
        </div>
        <div class="ops-item">
            <div>
                <span class="ops-label">Runners</span>
                <span class="ops-value">{{ $number($summary['active_runners']) }} / {{ $number($summary['total_runners']) }}</span>
            </div>
            <span class="badge {{ $summary['active_runners'] === $summary['total_runners'] && $summary['total_runners'] > 0 ? 'good' : 'warn' }}">
                <span class="status-dot"></span>{{ $summary['total_runners'] > 0 ? '24h health' : 'No runners' }}
            </span>
        </div>
        <div class="ops-item">
            <div>
                <span class="ops-label">Queued work</span>
                <span class="ops-value">{{ $number($summary['queued_files']) }}</span>
            </div>
            <span class="badge {{ $summary['active_commands'] > 0 ? 'warn' : 'good' }}">
                {{ $number($summary['active_commands']) }} active command(s)
            </span>
        </div>
    </section>

    <form method="get" class="form-grid">
        <label>
            Search
            <input type="text" name="q" value="{{ $query }}" placeholder="Site, collector, runner, version, share path">
        </label>
        <label>
            Site status
            <select name="status">
                <option value="" @selected($status === '')>All sites</option>
                <option value="attention" @selected($status === 'attention')>Needs attention</option>
                <option value="active" @selected($status === 'active')>Active</option>
            </select>
        </label>
        <div class="form-actions">
            <button type="submit">Filter</button>
            <a class="btn secondary" href="{{ route('admin.collectors.index') }}">Reset</a>
        </div>
    </form>

    <section class="stack dashboard-section">
        @forelse($sites as $site)
            @php
                $siteCommands = $activeCommandsBySite->get($site->site_id, collect());
                $siteBadgeClass = $site->needs_attention ? 'warn' : 'good';
                $siteBadgeLabel = $site->needs_attention ? 'Needs attention' : 'Active';
            @endphp

            <div class="panel">
                <div class="panel-header">
                    <div>
                        <h3 class="panel-title">{{ $site->site_name ?: $site->site_id }}</h3>
                        <p class="panel-subtitle">
                            {{ $site->site_id }} |
                            {{ $number($site->collectors_count) }} collector(s) |
                            {{ $number($site->runners_count) }} runner(s)
                        </p>
                    </div>
                    <span class="badge {{ $siteBadgeClass }}"><span class="status-dot"></span>{{ $siteBadgeLabel }}</span>
                </div>

                <section class="grid" aria-label="Site relay metrics for {{ $site->site_id }}">
                    <div class="info-tile">
                        <span class="info-label">Collectors online</span>
                        <span class="info-value">{{ $number($site->active_collectors_count) }} / {{ $number($site->collectors_count) }}</span>
                        <span class="info-note">Fresh since @inventoryTime($freshSince)</span>
                    </div>
                    <div class="info-tile">
                        <span class="info-label">Runners online</span>
                        <span class="info-value">{{ $number($site->active_runners_count) }} / {{ $number($site->runners_count) }}</span>
                        <span class="info-note">Scheduled task heartbeats</span>
                    </div>
                    <div class="info-tile">
                        <span class="info-label">Collector queue</span>
                        <span class="info-value">{{ $number($site->queue_depth_csv_total + $site->queue_depth_heartbeat_total) }}</span>
                        <span class="info-note">CSV {{ $number($site->queue_depth_csv_total) }} / heartbeat {{ $number($site->queue_depth_heartbeat_total) }}</span>
                    </div>
                    <div class="info-tile">
                        <span class="info-label">Active commands</span>
                        <span class="info-value">{{ $number($siteCommands->count()) }}</span>
                        <span class="info-note">Pending or dispatched</span>
                    </div>
                </section>

                <div class="detail-grid dashboard-section">
                    <div>
                        <div class="panel-header">
                            <div>
                                <h3 class="panel-title">Collectors</h3>
                                <p class="panel-subtitle">Branch relay process status and local queue depth.</p>
                            </div>
                        </div>
                        <div class="table-scroll">
                            <table>
                                <tr>
                                    <th>Collector</th>
                                    <th>Version</th>
                                    <th>Last seen</th>
                                    <th>Status</th>
                                    <th>Queue</th>
                                    <th>Share root</th>
                                </tr>
                                @forelse($site->collectors as $collector)
                                    @php
                                        [$collectorFreshClass, $collectorFreshLabel] = $statusFor($collector->last_seen_at);
                                    @endphp
                                    <tr>
                                        <td>
                                            <strong>{{ $collector->collector_name }}</strong>
                                            <div class="summary-meta">{{ $collector->site_id }}</div>
                                        </td>
                                        <td>{{ $collector->collector_version ?: '-' }}</td>
                                        <td>
                                            @inventoryTime($collector->last_seen_at)
                                            <div><span class="badge {{ $collectorFreshClass }}">{{ $collectorFreshLabel }}</span></div>
                                        </td>
                                        <td>
                                            <span class="badge {{ $collectorStatusClass($collector->last_status) }}">{{ $collector->last_status ?: 'ok' }}</span>
                                            <div class="summary-meta">{{ $collector->last_error ?: '-' }}</div>
                                        </td>
                                        <td>
                                            CSV {{ $number($collector->queue_depth_csv) }}
                                            <div class="summary-meta">Heartbeat {{ $number($collector->queue_depth_heartbeat) }}</div>
                                        </td>
                                        <td>{{ $collector->share_root_hint ?: '-' }}</td>
                                    </tr>
                                @empty
                                    <tr><td colspan="6">No collector has reported for this site.</td></tr>
                                @endforelse
                            </table>
                        </div>
                    </div>

                    <div>
                        <div class="panel-header">
                            <div>
                                <h3 class="panel-title">Linked Runners</h3>
                                <p class="panel-subtitle">Windows clients currently assigned to this site.</p>
                            </div>
                        </div>
                        <div class="table-scroll">
                            <table>
                                <tr>
                                    <th>Runner</th>
                                    <th>Last seen</th>
                                    <th>Inventory</th>
                                    <th>Command</th>
                                </tr>
                                @forelse($site->runners as $runner)
                                    @php
                                        [$runnerFreshClass, $runnerFreshLabel] = $statusFor($runner->last_seen_at);
                                        $activeCommand = $runner->activeCommands->first();
                                    @endphp
                                    <tr>
                                        <td>
                                            <a href="{{ route('admin.runners.show', $runner) }}">{{ $runner->runner_id }}</a>
                                            <div class="summary-meta">{{ $runner->hostname ?: '-' }} | {{ $runner->runner_version ?: '-' }}</div>
                                        </td>
                                        <td>
                                            @inventoryTime($runner->last_seen_at)
                                            <div><span class="badge {{ $runnerFreshClass }}">{{ $runnerFreshLabel }}</span></div>
                                        </td>
                                        <td>
                                            {{ $runner->last_inventory_status ?: '-' }}
                                            <div class="summary-meta">@inventoryTime($runner->last_successful_inventory_at)</div>
                                        </td>
                                        <td>
                                            @if($activeCommand)
                                                <span class="badge {{ $commandStatusClass($activeCommand->status) }}">{{ $commandLabel($activeCommand->command_type) }}</span>
                                                <div class="summary-meta">{{ $activeCommand->status }} since @inventoryTime($activeCommand->requested_at)</div>
                                            @else
                                                <span class="badge good">Clear</span>
                                                <div class="summary-meta">No active command</div>
                                            @endif
                                        </td>
                                    </tr>
                                @empty
                                    <tr><td colspan="4">No runners are linked to this site yet.</td></tr>
                                @endforelse
                            </table>
                        </div>
                    </div>
                </div>

                @if($siteCommands->isNotEmpty())
                    <div class="panel-header">
                        <div>
                            <h3 class="panel-title">Active Commands</h3>
                            <p class="panel-subtitle">Commands waiting for branch relay delivery or runner acknowledgement.</p>
                        </div>
                    </div>
                    <div class="table-scroll">
                        <table>
                            <tr>
                                <th>Requested</th>
                                <th>Runner</th>
                                <th>Type</th>
                                <th>Status</th>
                                <th>Requested by</th>
                            </tr>
                            @foreach($siteCommands as $command)
                                <tr>
                                    <td>@inventoryTime($command->requested_at)</td>
                                    <td>
                                        @if($command->runner)
                                            <a href="{{ route('admin.runners.show', $command->runner) }}">{{ $command->runner_id }}</a>
                                        @else
                                            {{ $command->runner_id }}
                                        @endif
                                    </td>
                                    <td>{{ $commandLabel($command->command_type) }}</td>
                                    <td><span class="badge {{ $commandStatusClass($command->status) }}">{{ $command->status }}</span></td>
                                    <td>{{ $command->requested_by ?: '-' }}</td>
                                </tr>
                            @endforeach
                        </table>
                    </div>
                @endif
            </div>
        @empty
            <div class="empty-state">No collector sites match the current filters.</div>
        @endforelse
    </section>
@endsection
