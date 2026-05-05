@extends('admin.layout')

@section('content')
    <h2>Reports</h2>
    <div class="grid">
        <div class="metric">
            Current inventory
            <p class="muted">Printable device list grouped by current site, department, asset, and assignment.</p>
            <a class="btn" href="{{ route('admin.reports.devices') }}">Open</a>
        </div>
        <div class="metric">
            Change audit
            <p class="muted">Printable recent hardware, assignment, location, network, and peripheral changes.</p>
            <a class="btn" href="{{ route('admin.reports.changes') }}">Open</a>
        </div>
        <div class="metric">
            Site summary
            <p class="muted">Collector, runner, device, and change counts by site.</p>
            <a class="btn" href="{{ route('admin.reports.sites') }}">Open</a>
        </div>
    </div>
@endsection
