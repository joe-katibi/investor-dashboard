<?php

namespace App\Mail;

use App\Models\NotificationLog;
use App\Models\OutagePhoto;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;

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

    /** Site photos of field maintenance (only those of the same ticket). */
    public function attachments(): array
    {
        $ids = (array) ($this->log->meta['photo_ids'] ?? []);

        if ($ids === [] || ! $this->log->outage_id) {
            return [];
        }

        return OutagePhoto::query()->where('outage_id', $this->log->outage_id)->whereIn('id', $ids)->get()
            ->filter(fn (OutagePhoto $photo) => Storage::disk('local')->exists($photo->path))
            ->map(fn (OutagePhoto $photo) => Attachment::fromStorageDisk('local', $photo->path)
                ->as(str($photo->label())->slug().'.'.pathinfo($photo->path, PATHINFO_EXTENSION)))
            ->values()->all();
    }
}
