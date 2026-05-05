<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('devices')
            ->select(['id', 'current_asset_code'])
            ->where(function ($query): void {
                $query->whereNull('manual_department')->orWhere('manual_department', '');
            })
            ->orderBy('id')
            ->chunkById(100, function ($devices): void {
                foreach ($devices as $device) {
                    $department = $this->departmentForAssetName((string) $device->current_asset_code);
                    if ($department === '') {
                        continue;
                    }

                    DB::table('devices')
                        ->where('id', $device->id)
                        ->update(['current_department' => $department]);
                }
            });
    }

    public function down(): void
    {
        DB::table('devices')
            ->where(function ($query): void {
                $query->whereNull('manual_department')->orWhere('manual_department', '');
            })
            ->whereIn('current_department', ['Pemberitaan', 'Teknik', 'Program', 'IT'])
            ->update(['current_department' => '']);
    }

    private function departmentForAssetName(string $assetName): string
    {
        $assetName = trim((string) preg_replace('/\s+/', ' ', $assetName));
        if ($assetName === '') {
            return '';
        }

        $prefix = strtoupper(strtok($assetName, '-_ .') ?: $assetName);

        return match ($prefix) {
            'BRT' => 'Pemberitaan',
            'EDT', 'MCR' => 'Teknik',
            'PGM' => 'Program',
            'IT', 'SRV' => 'IT',
            default => '',
        };
    }
};
