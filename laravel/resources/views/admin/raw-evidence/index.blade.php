@extends('admin.layout')

@php
    $number = fn (mixed $value): string => \App\Support\InventoryFormat::number($value);
    $shortHash = fn (?string $hash): string => $hash ? substr($hash, 0, 12) : '-';
    $metadataValue = function ($rawFile, string $key): string {
        return trim((string) data_get($rawFile->metadata_json ?? [], $key, ''));
    };
@endphp

@section('content')
    <section class="dashboard-hero">
        <div>
            <p class="dashboard-kicker">Raw snapshot archive</p>
            <h2 class="dashboard-title">Raw Evidence</h2>
            <p class="dashboard-copy">
                Trace imported scans back to archived raw files, hashes, source metadata, and linked devices.
            </p>
        </div>
        <div class="dashboard-toolbar">
            <a class="btn secondary" href="{{ route('admin.devices.index') }}">Devices</a>
            <a class="btn" href="{{ route('admin.reports.index') }}">Reports</a>
        </div>
    </section>

    <section class="grid" aria-label="Raw evidence summary">
        <div class="metric">
            <span class="metric-label">Raw files</span>
            <strong>{{ $number($summary['total_raw_files']) }}</strong>
            <p>Archived evidence records.</p>
        </div>
        <div class="metric">
            <span class="metric-label">Linked to scans</span>
            <strong>{{ $number($summary['linked_raw_files']) }}</strong>
            <p>Raw files with imported scan records.</p>
        </div>
        <div class="metric">
            <span class="metric-label">Unlinked</span>
            <strong>{{ $number($summary['unlinked_raw_files']) }}</strong>
            <p>Evidence records without a scan linkage.</p>
        </div>
        <div class="metric">
            <span class="metric-label">Latest received</span>
            <strong>{{ $summary['latest_received_at'] ? \App\Support\InventoryTime::format($summary['latest_received_at']) : '-' }}</strong>
            <p>Latest scan time: {{ $summary['latest_scan_at'] ? \App\Support\InventoryTime::format($summary['latest_scan_at']) : '-' }}</p>
        </div>
    </section>

    <form method="get" class="form-grid">
        <label>
            Search
            <input type="text" name="q" value="{{ $query }}" placeholder="Filename, hash, runner, site, asset, user">
        </label>
        <label>
            Link status
            <select name="status">
                <option value="" @selected($status === '')>All</option>
                <option value="linked" @selected($status === 'linked')>Linked to scan</option>
                <option value="unlinked" @selected($status === 'unlinked')>Unlinked</option>
            </select>
        </label>
        <div class="form-actions">
            <button type="submit">Filter</button>
            <a class="btn secondary" href="{{ route('admin.raw-evidence.index') }}">Reset</a>
        </div>
    </form>

    <div class="panel">
        <div class="panel-header">
            <div>
                <h3 class="panel-title">Evidence Files</h3>
                <p class="panel-subtitle">Database records for archived raw CSV/JSON snapshots.</p>
            </div>
            <span class="badge info">{{ $rawFiles->total() }} file(s)</span>
        </div>
        <div class="table-scroll">
            <table>
                <tr>
                    <th>Received</th>
                    <th>File</th>
                    <th>Hash</th>
                    <th>Source</th>
                    <th>Linked scan</th>
                    <th>Device</th>
                    <th>Action</th>
                </tr>
                @forelse($rawFiles as $rawFile)
                    @php($scan = $rawFile->scan)
                    <tr>
                        <td>@inventoryTime($rawFile->received_at)</td>
                        <td>
                            <strong>{{ $rawFile->original_filename }}</strong>
                            <div class="summary-meta">{{ $rawFile->raw_format }} | {{ $rawFile->saved_path }}</div>
                        </td>
                        <td><code>{{ $shortHash($rawFile->raw_hash) }}</code></td>
                        <td>
                            {{ $metadataValue($rawFile, 'runner_id') ?: $metadataValue($rawFile, 'collector_name') ?: '-' }}
                            <div class="summary-meta">{{ $metadataValue($rawFile, 'site_id') ?: '-' }}</div>
                        </td>
                        <td>
                            @if($scan)
                                <span class="badge good">Linked</span>
                                <div class="summary-meta">@inventoryTime($scan->scan_time)</div>
                            @else
                                <span class="badge warn">Unlinked</span>
                                <div class="summary-meta">No scan row found</div>
                            @endif
                        </td>
                        <td>
                            @if($scan?->device)
                                <a href="{{ route('admin.devices.show', $scan->device) }}">{{ $scan->device->current_asset_code ?: 'Device #' . $scan->device->id }}</a>
                                <div class="summary-meta">{{ $scan->device->current_user_name ?: '-' }}</div>
                            @else
                                -
                            @endif
                        </td>
                        <td><a class="btn" href="{{ route('admin.raw-evidence.show', $rawFile) }}">Open</a></td>
                    </tr>
                @empty
                    <tr><td colspan="7">No raw evidence records found.</td></tr>
                @endforelse
            </table>
        </div>
        {{ $rawFiles->links() }}
    </div>
@endsection
