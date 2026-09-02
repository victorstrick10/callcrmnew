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
        $schedule->command('multilogin:refresh-tokens')->everyTenMinutes()->withoutOverlapping();
        $schedule->command('leads:sync')->everyFifteenMinutes();
        $schedule->command('ipinfo:enrich')->everyFifteenMinutes()->withoutOverlapping();
        $schedule->command('profiles:sync-numbers')->everyFifteenMinutes()->withoutOverlapping();
        $schedule->command('proxies:check')->everyFifteenMinutes()->withoutOverlapping();
        $schedule->command('proxies:prepare-geo')->everyTenMinutes()->withoutOverlapping();
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
