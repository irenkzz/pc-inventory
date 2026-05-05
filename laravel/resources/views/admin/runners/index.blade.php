@extends('admin.layout')

@php
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

    $number = fn (mixed $value): string => \App\Support\InventoryFormat::number($value);
@endphp

@section('content')
    <section class="dashboard-hero">
        <div>
            <p class="dashboard-kicker">Runner control</p>
            <h2 class="dashboard-title">Runners</h2>
            <p class="dashboard-copy">Monitor scheduled Windows runners and queue one active command per runner.</p>
        </div>
        <div class="dashboard-toolbar">
            <a class="btn secondary" href="{{ route('admin.commands.index') }}">Command history</a>
        </div>
    </section>

    <form method="get" class="actions">
        <input type="text" name="q" value="{{ $query }}" placeholder="Search runner, hostname, site, version">
        <select name="status">
            <option value="">All runners</option>
            <option value="recent" @selected($status === 'recent')>Recent heartbeat</option>
            <option value="stale" @selected($status === 'stale')>Stale heartbeat</option>
            <option value="older-version" @selected($status === 'older-version')>Below {{ $targetVersion }}</option>
            <option value="active-command" @selected($status === 'active-command')>Active command</option>
        </select>
        <button type="submit">Search</button>
        @if($query !== '' || $status !== '')
            <a class="btn secondary" href="{{ route('admin.runners.index') }}">Clear</a>
        @endif
    </form>

    <div class="table-scroll">
        <table>
            <tr>
                <th>Runner</th>
                <th>Site</th>
                <th>Transport</th>
                <th>Version</th>
                <th>Last heartbeat</th>
                <th>Direct command poll</th>
                <th>Last inventory</th>
                <th>Active command</th>
                <th>Health</th>
                <th>Actions</th>
            </tr>
            @forelse($runners as $runner)
                @php
                    $isDirect = (string) $runner->transport_mode === 'direct_https';
                    $transportLabel = $isDirect ? 'Direct HTTPS' : 'Collector Share';
                    $transportClass = $isDirect ? 'info' : '';
                    $lastHeartbeat = $isDirect && $runner->last_direct_heartbeat_at
                        ? $runner->last_direct_heartbeat_at
                        : $runner->last_seen_at;
                    $activeCommand = $runner->activeCommands->first();
                    $hasScan = $runner->activeCommands->contains('command_type', 'scan_now');
                    $hasRepair = $runner->activeCommands->contains('command_type', 'repair_update');
                    $staleDispatchedCommand = $activeCommand
                        && $activeCommand->status === 'dispatched'
                        && $activeCommand->requested_at
                        && $activeCommand->requested_at->lt(now()->subHours(24));
                    $heartbeatMissing = $lastHeartbeat === null;
                    $heartbeatStale = $lastHeartbeat !== null && $lastHeartbeat->lt($freshSince);
                    $directUploadError = trim((string) $runner->last_upload_error) !== '';
                    $lastInventoryAt = $isDirect ? $runner->last_direct_upload_at : $runner->last_successful_inventory_at;
                    $lastInventoryMissingLabel = $isDirect ? 'No direct upload yet' : null;
                    $inventoryStatusSummary = $isDirect
                        ? ($runner->last_direct_upload_at ? ($directUploadError ? 'Upload needs attention' : 'Uploaded') : 'No direct upload yet')
                        : (($runner->last_inventory_status ?: '-') . ' / ' . ($runner->last_upload_status ?: '-'));
                    $directUploadNote = $directUploadError ? trim((string) $runner->last_upload_error) : '';

                    if ($heartbeatMissing) {
                        $healthLabel = 'Waiting for first runner cycle';
                        $healthClass = 'warn';
                    } elseif ($staleDispatchedCommand) {
                        $healthLabel = 'Stale — delivered but no ACK received';
                        $healthClass = 'danger';
                    } elseif ($activeCommand && in_array($activeCommand->status, ['pending', 'dispatched'], true)) {
                        $healthLabel = 'Command awaiting ACK';
                        $healthClass = 'warn';
                    } elseif ($heartbeatStale) {
                        $healthLabel = 'Heartbeat stale';
                        $healthClass = 'warn';
                    } else {
                        $healthLabel = 'Healthy';
                        $healthClass = 'good';
                    }
                @endphp
                <tr>
                    <td>
                        <div class="entity-cell">
                            <span class="entity-avatar" aria-hidden="true">{{ substr(strtoupper(preg_replace('/[^A-Z0-9]/', '', $runner->hostname ?: $runner->runner_id)), 0, 2) }}</span>
                            <span class="entity-main">
                                <a class="entity-title" href="{{ route('admin.runners.show', $runner) }}">{{ $runner->runner_id }}</a>
                                <span class="entity-subtitle">{{ $runner->hostname ?: '-' }}</span>
                            </span>
                        </div>
                    </td>
                    <td>{{ $runner->site?->site_name ?: ($runner->site_id ?: '-') }}</td>
                    <td>
                        <span class="badge {{ $transportClass }}">{{ $transportLabel }}</span>
                    </td>
                    <td>{{ $runner->runner_version ?: 'Version not reported' }}</td>
                    <td>
                        @if($lastHeartbeat)
                            @inventoryTime($lastHeartbeat)
                        @else
                            Waiting for first runner cycle
                        @endif
                    </td>
                    <td>
                        @if($isDirect)
                            @if($runner->last_direct_poll_at)
                                @inventoryTime($runner->last_direct_poll_at)
                            @else
                                No command poll recorded yet
                            @endif
                        @else
                            Not applicable — collector-share mode
                        @endif
                    </td>
                    <td>
                        @if($lastInventoryAt)
                            @inventoryTime($lastInventoryAt)
                        @else
                            {{ $lastInventoryMissingLabel ?? '-' }}
                        @endif
                    </td>
                    <td>
                        @if($activeCommand)
                            <span class="badge {{ $commandStatusClass($activeCommand->status) }}">{{ $commandLabel($activeCommand->command_type) }}</span>
                            <div class="summary-meta">
                                {{ $activeCommand->status }} since {{ \App\Support\InventoryTime::format($activeCommand->requested_at) }}
                            </div>
                            @if($staleDispatchedCommand)
                                <div class="summary-meta">Stale — delivered but no ACK received</div>
                            @endif
                        @else
                            <span class="badge good">Clear</span>
                            <div class="summary-meta">No active command</div>
                        @endif
                        <div class="summary-meta">{{ $number($runner->pending_command_count) }} pending command(s)</div>
                    </td>
                    <td>
                        <span class="badge {{ $healthClass }}"><span class="status-dot"></span>{{ $healthLabel }}</span>
                        <div class="summary-meta">{{ $inventoryStatusSummary }}</div>
                        @if($isDirect && $directUploadNote !== '')
                            <div class="summary-meta">{{ $directUploadNote }}</div>
                        @endif
                    </td>
                    <td class="actions">
                        <form method="post" action="{{ route('admin.runners.manual-scan', $runner) }}">
                            @csrf
                            <button type="submit" @disabled($hasScan)>Manual scan</button>
                        </form>
                        <form method="post" action="{{ route('admin.runners.repair', $runner) }}">
                            @csrf
                            <button class="secondary" type="submit" @disabled($hasRepair)>Repair/update</button>
                        </form>
                    </td>
                </tr>
            @empty
                <tr><td colspan="10">No runners yet.</td></tr>
            @endforelse
        </table>
    </div>
    {{ $runners->links() }}
@endsection
