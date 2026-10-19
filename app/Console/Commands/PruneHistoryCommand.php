<?php

namespace App\Console\Commands;

use App\Support\Settings;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

#[Signature('oms:prune-history {--dry-run : Only count what would be deleted}')]
#[Description('Delete old in-app notifications (read after 30 days, any after 90) and notification logs older than 180 days — days set in FIFO & SLA Settings → Housekeeping')]
class PruneHistoryCommand extends Command
{
    /** Rows per delete: short statements, so the tables are never locked for long. */
    private const CHUNK = 5000;

    public function handle(Settings $settings): int
    {
        $read = max(1, $settings->int('history.notifications_read_days'));
        $any = max($read, $settings->int('history.notifications_days'));
        $logs = max(30, $settings->int('history.notification_logs_days'));

        $targets = [
            "In-app notifications read more than {$read} days ago" => fn (): Builder => DB::table('notifications')->whereNotNull('read_at')->where('created_at', '<', now()->subDays($read)),
            "In-app notifications older than {$any} days" => fn (): Builder => DB::table('notifications')->where('created_at', '<', now()->subDays($any)),
            "Notification logs (e-mails, SMS sent) older than {$logs} days" => fn (): Builder => DB::table('notification_logs')->where('created_at', '<', now()->subDays($logs)),
        ];

        foreach ($targets as $label => $query) {
            $deleted = $this->option('dry-run') ? $query()->count() : $this->deleteInChunks($query);
            $this->line(($this->option('dry-run') ? 'Would delete ' : 'Deleted ').number_format($deleted).": {$label}");
        }

        return self::SUCCESS;
    }

    /** @param  callable(): Builder  $query */
    private function deleteInChunks(callable $query): int
    {
        $total = 0;

        do {
            $ids = $query()->orderBy('id')->limit(self::CHUNK)->pluck('id');
            $deleted = $ids->isEmpty() ? 0 : $query()->whereIn('id', $ids)->delete();
            $total += $deleted;
        } while ($deleted > 0);

        return $total;
    }
}
