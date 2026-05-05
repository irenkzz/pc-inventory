<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\File;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class DownloadController extends Controller
{
    public function index(): View
    {
        $root = $this->downloadsRoot();
        File::ensureDirectoryExists($root);

        $files = collect(File::files($root))
            ->map(fn ($file): array => [
                'name' => $file->getFilename(),
                'size' => $file->getSize(),
                'modified_at' => date('Y-m-d H:i:s', $file->getMTime()),
            ])
            ->sortBy('name')
            ->values();

        return view('admin.downloads.index', compact('files'));
    }

    public function show(string $filename): BinaryFileResponse|Response
    {
        abort_if(str_contains($filename, '..') || str_contains($filename, '/') || str_contains($filename, '\\'), 404);

        $path = $this->downloadsRoot() . DIRECTORY_SEPARATOR . $filename;
        abort_unless(is_file($path), 404);

        return response()->download($path);
    }

    private function downloadsRoot(): string
    {
        return storage_path('app/' . trim((string) config('inventory.downloads_path'), '/'));
    }
}
