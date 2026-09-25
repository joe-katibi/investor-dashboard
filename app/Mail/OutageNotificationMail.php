<?php

namespace App\Mail;

use App\Models\NotificationLog;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class OutageNotificationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public NotificationLog $log) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: (string) $this->log->subject);
    }

    public function content(): Content
    {
        return new Content(view: 'emails.outage-notification', with: [
            'subjectLine' => $this->log->subject,
            'body' => $this->log->body,
            'url' => $this->log->outage_id ? route('filament.admin.resources.outages.view', ['record' => $this->log->outage_id]) : null,
        ]);
    }
}
