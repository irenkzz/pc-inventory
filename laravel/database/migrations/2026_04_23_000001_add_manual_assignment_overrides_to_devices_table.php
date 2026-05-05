<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('devices', function (Blueprint $table): void {
            $table->string('manual_department')->nullable()->after('current_site');
            $table->string('manual_site')->nullable()->after('manual_department');
            $table->string('manual_location')->nullable()->after('manual_site');
            $table->string('manual_room')->nullable()->after('manual_location');
            $table->dateTime('manual_assignment_updated_at')->nullable()->after('manual_room');
        });
    }

    public function down(): void
    {
        Schema::table('devices', function (Blueprint $table): void {
            $table->dropColumn([
                'manual_department',
                'manual_site',
                'manual_location',
                'manual_room',
                'manual_assignment_updated_at',
            ]);
        });
    }
};
