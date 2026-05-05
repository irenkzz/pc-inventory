<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     */
    protected function schedule(Schedule $schedule): void
    {
        if ((bool) config('inventory.backup_schedule_enabled')) {
            $command = 'inventory:backup --label=scheduled';
            $retentionDays = config('inventory.backup_retention_days');

            if ((bool) config('inventory.backup_include_downloads')) {
                $command .= ' --include-downloads';
            }

            if ($retentionDays !== null && $retentionDays !== '') {
                $command .= ' --prune-days=' . (int) $retentionDays;
            }

            $schedule->command($command)
                ->dailyAt((string) config('inventory.backup_schedule_time', '01:30'))
                ->withoutOverlapping();
        }
    }

    /**
     * Register the commands for the application.
     */
    protected function commands(): void
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}
