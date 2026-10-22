<?php

namespace App\Jobs;

use App\Services\Customers\OnuExportSync;
use App\Support\AuditContext;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * A dump sent in by the uploader (POST /api/integrations/onu-export/{source}) is read straight away. The sync
 * itself makes sure a dump is read once and one sync of a source runs at a time; the schedule reads the folder too.
 */
class SyncOnuExportJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    // A first sync writes ONU details to every customer: a few minutes on a large customer base.
    public int $timeout = 1800;

    /** @param  list<string>  $files  the parts of one dump */
    public function __construct(public string $source, public array $files, public ?string $dumpedAt)
    {
        $this->onQueue(config('oms.queues.reports'));
    }

    public function uniqueId(): string
    {
        return $this->source.'|'.$this->dumpedAt;
    }

    public function handle(OnuExportSync $sync): void
    {
        AuditContext::as('system:onu-export-sync', fn () => $sync->sync(
            $this->source, $this->files, $this->dumpedAt ? CarbonImmutable::parse($this->dumpedAt) : null, apply: true,
        ));

        $sync->prune((string) config("oms.onu_exports.{$this->source}.path"), (int) config('oms.onu_export_upload.keep_days'));
    }
}
