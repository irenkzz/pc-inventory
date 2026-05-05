@extends('admin.layout')

@section('content')
    <h2>Install / Repair Packages</h2>
    <table>
        <tr><th>File</th><th>Size bytes</th><th>Modified</th><th>Action</th></tr>
        @forelse($files as $file)
            <tr>
                <td>{{ $file['name'] }}</td>
                <td>{{ $file['size'] }}</td>
                <td>@inventoryTime($file['modified_at'])</td>
                <td><a class="btn" href="{{ route('admin.downloads.show', $file['name']) }}">Download</a></td>
            </tr>
        @empty
            <tr><td colspan="4">No generated packages yet.</td></tr>
        @endforelse
    </table>
@endsection
