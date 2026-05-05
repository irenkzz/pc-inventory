<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('runners', function (Blueprint $table): void {
            $table->dateTime('last_direct_poll_at')->nullable()->after('last_direct_upload_at');
        });

        Schema::table('runner_commands', function (Blueprint $table): void {
            $table->dateTime('delivered_to_runner_at')->nullable()->after('dispatched_at');
        });
    }

    public function down(): void
    {
        Schema::table('runner_commands', function (Blueprint $table): void {
            $table->dropColumn('delivered_to_runner_at');
        });

        Schema::table('runners', function (Blueprint $table): void {
            $table->dropColumn('last_direct_poll_at');
        });
    }
};
