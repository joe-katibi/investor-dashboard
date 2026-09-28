<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A support technician (drop cable → customer premises) requested on an ongoing outage by the
 * Infrastructure team. Worked in parallel with the outage technician through its own FIFO queue.
 */
#[Fillable(['outage_id', 'requested_by', 'work_required', 'customer_details', 'location', 'status', 'queue_entry_id'])]
class SupportRequest extends Model
{
    public const OPEN = 'open';

    public const COMPLETED = 'completed';

    public const CANCELLED = 'cancelled';

    protected function casts(): array
    {
        return [
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function scopeOpen(Builder $query): void
    {
        $query->where('status', self::OPEN);
    }

    public function isOpen(): bool
    {
        return $this->status === self::OPEN;
    }

    public function outage(): BelongsTo
    {
        return $this->belongsTo(Outage::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function completer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    public function queueEntry(): BelongsTo
    {
        return $this->belongsTo(OutageQueueEntry::class);
    }
}
