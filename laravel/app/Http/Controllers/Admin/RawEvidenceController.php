<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\DeviceScan;
use App\Models\RawFile;
use Illuminate\Http\Request;
use Illuminate\View\View;

class RawEvidenceController extends Controller
{
    public function index(Request $request): View
    {
        $query = trim((string) $request->query('q', ''));
        $status = trim((string) $request->query('status', ''));

        $rawFiles = RawFile::query()
            ->with(['scan.device'])
            ->when($query !== '', function ($builder) use ($query): void {
                $like = "%{$query}%";
                $builder->where(function ($nested) use ($like): void {
                    $nested->where('original_filename', 'like', $like)
                        ->orWhere('saved_path', 'like', $like)
                        ->orWhere('raw_hash', 'like', $like)
                        ->orWhere('raw_format', 'like', $like)
                        ->orWhere('metadata_json', 'like', $like)
                        ->orWhereHas('scan.device', fn ($deviceQuery) => $deviceQuery
                            ->where('current_asset_code', 'like', $like)
                            ->orWhere('current_user_name', 'like', $like));
                });
            })
            ->when($status === 'linked', fn ($builder) => $builder->whereHas('scan'))
            ->when($status === 'unlinked', fn ($builder) => $builder->whereDoesntHave('scan'))
            ->orderByDesc('received_at')
            ->orderByDesc('id')
            ->paginate(50)
            ->withQueryString();

        return view('admin.raw-evidence.index', [
            'rawFiles' => $rawFiles,
            'query' => $query,
            'status' => $status,
            'summary' => $this->summary(),
        ]);
    }

    public function show(RawFile $rawFile): View
    {
        $rawFile->load(['scan.device', 'scan.snapshot', 'scan.changes']);

        return view('admin.raw-evidence.show', compact('rawFile'));
    }

    private function summary(): array
    {
        return [
            'total_raw_files' => RawFile::query()->count(),
            'linked_raw_files' => RawFile::query()->whereHas('scan')->count(),
            'unlinked_raw_files' => RawFile::query()->whereDoesntHave('scan')->count(),
            'total_scans' => DeviceScan::query()->count(),
            'latest_received_at' => RawFile::query()->max('received_at'),
            'latest_scan_at' => DeviceScan::query()->max('scan_time'),
        ];
    }
}
