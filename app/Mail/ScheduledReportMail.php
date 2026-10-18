<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class ScheduledReportMail extends Mailable
{
    use Queueable;

    public function __construct(
        public string $subjectLine,
        public string $bodyHtml,
        public ?string $attachmentPath = null,
        public string $attachmentName = 'report',
        public ?Address $sender = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(from: $this->sender, subject: $this->subjectLine);
    }

    public function content(): Content
    {
        return new Content(view: 'emails.scheduled-report', with: [
            'subjectLine' => $this->subjectLine,
            'html' => $this->bodyHtml,
        ]);
    }

    public function attachments(): array
    {
        if (! $this->attachmentPath || ! is_file($this->attachmentPath)) {
            return [];
        }

        return [
            Attachment::fromPath($this->attachmentPath)
                ->as($this->attachmentName.'-'.now()->format('Ymd-Hi').'.xlsx')
                ->withMime('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'),
        ];
    }
}
