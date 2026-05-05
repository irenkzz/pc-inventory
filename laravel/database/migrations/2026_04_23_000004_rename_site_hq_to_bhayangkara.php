<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('collector_sites')
            ->where('site_id', 'SITE-HQ')
            ->update(['site_name' => 'Bhayangkara']);

        DB::table('devices')
            ->where(function ($query): void {
                $query->whereNull('manual_site')->orWhere('manual_site', '');
            })
            ->where(function ($query): void {
                $query->whereNull('current_site')->orWhereIn('current_site', ['', 'SITE-HQ']);
            })
            ->update(['current_site' => 'Bhayangkara']);

        DB::table('device_assignments')
            ->where(function ($query): void {
                $query->whereNull('site_estimated')->orWhereIn('site_estimated', ['', 'SITE-HQ']);
            })
            ->update(['site_estimated' => 'Bhayangkara']);

        DB::table('change_log')
            ->where(function ($query): void {
                $query->whereNull('observed_site')->orWhereIn('observed_site', ['', 'SITE-HQ']);
            })
            ->update(['observed_site' => 'Bhayangkara']);
    }

    public function down(): void
    {
        DB::table('collector_sites')
            ->where('site_id', 'SITE-HQ')
            ->where('site_name', 'Bhayangkara')
            ->update(['site_name' => null]);

        DB::table('devices')
            ->where(function ($query): void {
                $query->whereNull('manual_site')->orWhere('manual_site', '');
            })
            ->where('current_site', 'Bhayangkara')
            ->update(['current_site' => '']);

        DB::table('device_assignments')
            ->where('site_estimated', 'Bhayangkara')
            ->update(['site_estimated' => '']);

        DB::table('change_log')
            ->where('observed_site', 'Bhayangkara')
            ->update(['observed_site' => '']);
    }
};
