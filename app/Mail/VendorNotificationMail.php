<?php

namespace App\Mail;

use App\Models\NotificationLog;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** E-mail to an external vendor, rendered from an editable vendor template (4G NOC). */
class VendorNotificationMail extends Mailable
{
    public function __construct(public NotificationLog $log) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: (string) $this->log->subject);
    }

    public function content(): Content
    {
        return new Content(view: 'emails.vendor-notification', with: [
            'subjectLine' => (string) $this->log->subject,
            // Rendered by TemplateRenderer: template text and values are escaped.
            'html' => (string) ($this->log->meta['html'] ?? e((string) $this->log->body)),
        ]);
    }
}
