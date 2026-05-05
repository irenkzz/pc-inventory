@extends('admin.layout')

@php
    $number = fn (mixed $value): string => \App\Support\InventoryFormat::number($value);

    $commandLabel = fn (?string $type): string => match ($type) {
        'scan_now' => 'Manual scan',
        'repair_update' => 'Repair/update',
        default => $type ?: '-',
    };

    $commandStatusClass = fn (?string $status): string => match ($status) {
        'pending' => 'warn',
        'dispatched' => 'info',
        'completed' => 'good',
        'success' => 'good',
        'succeeded' => 'good',
        'failed' => 'danger',
        'superseded' => 'warn',
        default => '',
    };

    $completionClass = fn (?string $status): string => match (strtolower((string) $status)) {
        'success', 'completed', 'ok' => 'good',
        'failed', 'error' => 'danger',
        'superseded' => 'warn',
        default => '',
    };

    $isDirectCommand = fn ($command): bool => (string) ($command->runner?->transport_mode ?? '') === 'direct_https';
    $isStaleDispatched = fn ($command): bool => $command->status === 'dispatched'
        && $command->acknowledged_at === null
        && ($command->delivered_to_runner_at ?? $command->dispatched_at ?? $command->requested_at)?->lt(now()->subHours(24));

    $transportLabel = fn ($command): string => $isDirectCommand($command) ? 'Direct HTTPS' : 'Collector Share';
    $transportClass = fn ($command): string => $isDirectCommand($command) ? 'info' : '';

    $displayStatusLabel = function ($command) use ($isDirectCommand, $isStaleDispatched): string {
        if (! $isDirectCommand($command)) {
            return (string) $command->status;
        }

        if ($command->command_type === 'repair_update') {
            return 'Blocked — not supported for Direct HTTPS MVP';
        }

        if ($isStaleDispatched($command)) {
            return 'Stale — delivered but no ACK received';
        }

        return match ((string) $command->status) {
            'pending' => 'Queued',
            'dispatched' => 'Delivered to runner',
            'completed', 'success', 'succeeded' => 'Succeeded',
            'failed' => 'Failed',
            default => (string) $command->status,
        };
    };

    $displayStatusClass = function ($command) use ($isDirectCommand, $isStaleDispatched, $commandStatusClass): string {
        if ($isDirectCommand($command) && ($command->command_type === 'repair_update' || $isStaleDispatched($command))) {
            return 'danger';
        }

        return $commandStatusClass($command->status);
    };

    $displayCompletionLabel = function ($command) use ($isDirectCommand): ?string {
        $completion = strtolower((string) ($command->completion_status ?: $command->status));

        if (! $isDirectCommand($command)) {
            return $command->completion_status;
        }

        return match ($completion) {
            'completed', 'success', 'succeeded' => 'Succeeded',
            'failed', 'error' => 'Failed',
            default => $command->completion_status,
        };
    };
@endphp

@section('content')
    <section class="dashboard-hero">
        <div>
            <p class="dashboard-kicker">Runner command queue</p>
            <h2 class="dashboard-title">Commands</h2>
            <p class="dashboard-copy">
                Track manual scans, repair requests, branch relay dispatch, runner acknowledgement, and completed command outcomes.
            </p>
        </div>
        <div class="dashboard-toolbar">
            <a class="btn secondary" href="{{ route('admin.runners.index') }}">Runners</a>
            <a class="btn" href="{{ route('admin.collectors.index') }}">Collectors</a>
        </div>
    </section>

    <section class="ops-strip" aria-label="Runner command queue health">
        <div class="ops-item">
            <div>
                <span class="ops-label">Total commands</span>
                <span class="ops-value">{{ $number($summary['total']) }}</span>
            </div>
            <span class="badge info">History</span>
        </div>
        <div class="ops-item">
            <div>
                <span class="ops-label">Pending dispatch</span>
                <span class="ops-value">{{ $number($summary['pending']) }}</span>
            </div>
            <span class="badge {{ $summary['pending'] > 0 ? 'warn' : 'good' }}">
                <span class="status-dot"></span>{{ $summary['pending'] > 0 ? 'Waiting' : 'Clear' }}
            </span>
        </div>
        <div class="ops-item">
            <div>
                <span class="ops-label">Dispatched</span>
                <span class="ops-value">{{ $number($summary['dispatched']) }}</span>
            </div>
            <span class="badge {{ $summary['stale_dispatched'] > 0 ? 'danger' : ($summary['dispatched'] > 0 ? 'info' : 'good') }}">
                {{ $number($summary['stale_dispatched']) }} stale
            </span>
        </div>
        <div class="ops-item">
            <div>
                <span class="ops-label">Completed / failed</span>
                <span class="ops-value">{{ $number($summary['completed']) }} / {{ $number($summary['failed']) }}</span>
            </div>
            <span class="badge {{ $summary['failed'] > 0 ? 'danger' : 'good' }}">{{ $number($summary['superseded']) }} superseded</span>
        </div>
    </section>

    <form method="get" class="form-grid">
        <label>
            Search
            <input type="text" name="q" value="{{ $query }}" placeholder="Runner, hostname, site, status, message">
        </label>
        <label>
            Status
            <select name="status">
                <option value="" @selected($status === '')>All statuses</option>
                <option value="active" @selected($status === 'active')>Active queue</option>
                <option value="attention" @selected($status === 'attention')>Needs attention</option>
                <option value="pending" @selected($status === 'pending')>Pending</option>
                <option value="dispatched" @selected($status === 'dispatched')>Dispatched</option>
                <option value="completed" @selected($status === 'completed')>Completed</option>
                <option value="failed" @selected($status === 'failed')>Failed</option>
                <option value="superseded" @selected($status === 'superseded')>Superseded</option>
            </select>
        </label>
        <label>
            Type
            <select name="type">
                <option value="" @selected($type === '')>All types</option>
                @foreach($commandTypes as $commandType)
                    <option value="{{ $commandType }}" @selected($type === $commandType)>{{ $commandLabel($commandType) }}</option>
                @endforeach
            </select>
        </label>
        <label>
            Site
            <select name="site_id">
                <option value="" @selected($siteId === '')>All sites</option>
                @foreach($sites as $site)
                    <option value="{{ $site->site_id }}" @selected($siteId === $site->site_id)>
                        {{ $site->site_name ?: $site->site_id }} ({{ $site->site_id }})
                    </option>
                @endforeach
            </select>
        </label>
        <div class="form-actions">
            <button type="submit">Filter</button>
            <a class="btn secondary" href="{{ route('admin.commands.index') }}">Reset</a>
        </div>
    </form>

    <div class="panel dashboard-section">
        <div class="panel-header">
            <div>
                <h3 class="panel-title">Command History</h3>
                <p class="panel-subtitle">Newest command requests first, including dispatch and completion timestamps.</p>
            </div>
            <span class="badge info">{{ $number($commands->total()) }} command(s)</span>
        </div>

        <div class="table-scroll">
            <table>
                <tr>
                    <th>Requested</th>
                    <th>Runner</th>
                    <th>Site</th>
                    <th>Transport</th>
                    <th>Type</th>
                    <th>Status</th>
                    <th>Dispatch / Ack</th>
                    <th>Completion</th>
                </tr>
                @forelse($commands as $command)
                    <tr>
                        <td>
                            @inventoryTime($command->requested_at)
                            <div class="summary-meta">{{ $command->requested_by ?: '-' }}</div>
                        </td>
                        <td>
                            @if($command->runner)
                                <a href="{{ route('admin.runners.show', $command->runner) }}">{{ $command->runner_id }}</a>
                                <div class="summary-meta">{{ $command->runner->hostname ?: '-' }} | {{ $command->runner->runner_version ?: '-' }}</div>
                            @else
                                {{ $command->runner_id }}
                                <div class="summary-meta">Runner record missing</div>
                            @endif
                        </td>
                        <td>
                            {{ $command->site?->site_name ?: ($command->site_id ?: '-') }}
                            <div class="summary-meta">{{ $command->site_id ?: '-' }}</div>
                        </td>
                        <td><span class="badge {{ $transportClass($command) }}">{{ $transportLabel($command) }}</span></td>
                        <td>{{ $commandLabel($command->command_type) }}</td>
                        <td>
                            <span class="badge {{ $displayStatusClass($command) }}">{{ $displayStatusLabel($command) }}</span>
                            @if($isDirectCommand($command))
                                <div class="summary-meta">Stored state: {{ $command->status }}</div>
                                @if($command->status === 'dispatched' && ! $isStaleDispatched($command))
                                    <div class="summary-meta">Awaiting ACK</div>
                                @endif
                            @elseif($command->status === 'dispatched' && $command->requested_at && $command->requested_at->lt(now()->subHours(24)))
                                <div class="summary-meta">Dispatched more than 24h ago</div>
                            @endif
                        </td>
                        <td>
                            @if($isDirectCommand($command))
                                <div>Delivery: @inventoryTime($command->delivered_to_runner_at ?: $command->dispatched_at)</div>
                                <div class="summary-meta">
                                    ACK:
                                    @if($command->acknowledged_at)
                                        @inventoryTime($command->acknowledged_at)
                                    @else
                                        Awaiting ACK
                                    @endif
                                </div>
                            @else
                                <div>Dispatched: @inventoryTime($command->dispatched_at)</div>
                                <div class="summary-meta">Ack: @inventoryTime($command->acknowledged_at)</div>
                            @endif
                        </td>
                        <td>
                            @php($completionLabel = $displayCompletionLabel($command))
                            @if($completionLabel)
                                <span class="badge {{ $completionClass($command->completion_status ?: $command->status) }}">{{ $completionLabel }}</span>
                            @else
                                <span class="badge">-</span>
                            @endif
                            <div class="summary-meta">@inventoryTime($command->completed_at)</div>
                            @if($command->completion_message)
                                <div class="summary-meta">{{ $command->completion_message }}</div>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="8">No commands match the current filters.</td></tr>
                @endforelse
            </table>
        </div>

        {{ $commands->links() }}
    </div>
@endsection
