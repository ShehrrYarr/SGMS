<?php

use Illuminate\Support\Facades\Schedule;

/*
| Scheduled tasks. In production add one cron entry:
|   * * * * * cd /path/to/sgms && php artisan schedule:run >> /dev/null 2>&1
| (On Windows: Task Scheduler running "php artisan schedule:run" every minute.)
*/

// Daily stock valuation for the reports (keeps the cost that applied that day).
Schedule::command('sgms:stock-snapshot')->dailyAt('23:55')->withoutOverlapping();

// Public demo only (SGMS_DEMO=true): rebuild the demo data every night.
if (config('sgms.demo.enabled')) {
    Schedule::command('sgms:demo-reset', ['--force'])->dailyAt(config('sgms.demo.reset_at'));
}
