<?php

namespace App\Http\Controllers;

use App\Models\OutageAttachment;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Ticket attachments are private: only signed-in users who may view the ticket can download them. */
class OutageAttachmentController extends Controller
{
    public function __invoke(OutageAttachment $attachment): StreamedResponse
    {
        Gate::authorize('view', $attachment->outage);
        abort_unless(Storage::disk('local')->exists($attachment->path), 404);

        // Always a download: an uploaded file is never rendered in the browser on our origin.
        return Storage::disk('local')->download($attachment->path, $attachment->name(), [
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
