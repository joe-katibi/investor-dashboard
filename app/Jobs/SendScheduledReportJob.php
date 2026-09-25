<?php

namespace App\Jobs;

use App\Services\Reports\ScheduledReportService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SendScheduledReportJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 300;

    public function __construct(public int $templateId, public ?string $at = null)
    {
        $this->onQueue(config('oms.queues.reports'));
    }

    public function backoff(): array
    {
        return [60, 300];
    }

    public function handle(ScheduledReportService $service): void
    {
        $service->sendById($this->templateId, $this->at);
    }
}
