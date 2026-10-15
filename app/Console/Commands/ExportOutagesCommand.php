<?php

namespace App\Console\Commands;

use App\Services\Outages\OutageTransfer;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

#[Signature('oms:export-outages
    {folder : Where to write the export (a new folder)}
    {--days=14 : Outages raised or changed in the last N days}
    {--since= : Or from this date / time instead (e.g. "2026-10-10")}')]
#[Description('Export the FTTH network outages of a recent period, with everything attached to them and their photos, to move them to another OMS server (oms:import-outages there)')]
class ExportOutagesCommand extends Command
{
    public function handle(OutageTransfer $transfer): int
    {
        $since = filled($this->option('since')) ? Carbon::parse($this->option('since')) : now()->subDays(max(1, (int) $this->option('days')))->startOfDay();
        $folder = rtrim((string) $this->argument('folder'), '/');

        if (is_dir($folder) && glob($folder.'/*') !== []) {
            $this->error("{$folder} is not empty: give a new folder.");

            return self::FAILURE;
        }

        // FTTH network outages: the outage workflow (not 4G, customer tickets, site visits or custom flows).
        $counts = $transfer->export($since, $folder, fn ($query) => $query->standard());

        $this->info("Exported the outages raised or changed since {$since->format('d M Y H:i')} to {$folder}:");
        $this->table(['What', 'Rows'], collect($counts)->map(fn (int $n, string $what) => [$what, $n])->values()->all());
        $this->line('Copy the folder to the new server, e.g.:');
        $this->line('  tar czf outages-export.tgz -C '.dirname($folder).' '.basename($folder));
        $this->line('  then on the new server: tar xzf outages-export.tgz && php artisan oms:import-outages <folder>');

        return self::SUCCESS;
    }
}
