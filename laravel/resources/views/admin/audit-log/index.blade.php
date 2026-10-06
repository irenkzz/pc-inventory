@extends('admin.layout')

@section('content')
    <section class="dashboard-hero">
        <div>
            <p class="dashboard-kicker">Audit</p>
            <h2 class="dashboard-title">Audit Log</h2>
        </div>
    </section>

    <table>
        <thead><tr><th>Time</th><th>Actor</th><th>Action</th><th>Subject</th><th>Details</th></tr></thead>
        <tbody>
        @forelse($entries as $entry)
            <tr>
                <td>{{ $entry->created_at }}</td>
                <td>{{ $entry->actor }}</td>
                <td>{{ $entry->action }}</td>
                <td>{{ $entry->subject }}</td>
                <td>{{ $entry->details ? json_encode($entry->details) : '' }}</td>
            </tr>
        @empty
            <tr><td colspan="5">No audit entries.</td></tr>
        @endforelse
        </tbody>
    </table>
    {{ $entries->links() }}
@endsection
