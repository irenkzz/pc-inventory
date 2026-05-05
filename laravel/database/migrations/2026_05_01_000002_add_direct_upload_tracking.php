<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('runners', function (Blueprint $table): void {
            $table->dateTime('last_direct_upload_at')->nullable()->after('last_direct_heartbeat_at');
        });

        Schema::table('raw_files', function (Blueprint $table): void {
            $table->string('upload_id')->nullable()->after('raw_hash');
            $table->unique('upload_id');
        });
    }

    public function down(): void
    {
        Schema::table('raw_files', function (Blueprint $table): void {
            $table->dropUnique(['upload_id']);
            $table->dropColumn('upload_id');
        });

        Schema::table('runners', function (Blueprint $table): void {
            $table->dropColumn('last_direct_upload_at');
        });
    }
};
