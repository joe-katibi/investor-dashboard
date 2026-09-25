<?php

namespace App\Models;

use App\Enums\AssignmentStatus;
use App\Enums\AssignmentType;
use App\Enums\QueueType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'outage_id', 'queue_entry_id', 'user_id', 'team_id', 'sub_team_id', 'queue_type', 'assignment_type',
    'queue_position', 'priority_id', 'priority_name', 'queued_at', 'assigned_at', 'ack_due_at',
    'accepted_at', 'rejected_at', 'released_at', 'timed_out_at', 'completed_at', 'status',
    'assignment_reason', 'release_reason', 'decision_meta', 'assigned_by',
])]
class OutageAssignment extends Model
{
    public function getDateFormat(): string
    {
        return 'Y-m-d H:i:s.u';
    }

    protected function casts(): array
    {
        return [
            'queue_type' => QueueType::class,
            'assignment_type' => AssignmentType::class,
            'status' => AssignmentStatus::class,
            'decision_meta' => 'array',
            'queued_at' => 'datetime',
            'assigned_at' => 'datetime',
            'ack_due_at' => 'datetime',
            'accepted_at' => 'datetime',
            'rejected_at' => 'datetime',
            'released_at' => 'datetime',
            'timed_out_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function outage(): BelongsTo
    {
        return $this->belongsTo(Outage::class);
    }

    public function queueEntry(): BelongsTo
    {
        return $this->belongsTo(OutageQueueEntry::class, 'queue_entry_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function subTeam(): BelongsTo
    {
        return $this->belongsTo(Team::class, 'sub_team_id');
    }

    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }

    public function scopeActive(Builder $query): void
    {
        $query->whereIn('status', AssignmentStatus::activeValues());
    }

    public function isActive(): bool
    {
        return in_array($this->status, [AssignmentStatus::Assigned, AssignmentStatus::Accepted], true);
    }
}
