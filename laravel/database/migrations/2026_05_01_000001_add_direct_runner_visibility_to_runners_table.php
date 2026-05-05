<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('runners', function (Blueprint $table): void {
            $table->string('transport_mode')->nullable()->after('install_mode');
            $table->dateTime('last_direct_heartbeat_at')->nullable()->after('last_seen_at');
            $table->text('last_upload_error')->nullable()->after('last_upload_status');
        });
    }

    public function down(): void
    {
        Schema::table('runners', function (Blueprint $table): void {
            $table->dropColumn([
                'transport_mode',
                'last_direct_heartbeat_at',
                'last_upload_error',
            ]);
        });
    }
};
