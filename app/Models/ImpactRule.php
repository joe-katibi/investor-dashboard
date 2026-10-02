<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasAuditColumns;
use App\Services\Outages\PriorityResolver;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Severity rule by affected network elements, e.g. "Transmission fault affecting 3 or more SSGs →
 * Critical". Used by verticals that do not rate impact by customer count (4G). When several rules
 * match a ticket, the most severe priority wins.
 */
#[Fillable(['name', 'vertical_id', 'outage_type_id', 'metric', 'min_value', 'max_value', 'priority_id', 'description', 'is_active'])]
class ImpactRule extends Model
{
    use Auditable, HasAuditColumns;

    public const METRICS = [
        'enodebs' => 'eNodeBs affected',
        'cells' => 'Cells down',
        'ssg' => 'SSGs affected',
        'csg' => 'CSGs affected',
        'asg' => 'ASGs affected',
        'rsg' => 'RSGs affected',
        'customers' => 'Customers affected',
    ];

    protected function casts(): array
    {
        return ['min_value' => 'integer', 'max_value' => 'integer', 'is_active' => 'boolean'];
    }

    protected static function booted(): void
    {
        static::saved(fn () => PriorityResolver::flush());
        static::deleted(fn () => PriorityResolver::flush());
    }

    public function vertical(): BelongsTo
    {
        return $this->belongsTo(Vertical::class);
    }

    public function outageType(): BelongsTo
    {
        return $this->belongsTo(OutageType::class);
    }

    public function priority(): BelongsTo
    {
        return $this->belongsTo(Priority::class);
    }

    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    public function matchesValue(int $value): bool
    {
        return $value >= $this->min_value && ($this->max_value === null || $value <= $this->max_value);
    }

    /** "3 – 6", "7 or more", "exactly 1". */
    public function rangeLabel(): string
    {
        return match (true) {
            $this->max_value === null => $this->min_value.' or more',
            $this->max_value === $this->min_value => 'exactly '.$this->min_value,
            default => $this->min_value.' – '.$this->max_value,
        };
    }
}
