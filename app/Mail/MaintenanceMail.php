<?php

namespace App\Mail;

use App\Models\ChangeRequest;
use App\Models\NotificationLog;
use App\Services\Maintenance\CrfPdf;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Support\Facades\Storage;

/**
 * Planned-maintenance e-mail rendered from an editable template. The CRF PDF (and the customer
 * list, when uploaded) is generated at send time so it always carries the latest signatures.
 */
class MaintenanceMail extends Mailable
{
    public function __construct(public NotificationLog $log) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: (string) $this->log->subject);
    }

    public function content(): Content
    {
        return new Content(view: 'emails.scheduled-report', with: [
            'subjectLine' => (string) $this->log->subject,
            // Rendered by TemplateRenderer: template text and values are escaped.
            'html' => (string) ($this->log->meta['html'] ?? e((string) $this->log->body)),
        ]);
    }

    public function attachments(): array
    {
        if (empty($this->log->meta['attach_crf'])) {
            return [];
        }

        $changeRequest = ChangeRequest::query()->with('outage')->find($this->log->meta['change_request_id'] ?? 0);

        if (! $changeRequest) {
            return [];
        }

        $attachments = [
            Attachment::fromData(fn () => app(CrfPdf::class)->render($changeRequest), $changeRequest->pdfFileName())
                ->withMime('application/pdf'),
        ];

        if ($changeRequest->customer_list_path && Storage::disk('local')->exists($changeRequest->customer_list_path)) {
            $attachments[] = Attachment::fromStorageDisk('local', $changeRequest->customer_list_path)
                ->as($changeRequest->customer_list_name ?: basename($changeRequest->customer_list_path));
        }

        return $attachments;
    }
}
