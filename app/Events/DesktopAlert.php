<?php

namespace App\Events;

use App\Filament\Support\OutageLink;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Support\Str;

/**
 * Tells the person's open OMS pages, over Reverb, that they have a new notification, so the
 * browser can show a desktop notification — also when OMS is in a background tab or the person is
 * working in another program. (Without Reverb the pages find the same notification by asking the
 * server every little while: see DesktopAlertController.)
 *
 * Sent on the person's own private channel, like the bell. What is shown on the desktop is kept
 * to a title and the ticket number: no customer details appear on a screen someone else may see.
 * Clicking it opens the ticket, where the usual permission check applies.
 */
class DesktopAlert implements ShouldBroadcast
{
    use Dispatchable;

    /** @param array{id: string, title: string, body: string, url: ?string, severity: string, ticket: ?string, at: string} $alert */
    public function __construct(public int $userId, public array $alert) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('App.Models.User.'.$this->userId)];
    }

    public function broadcastAs(): string
    {
        return 'desktop.alert';
    }

    public function broadcastWith(): array
    {
        return $this->alert;
    }

    public function broadcastQueue(): string
    {
        return (string) config('oms.queues.notifications');
    }

    /**
     * What the browser shows — the same from the live event and from the "anything new?" request.
     *
     * @return array{id: string, title: string, body: string, url: ?string, severity: string, ticket: ?string, at: string}
     */
    public static function describe(string $id, ?string $title, ?string $type, ?int $ticketId, ?string $ticketNumber, ?string $text = null, mixed $at = null): array
    {
        $type = (string) $type;

        return [
            'id' => $id,
            'title' => Str::limit(trim((string) $title) ?: 'OMS notification', 120),
            'body' => $ticketNumber
                ? "Ticket {$ticketNumber} — click to open it in OMS."
                : (Str::limit(trim(strip_tags(str_replace(['<br />', '<br>', '<br/>'], ' ', (string) $text))), 140) ?: 'Open OMS to see it.'),
            // The ticket's page; the permission check happens there when it is opened.
            'url' => $ticketId ? OutageLink::url($ticketId) : null,
            'severity' => str_contains($type, 'breach') || str_contains($type, 'escalation') ? 'critical' : 'info',
            'ticket' => $ticketNumber,
            'at' => ($at ?? now())->toIso8601String(),
        ];
    }
}
