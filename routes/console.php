<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| OMS scheduler
|--------------------------------------------------------------------------
| Run `php artisan schedule:work` (dev) or a cron entry for `schedule:run` on EVERY
| node. onOneServer() uses a cache lock so each task runs once cluster-wide, and
| withoutOverlapping() prevents a slow run from stacking up. Both require a shared
| cache store (redis or database) in multi-node deployments.
*/

Schedule::command('oms:sla-check')->everyMinute()->onOneServer()->withoutOverlapping(5);
Schedule::command('oms:fifo-timeouts')->everyMinute()->onOneServer()->withoutOverlapping(5);
Schedule::command('oms:fifo-sweep')->everyMinute()->onOneServer()->withoutOverlapping(5);
Schedule::command('oms:send-scheduled-reports')->everyMinute()->onOneServer()->withoutOverlapping(10);
Schedule::command('oms:maintenance-tick')->everyMinute()->onOneServer()->withoutOverlapping(5);
Schedule::command('oms:mobility-tick')->everyMinute()->onOneServer()->withoutOverlapping(5);
Schedule::command('oms:ticket-tick')->everyFiveMinutes()->onOneServer()->withoutOverlapping(10);

// The database cache driver never deletes expired rows on its own (Redis does); keep the table small.
Schedule::call(function (): void {
    if (config('cache.default') === 'database') {
        DB::table(config('cache.stores.database.table', 'cache'))->where('expiration', '<', now()->getTimestamp())->delete();
    }
})->name('oms:prune-expired-cache')->hourly()->onOneServer();

Schedule::command('queue:prune-failed --hours=720')->daily()->onOneServer();
Schedule::command('queue:prune-batches --hours=168')->daily()->onOneServer();

// Mobile app: sign-ins that expired more than a day ago are removed.
Schedule::command('sanctum:prune-expired --hours=24')->daily()->onOneServer();
