<?php

namespace App\Http\Controllers;

use App\Models\ReportExport;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Download of a report export prepared in the background. The file sits on the private disk:
 * it is only ever served here, to the person who asked for it (or to someone who reads the audit
 * trail and could have run that report), while it is kept.
 */
class ReportExportController extends Controller
{
    public function __invoke(Request $request, ReportExport $export): StreamedResponse
    {
        return self::send($export, $request->user());
    }

    /** The file as a download, for this person — also what the Download button of the list returns. */
    public static function send(ReportExport $export, User $user): StreamedResponse
    {
        abort_unless($user->can('reports.export'), 403);
        abort_unless(ReportExport::query()->visibleTo($user)->whereKey($export->id)->exists(), 404);
        abort_unless($export->isReady() && Storage::disk(ReportExport::DISK)->exists($export->path), 410, 'This report is no longer kept. Export it again from Reports.');
        abort_unless($export->downloadableBy($user), 403);

        // Who took which report out of the system, and whose it was.
        $export->loadMissing('user');
        $user->audit('report_downloaded', array_filter([
            'report' => $export->title,
            'format' => strtoupper($export->format),
            'period' => $export->periodLabel(),
            'pulled_by' => $export->user_id === $user->id ? null : $export->user?->name,
        ]));

        return Storage::disk(ReportExport::DISK)->download($export->path, $export->file_name, ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }
}
