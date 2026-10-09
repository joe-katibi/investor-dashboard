<?php

namespace App\Console\Commands;

use App\Services\Sla\DeskSla;
use App\Services\Sla\SlaService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('oms:sla-check')]
#[Description('Evaluate SLA state and send pre-breach, breach, credit-due and escalation notices — for each ticket, each desk it waits at, and the time to field')]
class SlaCheckCommand extends Command
{
    public function handle(SlaService $sla, DeskSla $desks): int
    {
        $changed = $sla->check();
        $this->info("SLA check complete: {$changed} outage(s) updated.");

        $desk = $desks->check();

        if (array_sum($desk) > 0) {
            $this->info("Desk SLA: {$desk['warned']} warning(s), {$desk['breached']} breach(es); time to field: {$desk['field_warned']} warning(s), {$desk['field_breached']} breach(es).");
        }

        return self::SUCCESS;
    }
}
