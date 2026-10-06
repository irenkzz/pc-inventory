@extends('admin.layout')

@section('content')
    <div class="actions">
        <h2>Change Audit Report</h2>
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
        <input type="date" name="date_from" value="{{ $filters['date_from'] }}">
        <input type="date" name="date_to" value="{{ $filters['date_to'] }}">
        <button type="submit">Filter</button>
        <a class="btn secondary" href="{{ route('admin.reports.changes') }}">Reset</a>
        <a class="btn secondary" href="{{ route('admin.reports.changes.export', request()->query()) }}">CSV</a>
    </form>
    <table>
        <tr><th>Observed</th><th>Asset</th><th>Site</th><th>Department</th><th>Room</th><th>Severity</th><th>Group</th><th>Field</th><th>Old</th><th>New</th></tr>
        @forelse($changes as $change)
            <tr>
                <td>@inventoryTime($change->observed_at)</td>
                <td>{{ $change->device?->current_asset_code }}</td>
                <td>{{ $change->observed_site }}</td>
                <td>{{ $change->observed_department }}</td>
                <td>{{ $change->observed_room }}</td>
                <td>{{ $change->severity }}</td>
                <td>{{ $change->change_group }}</td>
                <td>{{ $change->field_name }}</td>
                <td>{{ $change->old_value }}</td>
                <td>{{ $change->new_value }}</td>
            </tr>
        @empty
            <tr><td colspan="10">No changes yet.</td></tr>
        @endforelse
    </table>
    {{ $changes->links() }}
@endsection
