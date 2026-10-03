<?php

namespace App\Models;

use App\Enums\QueueType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One hand-off of a ticket between desks. moved_at → returned_at is the escalation turnaround. */
class TicketDeskMove extends Model
{
    protected $guarded = [];

    public const KINDS = [
        'route' => 'Escalated', 'return' => 'Returned', 'approve' => 'Approved', 'decline' => 'Declined',
        'assign' => 'Dispatched to the field', 'confirm' => 'Sent for customer confirmation', 'reopen' => 'Reopened',
    ];

    protected function casts(): array
    {
        return [
            'from_desk' => QueueType::class,
            'to_desk' => QueueType::class,
            'moved_at' => 'datetime',
            'returned_at' => 'datetime',
        ];
    }

    public function outage(): BelongsTo
    {
        return $this->belongsTo(Outage::class);
    }

    public function movedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'moved_by');
    }

    public function delayReason(): BelongsTo
    {
        return $this->belongsTo(DelayReason::class);
    }

    public function kindLabel(): string
    {
        return self::KINDS[$this->kind] ?? (string) $this->kind;
    }
}
