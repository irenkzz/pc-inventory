@extends('admin.layout')

@php
    $scan = $rawFile->scan;
    $device = $scan?->device;
    $shortHash = $rawFile->raw_hash ? substr($rawFile->raw_hash, 0, 12) : '-';
@endphp

@section('content')
    <section class="detail-hero">
        <div>
            <p class="dashboard-kicker">Raw snapshot evidence</p>
            <div class="detail-title-row">
                <h2>{{ $rawFile->original_filename }}</h2>
                <span class="badge {{ $scan ? 'good' : 'warn' }}">{{ $scan ? 'Linked to scan' : 'Unlinked' }}</span>
                <span class="badge info">{{ $rawFile->raw_format }}</span>
            </div>
            <div class="detail-meta">
                <span>Received: {{ \App\Support\InventoryTime::format($rawFile->received_at) }}</span>
                <span>Hash: {{ $shortHash }}</span>
            </div>
        </div>
        <div class="quick-nav">
            <a class="btn secondary" href="{{ route('admin.raw-evidence.index') }}">All evidence</a>
            @if($device)
                <a class="btn" href="{{ route('admin.devices.show', $device) }}">Device</a>
            @endif
        </div>
    </section>

    <section class="detail-grid">
        <div class="panel">
            <div class="panel-header">
                <div>
                    <h3 class="panel-title">Archive Record</h3>
                    <p class="panel-subtitle">Stored raw file identity and archive location.</p>
                </div>
            </div>
            <dl class="compact-dl">
                <dt>Original filename</dt><dd>{{ $rawFile->original_filename }}</dd>
                <dt>Saved path</dt><dd>{{ $rawFile->saved_path }}</dd>
                <dt>Raw format</dt><dd>{{ $rawFile->raw_format }}</dd>
                <dt>Raw hash</dt><dd>{{ $rawFile->raw_hash }}</dd>
                <dt>Received</dt><dd>@inventoryTime($rawFile->received_at)</dd>
            </dl>
        </div>

        <div class="panel">
            <div class="panel-header">
                <div>
                    <h3 class="panel-title">Linked Scan</h3>
                    <p class="panel-subtitle">Database scan created from this evidence, when available.</p>
                </div>
            </div>
            @if($scan)
                <dl class="compact-dl">
                    <dt>Scan time</dt><dd>@inventoryTime($scan->scan_time)</dd>
                    <dt>Scan source</dt><dd>{{ $scan->scan_source }}</dd>
                    <dt>Device</dt>
                    <dd>
                        @if($device)
                            <a href="{{ route('admin.devices.show', $device) }}">{{ $device->current_asset_code ?: 'Device #' . $device->id }}</a>
                        @else
                            -
                        @endif
                    </dd>
                    <dt>Hardware hash</dt><dd>{{ $scan->snapshot?->hardware_hash ?: '-' }}</dd>
                    <dt>Changes</dt><dd>{{ $scan->changes->count() }}</dd>
                </dl>
            @else
                <div class="empty-state">No scan row currently references this raw hash.</div>
            @endif
        </div>
    </section>

    <section class="panel dashboard-section">
        <div class="panel-header">
            <div>
                <h3 class="panel-title">Source Metadata</h3>
                <p class="panel-subtitle">Collector or runner metadata submitted with the raw file.</p>
            </div>
        </div>
        <pre class="raw-snapshot">{{ json_encode($rawFile->metadata_json ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</pre>
    </section>

    @if($scan?->snapshot)
        <section class="panel dashboard-section">
            <div class="panel-header">
                <div>
                    <h3 class="panel-title">Normalized Snapshot</h3>
                    <p class="panel-subtitle">Database snapshot produced from this raw evidence.</p>
                </div>
            </div>
            <pre class="raw-snapshot">{{ json_encode($scan->snapshot->snapshot_json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</pre>
        </section>
    @endif
@endsection
