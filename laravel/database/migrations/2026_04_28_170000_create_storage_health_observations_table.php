<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('storage_health_observations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('device_id')->constrained()->cascadeOnDelete();
            $table->foreignId('device_scan_id')->constrained('device_scans')->cascadeOnDelete();
            $table->string('disk_key');
            $table->string('disk_model')->nullable();
            $table->string('disk_serial')->nullable();
            $table->string('disk_type')->nullable();
            $table->string('interface_type')->nullable();
            $table->decimal('capacity_gb', 14, 2)->nullable();
            $table->boolean('smart_available')->nullable();
            $table->string('smart_health_status')->nullable();
            $table->unsignedBigInteger('tbw_bytes')->nullable();
            $table->decimal('tbw_gb', 16, 2)->nullable();
            $table->unsignedSmallInteger('percentage_used')->nullable();
            $table->unsignedInteger('power_on_hours')->nullable();
            $table->smallInteger('temperature_c')->nullable();
            $table->unsignedSmallInteger('available_spare')->nullable();
            $table->unsignedBigInteger('media_errors')->nullable();
            $table->unsignedBigInteger('reallocated_sector_count')->nullable();
            $table->unsignedBigInteger('current_pending_sector')->nullable();
            $table->unsignedBigInteger('offline_uncorrectable')->nullable();
            $table->string('risk_level')->default('unknown');
            $table->unsignedSmallInteger('risk_score')->default(0);
            $table->json('risk_reasons')->nullable();
            $table->string('recommended_action')->nullable();
            $table->string('source_method')->nullable();
            $table->text('storage_health_detail')->nullable();
            $table->longText('raw_smart_json')->nullable();
            $table->dateTime('observed_at');
            $table->timestamps();

            $table->index(['device_id', 'observed_at']);
            $table->index(['device_id', 'disk_key', 'observed_at']);
            $table->index(['risk_level', 'observed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('storage_health_observations');
    }
};
