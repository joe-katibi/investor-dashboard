<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A closed ticket put back to work: who, when, why, and the closure that was undone. */
#[Fillable([
    'outage_id', 'user_id', 'reason', 'previous_status_id', 'previous_closed_at', 'previous_closed_by',
    'previous_end_time', 'previous_sla_state', 'previous_root_cause', 'previous_resolution',
])]
class OutageReopening extends Model
{
    use Auditable;

    protected function casts(): array
    {
        return ['previous_closed_at' => 'datetime', 'previous_end_time' => 'datetime'];
    }

    public function outage(): BelongsTo
    {
        return $this->belongsTo(Outage::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function previousStatus(): BelongsTo
    {
        return $this->belongsTo(OutageStatus::class, 'previous_status_id');
    }

    public function previousClosedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'previous_closed_by');
    }
}
