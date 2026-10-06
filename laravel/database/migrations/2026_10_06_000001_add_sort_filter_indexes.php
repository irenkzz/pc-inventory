<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('change_log', fn (Blueprint $t) => $t->index('observed_at', 'change_log_observed_at_idx'));
        Schema::table('device_scans', fn (Blueprint $t) => $t->index('scan_time', 'device_scans_scan_time_idx'));
        Schema::table('runners', function (Blueprint $t): void {
            $t->index('last_seen_at', 'runners_last_seen_at_idx');
            $t->index('runner_version', 'runners_runner_version_idx');
        });
        Schema::table('devices', fn (Blueprint $t) => $t->index('current_department', 'devices_current_department_idx'));

        // New index first: the runner_id FK needs a runner_id-leading index at all times (MariaDB).
        // The (site_id,status,requested_at) index is kept: poll/pendingForSite filter by site_id first.
        Schema::table('runner_commands', fn (Blueprint $t) => $t->index(['runner_id', 'status', 'requested_at'], 'runner_commands_runner_status_requested_idx'));
        Schema::table('runner_commands', fn (Blueprint $t) => $t->dropIndex(['runner_id', 'requested_at']));
    }

    public function down(): void
    {
        Schema::table('runner_commands', fn (Blueprint $t) => $t->index(['runner_id', 'requested_at']));
        Schema::table('runner_commands', fn (Blueprint $t) => $t->dropIndex('runner_commands_runner_status_requested_idx'));

        Schema::table('devices', fn (Blueprint $t) => $t->dropIndex('devices_current_department_idx'));
        Schema::table('runners', function (Blueprint $t): void {
            $t->dropIndex('runners_last_seen_at_idx');
            $t->dropIndex('runners_runner_version_idx');
        });
        Schema::table('device_scans', fn (Blueprint $t) => $t->dropIndex('device_scans_scan_time_idx'));
        Schema::table('change_log', fn (Blueprint $t) => $t->dropIndex('change_log_observed_at_idx'));
    }
};
