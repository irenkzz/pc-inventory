<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hardware_snapshots', function (Blueprint $table): void {
            $table->text('ssd_tbw_bytes')->nullable()->after('disk_detail');
            $table->text('ssd_tbw_gb')->nullable()->after('ssd_tbw_bytes');
            $table->text('ssd_percentage_used')->nullable()->after('ssd_tbw_gb');
            $table->text('ssd_power_on_hours')->nullable()->after('ssd_percentage_used');
            $table->text('ssd_health_source')->nullable()->after('ssd_power_on_hours');
            $table->text('ssd_health_detail')->nullable()->after('ssd_health_source');
        });
    }

    public function down(): void
    {
        Schema::table('hardware_snapshots', function (Blueprint $table): void {
            $table->dropColumn([
                'ssd_tbw_bytes',
                'ssd_tbw_gb',
                'ssd_percentage_used',
                'ssd_power_on_hours',
                'ssd_health_source',
                'ssd_health_detail',
            ]);
        });
    }
};
