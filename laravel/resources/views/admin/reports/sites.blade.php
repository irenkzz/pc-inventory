@extends('admin.layout')

@section('content')
    <div class="actions">
        <h2>Site Summary Report</h2>
        <button onclick="window.print()">Print</button>
        <a class="btn secondary" href="{{ route('admin.reports.sites.export') }}">CSV</a>
    </div>
    <table>
        <tr><th>Site</th><th>Site ID</th><th>Collectors</th><th>Runners</th><th>Devices</th><th>Changes</th></tr>
        @forelse($sites as $row)
            <tr>
                <td>{{ $row['site']->site_name ?: $row['site']->site_id }}</td>
                <td>{{ $row['site']->site_id }}</td>
                <td>{{ $row['site']->collectors_count }}</td>
                <td>{{ $row['site']->runners_count }}</td>
                <td>{{ $row['device_count'] }}</td>
                <td>{{ $row['change_count'] }}</td>
            </tr>
        @empty
            <tr><td colspan="6">No sites yet.</td></tr>
        @endforelse
    </table>
@endsection
