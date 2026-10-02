<?php

namespace App\Console\Commands;

use App\Services\Mobility\VendorEscalationMonitor;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('oms:mobility-tick')]
#[Description('4G NOC: remind the NOC / escalate when a vendor has not updated a power outage ticket')]
class MobilityTickCommand extends Command
{
    public function handle(VendorEscalationMonitor $monitor): int
    {
        $raised = $monitor->tick();

        if ($raised > 0) {
            $this->info("Raised {$raised} vendor escalation(s).");
        }

        return self::SUCCESS;
    }
}
