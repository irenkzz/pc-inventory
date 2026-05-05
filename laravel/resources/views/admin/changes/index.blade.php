@extends('admin.layout')

@section('content')
    <h2>Recent Changes</h2>
    <table>
        <tr><th>Observed</th><th>Asset</th><th>Site</th><th>Severity</th><th>Group</th><th>Field</th><th>Old</th><th>New</th></tr>
        @forelse($changes as $change)
            <tr>
                <td>@inventoryTime($change->observed_at)</td>
                <td>{{ $change->device?->current_asset_code }}</td>
                <td>{{ $change->observed_site }}</td>
                <td>{{ $change->severity }}</td>
                <td>{{ $change->change_group }}</td>
                <td>{{ $change->field_name }}</td>
                <td>{{ $change->old_value }}</td>
                <td>{{ $change->new_value }}</td>
            </tr>
        @empty
            <tr><td colspan="8">No changes yet.</td></tr>
        @endforelse
    </table>
@endsection
