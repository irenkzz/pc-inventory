<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Default 'admin' keeps existing users at full access.
        Schema::table('users', function (Blueprint $table): void {
            $table->string('role', 20)->default('admin');
        });

        Schema::create('audit_log', function (Blueprint $table): void {
            $table->id();
            $table->string('actor');
            $table->string('action', 64);
            $table->string('subject')->nullable();
            $table->json('details')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_log');
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('role');
        });
    }
};
