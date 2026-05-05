@extends('admin.layout')

@php
    $activeCommand = $runner->activeCommands->first();
    $hasScan = $runner->activeCommands->contains('command_type', 'scan_now');
    $hasRepair = $runner->activeCommands->contains('command_type', 'repair_update');
    $isDirect = (string) $runner->transport_mode === 'direct_https';
    $latestCommand = $runner->commands->first();
    $latestAckCommand = $runner->commands
        ->filter(fn ($command) => $command->acknowledged_at !== null)
        ->sortByDesc('acknowledged_at')
        ->first();
    $pendingCommandCount = $runner->activeCommands->count();
    $staleDispatchedCommand = $runner->activeCommands->first(
        fn ($command): bool => $command->status === 'dispatched'
            && $command->acknowledged_at === null
            && ($command->delivered_to_runner_at ?? $command->dispatched_at ?? $command->requested_at)?->lt(now()->subHours(24))
    );

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
        'failed' => 'danger',
        'superseded' => '',
        default => '',
    };

    $maskedGuid = function (?string $guid): string {
        $guid = trim((string) $guid);

        return $guid === ''
            ? 'Runner GUID not reported'
            : 'Present · ending ' . substr($guid, -4);
    };
@endphp

@section('content')
    <section class="detail-hero">
        <div>
            <p class="dashboard-kicker">Runner control</p>
            <div class="detail-title-row">
                <h2>{{ $runner->runner_id }}</h2>
                <span class="badge {{ $activeCommand ? $commandStatusClass($activeCommand->status) : 'good' }}">
                    {{ $activeCommand ? $commandLabel($activeCommand->command_type) . ' ' . $activeCommand->status : 'Command queue clear' }}
                </span>
            </div>
            <div class="detail-meta">
                <span>Host: {{ $runner->hostname ?: '-' }}</span>
                <span>Site: {{ $runner->site?->site_name ?: ($runner->site_id ?: '-') }}</span>
                <span>Version: {{ $runner->runner_version ?: '-' }}</span>
            </div>
        </div>
        <div class="quick-nav">
            <a class="btn secondary" href="{{ route('admin.runners.index') }}">All runners</a>
            <a class="btn secondary" href="{{ route('admin.commands.index') }}">Commands</a>
        </div>
    </section>

    @if($isDirect)
        <section class="panel dashboard-section">
            <div class="panel-header">
                <div>
                    <h3 class="panel-title">Direct HTTPS Status</h3>
                    <p class="panel-subtitle">Direct command polling delivers work to the runner; ACK records the runner result.</p>
                </div>
                <span class="badge info">Direct HTTPS</span>
            </div>
            <div class="info-grid">
                <div class="info-tile">
                    <span class="info-label">Transport mode</span>
                    <span class="info-value">Direct HTTPS</span>
                    <span class="info-note">scan_now/manual_scan supported</span>
                </div>
                <div class="info-tile">
                    <span class="info-label">Site</span>
                    <span class="info-value">{{ $runner->site?->site_name ?: ($runner->site_id ?: '-') }}</span>
                    <span class="info-note">{{ $runner->site_id ?: '-' }}</span>
                </div>
                <div class="info-tile">
                    <span class="info-label">Runner</span>
                    <span class="info-value">{{ $runner->runner_id }}</span>
                    <span class="info-note">{{ $runner->hostname ?: '-' }}</span>
                </div>
                <div class="info-tile">
                    <span class="info-label">Runner GUID</span>
                    <span class="info-value">{{ $maskedGuid($runner->runner_guid) }}</span>
                </div>
                <div class="info-tile">
                    <span class="info-label">Runner version</span>
                    <span class="info-value">{{ $runner->runner_version ?: 'Version not reported' }}</span>
                </div>
                <div class="info-tile">
                    <span class="info-label">Last heartbeat</span>
                    <span class="info-value">
                        @if($runner->last_direct_heartbeat_at ?: $runner->last_seen_at)
                            @inventoryTime($runner->last_direct_heartbeat_at ?: $runner->last_seen_at)
                        @else
                            Waiting for first runner cycle
                        @endif
                    </span>
                </div>
                <div class="info-tile">
                    <span class="info-label">Last direct command poll</span>
                    <span class="info-value">
                        @if($runner->last_direct_poll_at)
                            @inventoryTime($runner->last_direct_poll_at)
                        @else
                            No command poll recorded yet
                        @endif
                    </span>
                </div>
                <div class="info-tile">
                    <span class="info-label">Last command ACK</span>
                    <span class="info-value">
                        @if($latestAckCommand)
                            @inventoryTime($latestAckCommand->acknowledged_at)
                        @else
                            No command ACK yet
                        @endif
                    </span>
                    <span class="info-note">{{ $latestAckCommand ? $commandLabel($latestAckCommand->command_type) . ' ' . $latestAckCommand->status : '' }}</span>
                </div>
                <div class="info-tile">
                    <span class="info-label">Latest command state</span>
                    @if($latestCommand)
                        <span class="info-value">{{ $commandLabel($latestCommand->command_type) }}</span>
                        <span class="info-note">{{ $latestCommand->status }} since @inventoryTime($latestCommand->requested_at)</span>
                    @else
                        <span class="info-value">No direct commands yet</span>
                    @endif
                </div>
                <div class="info-tile">
                    <span class="info-label">Pending commands</span>
                    <span class="info-value">{{ \App\Support\InventoryFormat::number($pendingCommandCount) }}</span>
                    @if($staleDispatchedCommand)
                        <span class="info-note">Stale — delivered but no ACK received</span>
                    @else
                        <span class="info-note">{{ $pendingCommandCount > 0 ? 'Command awaiting ACK' : 'No active command' }}</span>
                    @endif
                </div>
                <div class="info-tile">
                    <span class="info-label">Direct command support</span>
                    <span class="info-value">scan_now/manual_scan supported</span>
                    <span class="info-note">repair_update blocked for Direct HTTPS MVP</span>
                </div>
            </div>
        </section>
    @endif

    <section class="detail-grid">
        <div class="panel">
            <div class="panel-header">
                <div>
                    <h3 class="panel-title">Command Actions</h3>
                    <p class="panel-subtitle">Only one active command file is supported per runner. A different command supersedes the current active command.</p>
                </div>
            </div>
            <div class="actions">
                <form method="post" action="{{ route('admin.runners.manual-scan', $runner) }}">
                    @csrf
                    <button type="submit" @disabled($hasScan)>Queue manual scan</button>
                </form>
                <form method="post" action="{{ route('admin.runners.repair', $runner) }}">
                    @csrf
                    <button class="secondary" type="submit" @disabled($hasRepair)>Queue repair/update</button>
                </form>
            </div>
            @if($activeCommand)
                <div class="notice" style="margin-top:14px">
                    Active command #{{ $activeCommand->id }}:
                    {{ $commandLabel($activeCommand->command_type) }}
                    is {{ $activeCommand->status }} since {{ \App\Support\InventoryTime::format($activeCommand->requested_at) }}.
                </div>
            @endif
        </div>

        <div class="panel">
            <div class="panel-header">
                <div>
                    <h3 class="panel-title">Runner Health</h3>
                    <p class="panel-subtitle">Latest heartbeat and inventory status reported by the runner.</p>
                </div>
            </div>
            <div class="info-grid">
                <div class="info-tile">
                    <span class="info-label">Last seen</span>
                    <span class="info-value">@inventoryTime($runner->last_seen_at)</span>
                </div>
                <div class="info-tile">
                    <span class="info-label">Last inventory</span>
                    <span class="info-value">@inventoryTime($runner->last_successful_inventory_at)</span>
                </div>
                <div class="info-tile">
                    <span class="info-label">Inventory status</span>
                    <span class="info-value">{{ $runner->last_inventory_status ?: '-' }}</span>
                    <span class="info-note">{{ $runner->last_upload_status ?: '-' }}</span>
                </div>
                <div class="info-tile">
                    <span class="info-label">Last command seen</span>
                    <span class="info-value">@inventoryTime($runner->last_command_seen_at)</span>
                    <span class="info-note">{{ $runner->last_command_type ?: '-' }}</span>
                </div>
            </div>
        </div>
    </section>

    <section class="panel dashboard-section">
        <div class="panel-header">
            <div>
                <h3 class="panel-title">Runner Details</h3>
                <p class="panel-subtitle">Raw runner status fields stored by the central portal.</p>
            </div>
        </div>
        <dl class="compact-dl">
            @foreach($runner->getAttributes() as $key => $value)
                <dt>{{ $key }}</dt>
                <dd>
                    @if(in_array($key, ['last_seen_at', 'last_direct_heartbeat_at', 'last_direct_upload_at', 'last_direct_poll_at', 'last_successful_inventory_at', 'last_command_seen_at', 'created_at', 'updated_at'], true))
                        @inventoryTime($value)
                    @elseif($key === 'runner_guid')
                        {{ $maskedGuid($value) }}
                    @elseif($key === 'site_id')
                        {{ $runner->site?->site_name ?: ($value ?: '-') }}
                    @else
                        {{ is_array($value) ? json_encode($value) : ($value ?: '-') }}
                    @endif
                </dd>
            @endforeach
        </dl>
    </section>

    <section class="panel dashboard-section">
        <div class="panel-header">
            <div>
                <h3 class="panel-title">Command History</h3>
                <p class="panel-subtitle">Latest command requests, dispatch state, and completion messages.</p>
            </div>
        </div>
        <div class="table-scroll">
            <table>
                <tr><th>Requested</th><th>Type</th><th>Status</th><th>Requested by</th><th>Dispatch</th><th>Completion</th><th>Message</th></tr>
                @forelse($runner->commands as $command)
                    <tr>
                        <td>@inventoryTime($command->requested_at)</td>
                        <td>{{ $commandLabel($command->command_type) }}</td>
                        <td><span class="badge {{ $commandStatusClass($command->status) }}">{{ $command->status }}</span></td>
                        <td>{{ $command->requested_by }}</td>
                        <td>@inventoryTime($command->dispatched_at)</td>
                        <td>@inventoryTime($command->completed_at)</td>
                        <td>{{ trim((string) $command->completion_message) !== '' ? $command->completion_message : '-' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="7">No command history yet.</td></tr>
                @endforelse
            </table>
        </div>
    </section>
@endsection
