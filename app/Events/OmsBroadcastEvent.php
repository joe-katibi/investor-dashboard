<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Base for all real-time events (spec §27.9). Events are queued (ShouldBroadcast) and only
 * dispatched after the surrounding transaction commits, so a websocket outage can never
 * roll back or block an operational action. Payloads carry identifiers only — clients
 * re-query through authorised Livewire components, so no data leaks over the socket.
 */
abstract class OmsBroadcastEvent implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable, InteractsWithSockets;

    public const OPERATIONS_CHANNEL = 'oms.operations';

    /** Every event name the UI listens to, used to build Livewire echo listeners. */
    public const NAMES = [
        'OutageCreated', 'OutageUpdated', 'OutageAssigned', 'OutageAccepted', 'OutageRejected',
        'AssignmentTimeout', 'OutageStatusChanged', 'PriorityChanged', 'TechnicianAvailabilityChanged',
    ];

    /** @param list<int> $userIds users who additionally get the event on their private channel */
    public function __construct(
        public ?int $outageId = null,
        public ?string $ticketNumber = null,
        public array $userIds = [],
        public array $meta = [],
    ) {
        $this->dontBroadcastToCurrentUser();
    }

    public function broadcastOn(): array
    {
        $channels = [new PrivateChannel(self::OPERATIONS_CHANNEL)];

        foreach (array_unique(array_filter($this->userIds)) as $userId) {
            $channels[] = new PrivateChannel('App.Models.User.'.$userId);
        }

        return $channels;
    }

    public function broadcastAs(): string
    {
        return class_basename(static::class);
    }

    public function broadcastWith(): array
    {
        return [
            'outage_id' => $this->outageId,
            'ticket' => $this->ticketNumber,
            'meta' => $this->meta,
        ];
    }
}
