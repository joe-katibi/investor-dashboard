<?php

namespace App\Console\Commands;

use App\Services\Tickets\TicketFollowUpMonitor;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('oms:ticket-tick')]
#[Description('Customer tickets: queue tickets pending past the follow-up time for a customer call and send the ready-for-credit list')]
class TicketTickCommand extends Command
{
    public function handle(TicketFollowUpMonitor $monitor): int
    {
        $result = $monitor->tick();

        if ($result['flagged'] > 0 || $result['notified'] > 0 || $result['aftercare'] > 0) {
            $this->info("{$result['flagged']} ticket(s) placed in the follow-up queue; {$result['notified']} listed as ready for credit; {$result['aftercare']} site visit(s) due for the 72-hour call.");
        }

        return self::SUCCESS;
    }
}
