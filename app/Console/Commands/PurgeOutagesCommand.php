<?php

namespace App\Console\Commands;

use App\Services\Outages\OutagePurger;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('oms:purge-outages {--force : Do not ask for confirmation}')]
#[Description('Delete ALL outage tickets and their data (history, queues, notifications, CRFs, photos) and restart ticket numbering')]
class PurgeOutagesCommand extends Command
{
    public function handle(OutagePurger $purger): int
    {
        $counts = $purger->counts();

        $this->warn('This permanently deletes ALL outage tickets and everything attached to them.');
        $this->warn('Configuration, users, teams, topology, templates and settings are kept. Take a database backup first.');
        $this->table(['Will be removed', 'Count'], collect($counts)->map(fn (int $n, string $what) => [$what, $n])->values()->all());

        if (! $this->option('force') && $this->ask('Type PURGE to continue') !== 'PURGE') {
            $this->info('Cancelled — nothing deleted.');

            return self::FAILURE;
        }

        $purger->purge();
        $this->info("Done: {$counts['tickets']} tickets removed. Ticket numbering restarts at 1.");

        return self::SUCCESS;
    }
}
