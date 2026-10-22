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
// Old in-app notifications and notification logs (days: FIFO & SLA Settings → Housekeeping); quiet hours.
Schedule::command('oms:prune-history')->dailyAt('02:30')->onOneServer()->withoutOverlapping(60);
// A ticket "with" someone that is not in their My Work: its assignment is put back.
Schedule::command('oms:check-my-work --fix')->everyTenMinutes()->onOneServer()->withoutOverlapping(10);
Schedule::command('oms:send-scheduled-reports')->everyMinute()->onOneServer()->withoutOverlapping(10);
Schedule::command('oms:maintenance-tick')->everyMinute()->onOneServer()->withoutOverlapping(5);
Schedule::command('oms:mobility-tick')->everyMinute()->onOneServer()->withoutOverlapping(5);
Schedule::command('oms:ticket-tick')->everyFiveMinutes()->onOneServer()->withoutOverlapping(10);
// 4G customer tickets: an update is due at every step; silent vendors are reminded.
Schedule::command('oms:ticket-progress')->everyMinute()->onOneServer()->withoutOverlapping(5);

// Customers from the ONU exports (dumped at 08:00, 12:00, 17:00 and 21:00). The uploader on the UAT server sends each
// dump at :30 and it is read as it arrives; at :45 the folder is read as a backup (a dump is read once). Only once
// OMS_ONU_EXPORT_AUTO_APPLY is on — until then dumps are applied by hand.
foreach (config('oms.onu_export_upload.auto_apply') ? array_keys((array) config('oms.onu_exports')) : [] as $source) {
    foreach (['08:45', '12:45', '17:45', '21:45'] as $time) {
        Schedule::command("oms:sync-onu-export --source={$source} --apply")->dailyAt($time)
            ->name("oms:sync-onu-export:{$source}:".str_replace(':', '', $time))->onOneServer()->withoutOverlapping(120);
    }
}

// Report exports are kept six hours: the file and its row then go; a run whose worker died is closed.
Schedule::command('oms:prune-report-exports')->everyTenMinutes()->onOneServer()->withoutOverlapping(10);

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
