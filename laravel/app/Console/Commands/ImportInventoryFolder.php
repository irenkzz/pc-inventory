<?php

namespace App\Console\Commands;

use App\Services\Inventory\InventoryIngestService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class ImportInventoryFolder extends Command
{
    protected $signature = 'inventory:import-folder {folder : Folder containing inventory CSV files}';

    protected $description = 'Import inventory CSV files into the authoritative database';

    public function handle(InventoryIngestService $ingest): int
    {
        $folder = base_path($this->argument('folder'));
        if (! is_dir($folder)) {
            $folder = (string) $this->argument('folder');
        }

        if (! is_dir($folder)) {
            $this->error("Folder not found: {$folder}");
            return self::FAILURE;
        }

        $stats = [
            'files' => 0,
            'rows' => 0,
            'created' => 0,
            'updated' => 0,
            'duplicates' => 0,
        ];

        foreach (File::allFiles($folder) as $file) {
            if (strtolower($file->getExtension()) !== 'csv') {
                continue;
            }

            $stats['files']++;
            $results = $ingest->ingestCsvText((string) file_get_contents($file->getPathname()), $file->getFilename(), 'cli_import');
            $stats['rows'] += count($results);

            foreach ($results as $result) {
                match ($result['status'] ?? '') {
                    'created' => $stats['created']++,
                    'updated' => $stats['updated']++,
                    'duplicate' => $stats['duplicates']++,
                    default => null,
                };
            }
        }

        $this->line("Imported files: {$stats['files']}");
        $this->line("Imported rows : {$stats['rows']}");
        $this->line("Created scans : {$stats['created']}");
        $this->line("Updated scans : {$stats['updated']}");
        $this->line("Duplicates    : {$stats['duplicates']}");

        return self::SUCCESS;
    }
}
