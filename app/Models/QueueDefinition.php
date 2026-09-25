<?php

namespace App\Models;

use App\Enums\QueueType;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasAuditColumns;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'name', 'queue_type', 'department_id', 'team_id', 'major_outage_type_id', 'vertical_id',
    'required_permission', 'assignment_mode', 'ack_timeout_minutes', 'escalate_after_attempts',
    'sort_order', 'description', 'is_active',
])]
class QueueDefinition extends Model
{
    use Auditable, HasAuditColumns;

    protected function casts(): array
    {
        return [
            'queue_type' => QueueType::class,
            'is_active' => 'boolean',
            'ack_timeout_minutes' => 'integer',
            'escalate_after_attempts' => 'integer',
        ];
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function majorOutageType(): BelongsTo
    {
        return $this->belongsTo(MajorOutageType::class);
    }

    public function vertical(): BelongsTo
    {
        return $this->belongsTo(Vertical::class);
    }

    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    public function permission(): string
    {
        return $this->required_permission ?: $this->queue_type->defaultPermission();
    }

    public function isManual(): bool
    {
        return $this->assignment_mode === 'manual';
    }
}
