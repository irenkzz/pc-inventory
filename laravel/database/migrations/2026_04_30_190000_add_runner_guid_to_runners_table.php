<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('runners', function (Blueprint $table): void {
            $table->string('runner_guid')->nullable()->unique()->after('runner_id');
        });
    }

    public function down(): void
    {
        Schema::table('runners', function (Blueprint $table): void {
            $table->dropUnique(['runner_guid']);
            $table->dropColumn('runner_guid');
        });
    }
};
