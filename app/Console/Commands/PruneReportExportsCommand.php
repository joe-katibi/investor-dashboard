<?php

namespace App\Console\Commands;

use App\Models\ReportExport;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

#[Signature('oms:prune-report-exports')]
#[Description('Remove report exports older than six hours (file and row), and close the ones whose worker died')]
class PruneReportExportsCommand extends Command
{
    public function handle(): int
    {
        $removed = 0;

        ReportExport::query()->where('expires_at', '<=', now())->eachById(function (ReportExport $export) use (&$removed): void {
            $export->purge();
            $removed++;
        });

        // Still "being prepared" long after it started: the worker went away. Say so; the row goes at its own time.
        $stuck = ReportExport::query()
            ->whereIn('status', [ReportExport::QUEUED, ReportExport::RUNNING])
            ->where('updated_at', '<', now()->subMinutes(ReportExport::STUCK_MINUTES))
            ->update(['status' => ReportExport::FAILED, 'error' => 'It was not prepared in time. Try again.', 'finished_at' => now()]);

        // A file without its row (a run that was cut off half-way) is not kept either.
        $disk = Storage::disk(ReportExport::DISK);
        $known = ReportExport::query()->whereNotNull('path')->pluck('path')->flip();
        $orphans = 0;

        foreach ($disk->files(ReportExport::DIRECTORY) as $file) {
            if (! isset($known[$file]) && $disk->lastModified($file) < now()->subHours(ReportExport::KEEP_HOURS)->getTimestamp()) {
                $disk->delete($file);
                $orphans++;
            }
        }

        if ($removed + $stuck + $orphans > 0) {
            $this->info("Report exports: {$removed} removed, {$stuck} closed as not prepared, {$orphans} stray file(s) deleted.");
        }

        return self::SUCCESS;
    }
}
