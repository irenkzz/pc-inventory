@extends('admin.layout')

@section('content')
    <div class="actions">
        <h2>Current Inventory Report</h2>
        <button onclick="window.print()">Print</button>
    </div>
    <form method="get" class="actions">
        <select name="site">
            <option value="">All sites</option>
            @foreach($sites as $site)
                <option value="{{ $site }}" @selected($filters['site'] === $site)>{{ $site }}</option>
            @endforeach
        </select>
        <select name="department">
            <option value="">All departments</option>
            @foreach($departments as $department)
                <option value="{{ $department }}" @selected($filters['department'] === $department)>{{ $department }}</option>
            @endforeach
        </select>
        <button type="submit">Filter</button>
        <a class="btn secondary" href="{{ route('admin.reports.devices') }}">Reset</a>
        <a class="btn secondary" href="{{ route('admin.reports.devices.export', request()->query()) }}">CSV</a>
    </form>
    <table>
        <tr><th>Site</th><th>Department</th><th>Asset</th><th>User</th><th>Room</th><th>Device</th><th>Hardware hash</th><th>Last seen</th></tr>
        @forelse($devices as $device)
            <tr>
                <td>{{ $device->current_site }}</td>
                <td>{{ $device->current_department }}</td>
                <td>{{ $device->current_asset_code }}</td>
                <td>{{ $device->current_user_name }}</td>
                <td>{{ $device->current_location }} {{ $device->current_room }}</td>
                <td>{{ $device->manufacturer }} {{ $device->model }}</td>
                <td>{{ $device->hardware_hash }}</td>
                <td>@inventoryTime($device->last_seen_at)</td>
            </tr>
        @empty
            <tr><td colspan="8">No devices yet.</td></tr>
        @endforelse
    </table>
    {{ $devices->links() }}
@endsection
