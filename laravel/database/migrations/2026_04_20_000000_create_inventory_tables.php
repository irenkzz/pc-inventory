<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('devices', function (Blueprint $table) {
            $table->id();
            $table->string('device_uid')->unique();
            $table->string('status')->default('active');
            $table->dateTime('first_seen_at');
            $table->dateTime('last_seen_at');
            $table->string('current_asset_code')->nullable();
            $table->string('current_department')->nullable();
            $table->string('current_user_name')->nullable();
            $table->string('current_location')->nullable();
            $table->string('current_room')->nullable();
            $table->string('current_site')->nullable();
            $table->string('manufacturer')->nullable();
            $table->string('model')->nullable();
            $table->string('system_type')->nullable();
            $table->string('serial_no')->nullable();
            $table->string('motherboard_serial')->nullable();
            $table->string('system_uuid')->nullable();
            $table->string('mac_address')->nullable();
            $table->string('hardware_hash')->nullable();
            $table->timestamps();

            $table->index(['last_seen_at', 'id']);
            $table->index('current_asset_code');
            $table->index('current_site');
        });

        Schema::create('device_identities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('device_id')->constrained()->cascadeOnDelete();
            $table->string('identity_type');
            $table->string('identity_value');
            $table->unsignedSmallInteger('weight')->default(0);
            $table->dateTime('first_seen_at');
            $table->dateTime('last_seen_at');
            $table->timestamps();

            $table->unique(['identity_type', 'identity_value']);
            $table->index('device_id');
        });

        Schema::create('device_scans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('device_id')->constrained()->cascadeOnDelete();
            $table->dateTime('scan_time');
            $table->string('scan_source');
            $table->string('raw_hash', 64)->unique();
            $table->string('raw_filename')->nullable();
            $table->string('raw_format');
            $table->timestamp('ingested_at')->useCurrent();
            $table->timestamps();

            $table->index(['device_id', 'scan_time']);
        });

        Schema::create('hardware_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('device_scan_id')->unique()->constrained('device_scans')->cascadeOnDelete();
            $table->string('asset_code')->nullable();
            $table->string('department')->nullable();
            $table->string('location')->nullable();
            $table->string('room')->nullable();
            $table->string('site_estimated')->nullable();
            $table->string('site_method')->nullable();
            $table->string('site_confidence')->nullable();
            $table->string('user_name')->nullable();
            $table->string('user_raw')->nullable();
            $table->string('manufacturer')->nullable();
            $table->string('model')->nullable();
            $table->string('system_type')->nullable();
            $table->string('motherboard')->nullable();
            $table->string('motherboard_manufacturer')->nullable();
            $table->string('motherboard_product')->nullable();
            $table->string('motherboard_version')->nullable();
            $table->string('motherboard_serial')->nullable();
            $table->text('cpu')->nullable();
            $table->text('gpu')->nullable();
            $table->text('gpu_detail')->nullable();
            $table->string('ram_gb')->nullable();
            $table->string('ram_slots_used')->nullable();
            $table->text('ram_detail')->nullable();
            $table->text('disk')->nullable();
            $table->text('disk_detail')->nullable();
            $table->string('os')->nullable();
            $table->string('os_version')->nullable();
            $table->string('os_build')->nullable();
            $table->string('os_install_date')->nullable();
            $table->string('bios_serial_raw')->nullable();
            $table->string('serial_no')->nullable();
            $table->string('bios_version')->nullable();
            $table->string('system_uuid')->nullable();
            $table->string('mac_address')->nullable();
            $table->string('ip_address')->nullable();
            $table->string('ip_prefix')->nullable();
            $table->string('prefix_length')->nullable();
            $table->string('default_gateway')->nullable();
            $table->string('dns_suffix')->nullable();
            $table->string('network_interface')->nullable();
            $table->string('wifi_ssid')->nullable();
            $table->text('installed_printers')->nullable();
            $table->text('present_peripherals')->nullable();
            $table->string('peripheral_count')->nullable();
            $table->string('canonical_device_id', 64)->nullable();
            $table->string('hardware_hash', 64)->nullable();
            $table->string('scan_time_display')->nullable();
            $table->json('snapshot_json');
            $table->timestamps();
        });

        Schema::create('network_observations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('device_id')->constrained()->cascadeOnDelete();
            $table->foreignId('device_scan_id')->constrained('device_scans')->cascadeOnDelete();
            $table->string('mac_address')->nullable();
            $table->string('ip_address')->nullable();
            $table->string('ip_prefix')->nullable();
            $table->string('prefix_length')->nullable();
            $table->string('default_gateway')->nullable();
            $table->string('dns_suffix')->nullable();
            $table->string('network_interface')->nullable();
            $table->string('wifi_ssid')->nullable();
            $table->dateTime('observed_at');
            $table->timestamps();

            $table->index(['device_id', 'observed_at']);
        });

        Schema::create('peripherals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('device_id')->constrained()->cascadeOnDelete();
            $table->foreignId('device_scan_id')->constrained('device_scans')->cascadeOnDelete();
            $table->string('peripheral_type');
            $table->string('name');
            $table->text('detail')->nullable();
            $table->string('source')->nullable();
            $table->dateTime('observed_at');
            $table->timestamps();

            $table->index(['device_id', 'observed_at']);
            $table->index('peripheral_type');
        });

        Schema::create('device_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('device_id')->constrained()->cascadeOnDelete();
            $table->string('user_name')->nullable();
            $table->string('department')->nullable();
            $table->string('location')->nullable();
            $table->string('room')->nullable();
            $table->string('site_estimated')->nullable();
            $table->dateTime('started_at');
            $table->dateTime('ended_at')->nullable();
            $table->foreignId('start_scan_id')->constrained('device_scans')->cascadeOnDelete();
            $table->foreignId('end_scan_id')->nullable()->constrained('device_scans')->nullOnDelete();
            $table->timestamps();

            $table->index(['device_id', 'started_at']);
        });

        Schema::create('change_log', function (Blueprint $table) {
            $table->id();
            $table->foreignId('device_id')->constrained()->cascadeOnDelete();
            $table->foreignId('device_scan_id')->constrained('device_scans')->cascadeOnDelete();
            $table->string('change_group');
            $table->string('severity')->default('minor');
            $table->string('field_name');
            $table->text('old_value')->nullable();
            $table->text('new_value')->nullable();
            $table->dateTime('observed_at');
            $table->string('observed_site')->nullable();
            $table->string('observed_department')->nullable();
            $table->string('observed_room')->nullable();
            $table->timestamps();

            $table->index(['device_id', 'observed_at']);
            $table->index(['change_group', 'severity']);
        });

        Schema::create('raw_files', function (Blueprint $table) {
            $table->id();
            $table->string('raw_hash', 64)->unique();
            $table->string('original_filename')->nullable();
            $table->string('saved_path');
            $table->string('raw_format');
            $table->timestamp('received_at')->useCurrent();
            $table->json('metadata_json')->nullable();
            $table->timestamps();
        });

        Schema::create('collector_sites', function (Blueprint $table) {
            $table->string('site_id')->primary();
            $table->string('site_name')->nullable();
            $table->text('description')->nullable();
            $table->timestamps();
        });

        Schema::create('collectors', function (Blueprint $table) {
            $table->id();
            $table->string('site_id');
            $table->string('collector_name');
            $table->string('collector_version')->nullable();
            $table->string('share_root_hint')->nullable();
            $table->dateTime('last_seen_at')->nullable();
            $table->string('last_status')->nullable();
            $table->text('last_error')->nullable();
            $table->unsignedInteger('queue_depth_csv')->default(0);
            $table->unsignedInteger('queue_depth_heartbeat')->default(0);
            $table->json('raw_status_json')->nullable();
            $table->timestamps();

            $table->foreign('site_id')->references('site_id')->on('collector_sites')->cascadeOnDelete();
            $table->unique(['site_id', 'collector_name']);
            $table->index(['site_id', 'last_seen_at']);
        });

        Schema::create('runners', function (Blueprint $table) {
            $table->id();
            $table->string('runner_id')->unique();
            $table->string('hostname')->nullable();
            $table->string('site_id')->nullable();
            $table->string('collector_name')->nullable();
            $table->string('runner_version')->nullable();
            $table->string('install_mode')->nullable();
            $table->dateTime('last_seen_at')->nullable();
            $table->dateTime('last_successful_inventory_at')->nullable();
            $table->string('last_inventory_status')->nullable();
            $table->string('last_upload_status')->nullable();
            $table->text('last_error')->nullable();
            $table->dateTime('last_command_seen_at')->nullable();
            $table->string('last_command_type')->nullable();
            $table->json('raw_state_json')->nullable();
            $table->timestamps();

            $table->foreign('site_id')->references('site_id')->on('collector_sites')->nullOnDelete();
            $table->index(['site_id', 'last_seen_at']);
            $table->index('hostname');
        });

        Schema::create('runner_commands', function (Blueprint $table) {
            $table->id();
            $table->string('runner_id');
            $table->string('site_id')->nullable();
            $table->string('command_type');
            $table->json('payload_json')->nullable();
            $table->string('status')->default('pending');
            $table->string('requested_by')->nullable();
            $table->timestamp('requested_at')->useCurrent();
            $table->dateTime('dispatched_at')->nullable();
            $table->dateTime('acknowledged_at')->nullable();
            $table->dateTime('completed_at')->nullable();
            $table->string('completion_status')->nullable();
            $table->text('completion_message')->nullable();
            $table->timestamps();

            $table->foreign('runner_id')->references('runner_id')->on('runners')->cascadeOnDelete();
            $table->foreign('site_id')->references('site_id')->on('collector_sites')->nullOnDelete();
            $table->index(['site_id', 'status', 'requested_at']);
            $table->index(['runner_id', 'requested_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('runner_commands');
        Schema::dropIfExists('runners');
        Schema::dropIfExists('collectors');
        Schema::dropIfExists('collector_sites');
        Schema::dropIfExists('raw_files');
        Schema::dropIfExists('change_log');
        Schema::dropIfExists('device_assignments');
        Schema::dropIfExists('peripherals');
        Schema::dropIfExists('network_observations');
        Schema::dropIfExists('hardware_snapshots');
        Schema::dropIfExists('device_scans');
        Schema::dropIfExists('device_identities');
        Schema::dropIfExists('devices');
    }
};
