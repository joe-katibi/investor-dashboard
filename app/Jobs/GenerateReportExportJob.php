<?php

namespace App\Jobs;

use App\Filament\Pages\ReportDownloads;
use App\Models\ReportExport;
use App\Services\Reports\ReportExporter;
use App\Services\Reports\ReportRegistry;
use Filament\Actions\Action;
use Filament\Notifications\Events\DatabaseNotificationsSent;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Writes one report export to the private disk, away from the person's browser: a large period
 * takes as long as it takes here and nobody's page waits for it.
 *
 * The queue may hand a long job to a second worker while the first is still writing (the queue's
 * "retry after"); the lock makes that second run step aside instead of writing the file twice.
 */
class GenerateReportExportJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $timeout = 1800;

    public function __construct(public int $exportId)
    {
        $this->onQueue(config('oms.queues.exports'));
    }

    public function handle(ReportExporter $exporter, ReportRegistry $registry): void
    {
        $lock = Cache::lock('report-export:'.$this->exportId, $this->timeout + 60);

        if (! $lock->get()) {
            return;
        }

        try {
            $export = ReportExport::query()->with('user')->find($this->exportId);

            if (! $export || $export->status === ReportExport::READY || $export->status === ReportExport::FAILED) {
                return;
            }

            $this->write($export, $exporter, $registry);
        } finally {
            $lock->release();
        }
    }

    private function write(ReportExport $export, ReportExporter $exporter, ReportRegistry $registry): void
    {
        $user = $export->user;

        // Checked again now: the account may have been closed, or the role changed, since the request.
        if (! $user || ! $user->is_active || ! $user->can('reports.export') || ! isset(ReportRegistry::availableTo($user)[$export->report_key])) {
            $this->giveUp($export, 'You are no longer allowed to export this report.');

            return;
        }

        $export->update(['status' => ReportExport::RUNNING, 'started_at' => now(), 'error' => null]);

        $disk = Storage::disk(ReportExport::DISK);
        $path = ReportExport::DIRECTORY.'/'.Str::uuid().'.'.$export->format;
        $disk->makeDirectory(ReportExport::DIRECTORY);

        // Reports show each person the modules they may see: run it as the person who asked.
        $previous = Auth::user();
        Auth::setUser($user);

        try {
            $filters = $export->filters ?? [];
            $report = $registry->run($export->report_key, $filters);
            $exporter->store($report, $export->format, $filters, $disk->path($path));

            $export->update([
                'status' => ReportExport::READY,
                'path' => $path,
                'file_name' => $exporter->filename($report, $export->format),
                'size_bytes' => $disk->size($path),
                'finished_at' => now(),
                'expires_at' => now()->addHours(ReportExport::KEEP_HOURS),
            ]);
        } catch (Throwable $e) {
            $disk->delete($path);
            report($e);
            $this->giveUp($export, 'Something went wrong while preparing it. Try again, or choose a shorter period.');

            return;
        } finally {
            // A worker runs many jobs in one process: nobody stays signed in between them.
            $previous ? Auth::setUser($previous) : Auth::guard()->forgetUser();
        }

        $this->tell($export, 'Your report is ready', "{$export->title} ({$export->periodLabel()}) can be downloaded for the next ".ReportExport::KEEP_HOURS.' hours.', 'success');
    }

    /** The worker gave up on the job (crashed, or out of attempts): say so instead of "being prepared" for ever. */
    public function failed(?Throwable $e): void
    {
        $export = ReportExport::query()->with('user')->find($this->exportId);

        if ($export && $export->isPending() && ! Cache::lock('report-export:'.$this->exportId, 5)->get()) {
            return; // another run is still writing it
        }

        if ($export && $export->isPending()) {
            $this->giveUp($export, 'It took too long to prepare. Try again, or choose a shorter period.');
        }
    }

    private function giveUp(ReportExport $export, string $reason): void
    {
        $export->update(['status' => ReportExport::FAILED, 'error' => $reason, 'finished_at' => now()]);
        $this->tell($export, 'Your report could not be prepared', "{$export->title}: {$reason}", 'danger');
    }

    private function tell(ReportExport $export, string $title, string $body, string $status): void
    {
        if (! $export->user) {
            return;
        }

        try {
            // Stored for the bell right away (sendToDatabase() would queue a second job).
            $stored = Notification::make()->title($title)->body($body)->status($status)
                ->actions([Action::make('open')->label('Report downloads')->url(ReportDownloads::getUrl(panel: 'admin'))->markAsRead()])
                ->toDatabase();
            $stored->id = (string) Str::uuid();
            $export->user->notifyNow($stored);
            DatabaseNotificationsSent::dispatch($export->user);
        } catch (Throwable $e) {
            // The file is there either way; the list shows it.
            report($e);
        }
    }
}
