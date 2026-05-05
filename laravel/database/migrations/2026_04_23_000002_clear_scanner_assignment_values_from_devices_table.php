<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('devices')
            ->where(function ($query): void {
                $query->whereNull('manual_department')->orWhere('manual_department', '');
            })
            ->update(['current_department' => null]);

        DB::table('devices')
            ->where(function ($query): void {
                $query->whereNull('manual_site')->orWhere('manual_site', '');
            })
            ->update(['current_site' => null]);

        DB::table('devices')
            ->where(function ($query): void {
                $query->whereNull('manual_location')->orWhere('manual_location', '');
            })
            ->update(['current_location' => null]);

        DB::table('devices')
            ->where(function ($query): void {
                $query->whereNull('manual_room')->orWhere('manual_room', '');
            })
            ->update(['current_room' => null]);
    }

    public function down(): void
    {
        // Scanner-derived assignment/location values are intentionally not restored.
    }
};
