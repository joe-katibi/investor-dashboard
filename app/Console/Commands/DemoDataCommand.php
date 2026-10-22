<?php

namespace App\Console\Commands;

use App\Services\Performance\DemoGenerator;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('oms:demo-data
    {--backfill : Replace the demo history: fill the last 6 months where the real sources have no rows}
    {--tick : Live: add demo site visits at the real average rate and close those that are due (scheduled every minute)}')]
#[Description('Demo outages / site visits for the periods without real data (always marked as demo data)')]
class DemoDataCommand extends Command
{
    public function handle(DemoGenerator $demo): int
    {
        if ($this->option('backfill')) {
            $r = $demo->backfill();
            $this->info("Demo history: {$r['visits']} site visits, {$r['outages']} outages.");
            $this->line('  Site visit gaps: '.(implode(', ', $r['visit_gaps']) ?: 'none'));
            $this->line('  Outage gaps: '.(implode(', ', $r['outage_gaps']) ?: 'none'));
        }

        if ($this->option('tick')) {
            $r = $demo->tick();
            $this->line("Live demo: {$r['created']} new site visit(s), {$r['closed']} closed.");
        }

        if (! $this->option('backfill') && ! $this->option('tick')) {
            $this->error('Choose --backfill and/or --tick.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
