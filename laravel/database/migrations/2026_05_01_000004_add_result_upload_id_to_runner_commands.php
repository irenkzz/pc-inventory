<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('runner_commands', function (Blueprint $table): void {
            $table->string('result_upload_id')->nullable()->after('completion_message');
        });
    }

    public function down(): void
    {
        Schema::table('runner_commands', function (Blueprint $table): void {
            $table->dropColumn('result_upload_id');
        });
    }
};
