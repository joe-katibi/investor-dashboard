<?php

namespace App\Console\Commands;

use App\Services\Fifo\FifoAssignmentEngine;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('oms:fifo-timeouts')]
#[Description('Requeue or escalate FIFO assignments whose acknowledgement window expired')]
class FifoTimeoutsCommand extends Command
{
    public function handle(FifoAssignmentEngine $engine): int
    {
        $count = $engine->processTimeouts();
        $this->info("{$count} assignment(s) timed out.");

        return self::SUCCESS;
    }
}
