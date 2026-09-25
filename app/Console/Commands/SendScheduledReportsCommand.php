<?php

namespace App\Console\Commands;

use App\Services\Reports\ScheduledReportService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('oms:send-scheduled-reports')]
#[Description('Queue scheduled management emails (two-hour update, executive summary, summary report) that are due')]
class SendScheduledReportsCommand extends Command
{
    public function handle(ScheduledReportService $service): int
    {
        $queued = $service->dispatchDue();
        $this->info("{$queued} scheduled report(s) queued.");

        return self::SUCCESS;
    }
}
