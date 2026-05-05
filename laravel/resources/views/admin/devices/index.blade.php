@extends('admin.layout')

@php
    $number = fn (mixed $value): string => \App\Support\InventoryFormat::number($value);
    $specList = function (mixed $raw): array {
        $value = trim((string) $raw);
        if ($value === '') {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode(';', $value))));
    };
    $activeFilters = array_values(array_filter([
        $query !== '' ? 'Search: ' . $query : null,
        $cpu !== '' ? 'CPU: ' . $cpu : null,
        $gpu !== '' ? 'GPU: ' . $gpu : null,
        $disk !== '' ? 'Storage: ' . $disk : null,
        $ramGb !== '' ? 'RAM exact: ' . $ramGb . ' GB' : null,
        $ramMinGb !== '' ? 'RAM minimum: ' . $ramMinGb . ' GB' : null,
    ]));
    $sortUrl = function (string $column) use ($sort, $direction): string {
        $nextDirection = $sort === $column && $direction === 'asc' ? 'desc' : 'asc';

        return request()->fullUrlWithQuery([
            'sort' => $column,
            'direction' => $nextDirection,
        ]);
    };
    $sortLabel = function (string $column, string $label) use ($sort, $direction): string {
        if ($sort !== $column) {
            return $label;
        }

        return $label . ' ' . ($direction === 'asc' ? '▲' : '▼');
    };
@endphp

@section('content')
    <section class="dashboard-hero">
        <div>
            <p class="dashboard-kicker">Current inventory</p>
            <h2 class="dashboard-title">Devices</h2>
            <p class="dashboard-copy">
                Search current assignments and filter by the latest stored hardware snapshot, including CPU, GPU, storage, and RAM capacity.
            </p>
        </div>
        <div class="dashboard-toolbar">
            <a class="btn secondary" href="{{ route('admin.inventory-review.index') }}">Review queue</a>
            <a class="btn" href="{{ route('admin.reports.index') }}">Reports</a>
        </div>
    </section>

    <section class="ops-strip" aria-label="Device inventory coverage">
        <div class="ops-item">
            <div>
                <span class="ops-label">Matching devices</span>
                <span class="ops-value">{{ $number($devices->total()) }}</span>
            </div>
            <span class="badge info">Filtered view</span>
        </div>
        <div class="ops-item">
            <div>
                <span class="ops-label">CPU filter</span>
                <span class="ops-value">{{ $cpu !== '' ? $cpu : '-' }}</span>
            </div>
            <span class="badge {{ $cpu !== '' ? 'good' : '' }}">{{ $cpu !== '' ? 'Active' : 'Any CPU' }}</span>
        </div>
        <div class="ops-item">
            <div>
                <span class="ops-label">RAM filter</span>
                <span class="ops-value">
                    @if($ramGb !== '')
                        {{ $ramGb }} GB
                    @elseif($ramMinGb !== '')
                        >= {{ $ramMinGb }} GB
                    @else
                        -
                    @endif
                </span>
            </div>
            <span class="badge {{ $ramGb !== '' || $ramMinGb !== '' ? 'good' : '' }}">
                {{ $ramGb !== '' ? 'Exact match' : ($ramMinGb !== '' ? 'Minimum match' : 'Any RAM') }}
            </span>
        </div>
    </section>

    <form method="get" class="form-grid">
        <label>
            Search
            <input type="text" name="q" value="{{ $query }}" placeholder="Asset, user, department, model, location, site">
        </label>
        <label>
            Processor
            <input type="text" name="cpu" value="{{ $cpu }}" placeholder="Intel Core i5, Ryzen 5, Xeon">
        </label>
        <label>
            GPU
            <input type="text" name="gpu" value="{{ $gpu }}" placeholder="Intel UHD, NVIDIA, Radeon">
        </label>
        <label>
            Storage
            <input type="text" name="disk" value="{{ $disk }}" placeholder="512 GB SSD, NVMe, 1 TB HDD">
        </label>
        <label>
            RAM exact
            <select name="ram_gb">
                <option value="" @selected($ramGb === '')>Any RAM</option>
                @foreach(['4', '8', '16', '32', '64', '128'] as $ramOption)
                    <option value="{{ $ramOption }}" @selected($ramGb === $ramOption)>{{ $ramOption }} GB</option>
                @endforeach
            </select>
        </label>
        <label>
            RAM minimum
            <select name="ram_min_gb">
                <option value="" @selected($ramMinGb === '')>No minimum</option>
                @foreach(['8', '16', '32', '64', '128'] as $ramOption)
                    <option value="{{ $ramOption }}" @selected($ramMinGb === $ramOption)>{{ $ramOption }} GB or more</option>
                @endforeach
            </select>
        </label>
        <div class="form-actions">
            <button type="submit">Filter devices</button>
            <a class="btn secondary" href="{{ route('admin.devices.index') }}">Reset</a>
        </div>
    </form>

    @if($activeFilters !== [])
        <div class="panel dashboard-section">
            <div class="panel-header">
                <div>
                    <h3 class="panel-title">Active Hardware Filters</h3>
                    <p class="panel-subtitle">These filters use the latest snapshot stored for each device, so old scans do not affect the result.</p>
                </div>
            </div>
            <div class="actions">
                @foreach($activeFilters as $filter)
                    <span class="badge info">{{ $filter }}</span>
                @endforeach
            </div>
        </div>
    @endif

    <div class="panel dashboard-section">
        <div class="panel-header">
            <div>
                <h3 class="panel-title">Device List</h3>
                <p class="panel-subtitle">Current asset assignment, location, and latest CPU, GPU, storage, and RAM detail.</p>
            </div>
            <span class="badge info">{{ $number($devices->total()) }} device(s)</span>
        </div>

        <div class="table-scroll">
            <table>
                <tr>
                    <th><a href="{{ $sortUrl('asset') }}">{{ $sortLabel('asset', 'Asset') }}</a></th>
                    <th><a href="{{ $sortUrl('user') }}">{{ $sortLabel('user', 'User') }}</a></th>
                    <th><a href="{{ $sortUrl('department') }}">{{ $sortLabel('department', 'Department') }}</a></th>
                    <th><a href="{{ $sortUrl('site') }}">{{ $sortLabel('site', 'Site') }}</a></th>
                    <th><a href="{{ $sortUrl('cpu') }}">{{ $sortLabel('cpu', 'CPU') }}</a></th>
                    <th><a href="{{ $sortUrl('gpu') }}">{{ $sortLabel('gpu', 'GPU') }}</a></th>
                    <th><a href="{{ $sortUrl('disk') }}">{{ $sortLabel('disk', 'Storage') }}</a></th>
                    <th><a href="{{ $sortUrl('ram') }}">{{ $sortLabel('ram', 'RAM') }}</a></th>
                    <th><a href="{{ $sortUrl('hardware') }}">{{ $sortLabel('hardware', 'Hardware') }}</a></th>
                    <th><a href="{{ $sortUrl('last_seen_at') }}">{{ $sortLabel('last_seen_at', 'Last seen') }}</a></th>
                </tr>
                @forelse($devices as $device)
                    @php($snapshot = $device->latestScan?->snapshot)
                    <tr>
                        <td>
                            <a href="{{ route('admin.devices.show', $device) }}">{{ $device->current_asset_code ?: 'Device #' . $device->id }}</a>
                            <div class="summary-meta">{{ $device->latestNetworkObservation?->ip_address ?: '-' }}</div>
                        </td>
                        <td>{{ $device->current_user_name ?: '-' }}</td>
                        <td>{{ $device->current_department ?: '-' }}</td>
                        <td>
                            {{ $device->current_site ?: '-' }}
                            <div class="summary-meta">{{ trim(($device->current_location ?? '') . ' ' . ($device->current_room ?? '')) ?: '-' }}</div>
                        </td>
                        <td>
                            @php($cpuParts = $specList($snapshot?->cpu))
                            @if(count($cpuParts) > 1)
                                <ul class="spec-list compact">
                                    @foreach($cpuParts as $part)
                                        <li>{{ $part }}</li>
                                    @endforeach
                                </ul>
                            @else
                                {{ $snapshot?->cpu ?: '-' }}
                            @endif
                        </td>
                        <td>
                            @php($gpuParts = $specList($snapshot?->gpu))
                            @if(count($gpuParts) > 1)
                                <ul class="spec-list compact">
                                    @foreach($gpuParts as $part)
                                        <li>{{ $part }}</li>
                                    @endforeach
                                </ul>
                            @else
                                {{ $snapshot?->gpu ?: '-' }}
                            @endif
                        </td>
                        <td>
                            @php($diskParts = $specList($snapshot?->disk))
                            @if(count($diskParts) > 1)
                                <ul class="spec-list compact">
                                    @foreach($diskParts as $part)
                                        <li>{{ $part }}</li>
                                    @endforeach
                                </ul>
                            @else
                                {{ $snapshot?->disk ?: '-' }}
                            @endif
                        </td>
                        <td>
                            {{ $snapshot?->ram_gb ? \App\Support\InventoryFormat::number($snapshot->ram_gb) . ' GB' : '-' }}
                            <div class="summary-meta">{{ $snapshot?->ram_slots_used ? \App\Support\InventoryFormat::number($snapshot->ram_slots_used) . ' slot(s) used' : '-' }}</div>
                        </td>
                        <td>{{ trim(($device->manufacturer ?? '') . ' ' . ($device->model ?? '')) ?: '-' }}</td>
                        <td>@inventoryTime($device->last_seen_at)</td>
                    </tr>
                @empty
                    <tr><td colspan="10">No devices match the current filters.</td></tr>
                @endforelse
            </table>
        </div>

        {{ $devices->links() }}
    </div>
@endsection
