<?php

namespace App\Mail;

use App\Models\NotificationLog;
use App\Models\Outage;
use App\Support\MailSenders;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** E-mail to an external vendor, rendered from an editable vendor template (4G NOC). */
class VendorNotificationMail extends Mailable
{
    public function __construct(public NotificationLog $log) {}

    public function envelope(): Envelope
    {
        return new Envelope(from: MailSenders::address($this->sender()), subject: (string) $this->log->subject);
    }

    public function content(): Content
    {
        return new Content(view: 'emails.vendor-notification', with: [
            'subjectLine' => (string) $this->log->subject,
            // Rendered by TemplateRenderer: template text and values are escaped.
            'html' => (string) ($this->log->meta['html'] ?? e((string) $this->log->body)),
        ]);
    }

    /** A vendor hears from the 4G tickets alias about a customer ticket, from the 4G outages alias otherwise. */
    private function sender(): string
    {
        $outage = $this->log->outage_id ? Outage::query()->whereKey($this->log->outage_id)->first(['id', 'workflow']) : null;

        return $outage?->isTicket() ? MailSenders::MOBILITY_TICKETS : MailSenders::MOBILITY_OUTAGES;
    }
}
