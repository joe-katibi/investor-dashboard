<?php

namespace App\Console\Commands;

use App\Services\Tickets\FourGTicketMonitor;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('oms:ticket-progress')]
#[Description('4G customer tickets: remind whoever holds one when no update has been posted for a while, and remind silent vendors by e-mail')]
class TicketProgressCommand extends Command
{
    public function handle(FourGTicketMonitor $monitor): int
    {
        $done = $monitor->check();

        if (array_sum($done) > 0) {
            $this->info("4G tickets: {$done['updates_due']} update reminder(s), {$done['vendors_reminded']} vendor reminder(s).");
        }

        return self::SUCCESS;
    }
}
