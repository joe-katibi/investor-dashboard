<?php

namespace App\Models;

use App\Enums\QueueEntryStatus;
use App\Enums\QueueType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'outage_id', 'queue_definition_id', 'queue_type', 'step_name', 'priority_id', 'priority_rank', 'department_id',
    'team_id', 'sub_team_id', 'queued_at', 'eligible_from', 'assigned_at', 'completed_at', 'status',
    'attempts', 'rejected_user_ids', 'auto_assign_blocked', 'escalated_at',
])]
class OutageQueueEntry extends Model
{
    /** Keep microseconds so FIFO order is exact even for tickets created in the same second. */
    public function getDateFormat(): string
    {
        return 'Y-m-d H:i:s.u';
    }

    protected function casts(): array
    {
        return [
            'auto_assign_blocked' => 'boolean',
            'queue_type' => QueueType::class,
            'status' => QueueEntryStatus::class,
            'queued_at' => 'datetime',
            'eligible_from' => 'datetime',
            'assigned_at' => 'datetime',
            'completed_at' => 'datetime',
            'escalated_at' => 'datetime',
            'sla_minutes' => 'integer',
            'sla_warn_at' => 'datetime',
            'sla_due_at' => 'datetime',
            'sla_warned_at' => 'datetime',
            'sla_breached_at' => 'datetime',
            'rejected_user_ids' => 'array',
            'attempts' => 'integer',
            'priority_rank' => 'integer',
        ];
    }

    public function outage(): BelongsTo
    {
        return $this->belongsTo(Outage::class);
    }

    public function queueDefinition(): BelongsTo
    {
        return $this->belongsTo(QueueDefinition::class);
    }

    public function priority(): BelongsTo
    {
        return $this->belongsTo(Priority::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function subTeam(): BelongsTo
    {
        return $this->belongsTo(Team::class, 'sub_team_id');
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(OutageAssignment::class, 'queue_entry_id');
    }

    public function activeAssignment(): HasOne
    {
        return $this->hasOne(OutageAssignment::class, 'queue_entry_id')->ofMany(['id' => 'max'], function (Builder $query): void {
            $query->whereIn('status', ['assigned', 'accepted']);
        });
    }

    public function scopeOpen(Builder $query): void
    {
        $query->whereIn('status', QueueEntryStatus::openValues());
    }

    public function scopePending(Builder $query): void
    {
        $query->where('status', QueueEntryStatus::Pending->value);
    }

    /**
     * Priority-aware FIFO ordering: priority rank first (configurable), then exact queue
     * time, then id as the final deterministic tie-breaker.
     */
    public function scopeFifoOrder(Builder $query, bool $priorityAware = true): void
    {
        if ($priorityAware) {
            $query->orderBy('priority_rank');
        }

        $query->orderBy('queued_at')->orderBy('id');
    }

    /** "NOC Review" — or, for a custom workflow, the name of the step ("Finance approval"). */
    public function label(): string
    {
        return filled($this->step_name) ? (string) $this->step_name : $this->queue_type->getLabel();
    }

    public function hasRejected(int $userId): bool
    {
        return in_array($userId, $this->rejected_user_ids ?? [], true);
    }
}
