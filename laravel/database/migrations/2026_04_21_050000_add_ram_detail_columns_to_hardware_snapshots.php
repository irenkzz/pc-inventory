<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hardware_snapshots', function (Blueprint $table): void {
            $table->text('ram_manufacturers')->nullable()->after('ram_slots_used');
            $table->text('ram_part_numbers')->nullable()->after('ram_manufacturers');
            $table->text('ram_serial_numbers')->nullable()->after('ram_part_numbers');
            $table->text('ram_speeds_mhz')->nullable()->after('ram_serial_numbers');
            $table->text('ram_types')->nullable()->after('ram_speeds_mhz');
            $table->text('ram_slots')->nullable()->after('ram_types');
        });
    }

    public function down(): void
    {
        Schema::table('hardware_snapshots', function (Blueprint $table): void {
            $table->dropColumn([
                'ram_manufacturers',
                'ram_part_numbers',
                'ram_serial_numbers',
                'ram_speeds_mhz',
                'ram_types',
                'ram_slots',
            ]);
        });
    }
};
