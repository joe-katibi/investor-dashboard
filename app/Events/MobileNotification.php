<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Tells the mobile app, over Reverb, that this person has a new notification — the instant path
 * while the app is open. (When the app is in the background or closed, the same notification
 * reaches the phone as a Firebase push: see SendPushNotificationJob.)
 *
 * Sent on the person's own private channel (the one the web bell uses), so only they can receive
 * it. The payload is deliberately small — what kind of event, which ticket, a title. The app shows
 * it and, when the person opens it, asks the API for the ticket: the server decides then whether
 * they may see it. Nothing here grants access to anything.
 */
class MobileNotification implements ShouldBroadcast
{
    use Dispatchable;

    public function __construct(
        public int $userId,
        public string $notificationId,
        public string $type,
        public string $title,
        public ?int $ticketId = null,
        public ?string $ticketNumber = null,
    ) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('App.Models.User.'.$this->userId)];
    }

    public function broadcastAs(): string
    {
        return 'mobile.notification';
    }

    public function broadcastWith(): array
    {
        return [
            'id' => $this->notificationId,
            'type' => $this->type,
            'title' => $this->title,
            'ticket_id' => $this->ticketId,
            'ticket_number' => $this->ticketNumber,
        ];
    }
}
