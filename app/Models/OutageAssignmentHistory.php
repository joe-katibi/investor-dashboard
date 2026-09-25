<?php

namespace App\Models;

use App\Enums\QueueType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'outage_id', 'assignment_id', 'queue_type', 'previous_user_id', 'new_user_id', 'action', 'reason',
    'performed_by', 'trigger', 'performed_at',
])]
class OutageAssignmentHistory extends Model
{
    protected function casts(): array
    {
        return [
            'queue_type' => QueueType::class,
            'performed_at' => 'datetime',
        ];
    }

    public function outage(): BelongsTo
    {
        return $this->belongsTo(Outage::class);
    }

    public function assignment(): BelongsTo
    {
        return $this->belongsTo(OutageAssignment::class);
    }

    public function previousUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'previous_user_id');
    }

    public function newUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'new_user_id');
    }

    public function performer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'performed_by');
    }
}
