@extends('admin.layout')

@php
    $number = fn (mixed $value): string => \App\Support\InventoryFormat::number($value);

    $deviceIssues = function (\App\Models\Device $device): array {
        $issues = [];

        if (trim((string) $device->current_department) === '') {
            $issues[] = ['label' => 'Department', 'class' => 'warn'];
        }

        if (trim((string) $device->current_site) === '') {
            $issues[] = ['label' => 'Site', 'class' => 'warn'];
        }

        if (trim((string) $device->current_user_name) === '') {
            $issues[] = ['label' => 'User', 'class' => 'info'];
        }

        $asset = trim((string) $device->current_asset_code);
        if ($asset === '' || str_starts_with(strtoupper($asset), 'DESKTOP-')) {
            $issues[] = ['label' => 'Asset name', 'class' => 'warn'];
        }

        if (! $device->identities->contains(fn ($identity): bool => (int) $identity->weight >= 70)) {
            $issues[] = ['label' => 'Identity', 'class' => 'danger'];
        }

        return $issues;
    };

    $issueHelp = [
        'missing_department' => 'Add a prefix rule or set a manual department on the device detail page.',
        'missing_site' => 'Add a site alias rule or set a manual site on the device detail page.',
        'missing_user' => 'Check the latest runner scan user context.',
        'generic_asset' => 'Rename the Windows computer or assign a stable asset code.',
        'weak_identity' => 'Verify UUID, motherboard serial, MAC address, or other identity evidence.',
    ];
@endphp

@section('content')
    <section class="dashboard-hero">
        <div>
            <p class="dashboard-kicker">Inventory quality</p>
            <h2 class="dashboard-title">Review Queue</h2>
            <p class="dashboard-copy">
                Devices that need cleanup before reports and audits can be trusted.
            </p>
        </div>
        <div class="dashboard-toolbar">
            <a class="btn secondary" href="{{ route('admin.classification-rules.index') }}">Rules</a>
            <a class="btn" href="{{ route('admin.devices.index') }}">All devices</a>
        </div>
    </section>

    <section class="grid" aria-label="Review issue counts">
        @foreach($issueLabels as $key => $label)
            @php($active = $issue === $key)
            <a href="{{ route('admin.inventory-review.index', ['issue' => $key]) }}" class="metric" style="text-decoration:none; border-color: {{ $active ? 'var(--blue)' : 'var(--border-soft)' }};">
                <div class="metric-top">
                    <div>
                        <span class="metric-label">{{ $label }}</span>
                        <strong>{{ $number($issueCounts[$key] ?? 0) }}</strong>
                    </div>
                    <span class="metric-icon" aria-hidden="true">{{ strtoupper(substr($label, 0, 1)) }}</span>
                </div>
                <p>{{ $key === 'all' ? 'Every device in the review table.' : ($issueHelp[$key] ?? 'Review device details.') }}</p>
            </a>
        @endforeach
    </section>

    <div class="panel dashboard-section">
        <div class="panel-header">
            <div>
                <h3 class="panel-title">{{ $issueLabels[$issue] }}</h3>
                <p class="panel-subtitle">Open a device to set manual assignment values or inspect raw scan evidence.</p>
            </div>
            <span class="badge info">{{ $devices->total() }} device(s)</span>
        </div>

        <div class="table-scroll">
            <table>
                <tr>
                    <th>Device</th>
                    <th>Issues</th>
                    <th>Department</th>
                    <th>Site</th>
                    <th>User</th>
                    <th>IP address</th>
                    <th>Last seen</th>
                    <th>Action</th>
                </tr>
                @forelse($devices as $device)
                    @php($issues = $deviceIssues($device))
                    <tr>
                        <td>
                            <div class="entity-cell">
                                <span class="entity-avatar" aria-hidden="true">{{ substr(strtoupper(preg_replace('/[^A-Z0-9]/', '', $device->current_asset_code ?: 'PC')), 0, 2) }}</span>
                                <span class="entity-main">
                                    <a class="entity-title" href="{{ route('admin.devices.show', $device) }}">{{ $device->current_asset_code ?: 'Device #' . $device->id }}</a>
                                    <span class="entity-subtitle">{{ trim(($device->manufacturer ?? '') . ' ' . ($device->model ?? '')) ?: 'Unknown hardware' }}</span>
                                </span>
                            </div>
                        </td>
                        <td>
                            <div class="actions">
                                @forelse($issues as $deviceIssue)
                                    <span class="badge {{ $deviceIssue['class'] }}">{{ $deviceIssue['label'] }}</span>
                                @empty
                                    <span class="badge good">Clean</span>
                                @endforelse
                            </div>
                        </td>
                        <td>{{ $device->current_department ?: '-' }}</td>
                        <td>{{ $device->current_site ?: '-' }}</td>
                        <td>{{ $device->current_user_name ?: '-' }}</td>
                        <td>{{ $device->latestNetworkObservation?->ip_address ?: '-' }}</td>
                        <td>@inventoryTime($device->last_seen_at)</td>
                        <td><a class="btn" href="{{ route('admin.devices.show', $device) }}">Review</a></td>
                    </tr>
                @empty
                    <tr><td colspan="8">No devices in this review category.</td></tr>
                @endforelse
            </table>
        </div>

        {{ $devices->links() }}
    </div>
@endsection
