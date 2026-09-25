<?php

namespace App\Console\Commands;

use App\Services\Sla\SlaService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('oms:sla-check')]
#[Description('Evaluate SLA state and send pre-breach, breach, credit-due and escalation notices')]
class SlaCheckCommand extends Command
{
    public function handle(SlaService $sla): int
    {
        $changed = $sla->check();
        $this->info("SLA check complete: {$changed} outage(s) updated.");

        return self::SUCCESS;
    }
}
