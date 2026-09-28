<?php

namespace App\Console\Commands;

use App\Services\Maintenance\MaintenanceScheduler;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('oms:maintenance-tick')]
#[Description('Send planned-maintenance reminders, before-start e-mails and "maintenance started" alerts that are due')]
class MaintenanceTickCommand extends Command
{
    public function handle(MaintenanceScheduler $scheduler): int
    {
        $sent = $scheduler->tick();
        $this->info("{$sent} maintenance notification(s) sent.");

        return self::SUCCESS;
    }
}
