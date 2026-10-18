<?php

namespace App\Console\Commands;

use App\Services\Fifo\FifoAssignmentEngine;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('oms:fifo-sweep')]
#[Description('Release assignments of users who became ineligible (leave, shift end, unavailable) and assign waiting tickets')]
class FifoSweepCommand extends Command
{
    public function handle(FifoAssignmentEngine $engine): int
    {
        $released = $engine->sweepIneligibleAssignees();
        $reopened = $engine->reopenWaiting();
        $assigned = $engine->process();

        $this->info("Released {$released}, offered {$reopened} waiting ticket(s) to everyone again, assigned {$assigned}.");

        return self::SUCCESS;
    }
}
