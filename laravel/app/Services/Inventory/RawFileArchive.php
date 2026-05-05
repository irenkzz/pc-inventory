<?php

namespace App\Services\Inventory;

use Illuminate\Support\Facades\Storage;

class RawFileArchive
{
    public function archive(string $rawHash, string $rawText, string $rawFormat, string $originalFilename): string
    {
        $extension = $rawFormat === 'json' ? 'json' : 'csv';
        $safeName = $this->safeFilename($originalFilename !== '' ? $originalFilename : "scan_{$rawHash}.{$extension}");
        $archiveRoot = trim((string) config('inventory.raw_archive_path', 'inventory/raw_archive'), '/');
        $basePath = $archiveRoot . '/' . substr($rawHash, 0, 16) . "_{$safeName}";
        $path = $basePath;
        $counter = 2;

        $disk = (string) config('inventory.raw_archive_disk', 'local');

        while (Storage::disk($disk)->exists($path)) {
            $path = preg_replace('/(\.[^.]+)$/', "_{$counter}$1", $basePath) ?? "{$basePath}_{$counter}";
            $counter++;
        }

        Storage::disk($disk)->put($path, $rawText);

        return $path;
    }

    private function safeFilename(string $filename): string
    {
        $filename = trim(str_replace(['\\', '/'], '_', $filename));
        $filename = preg_replace('/[^A-Za-z0-9._-]+/', '_', $filename) ?: 'scan.csv';

        return trim($filename, '._') ?: 'scan.csv';
    }
}
