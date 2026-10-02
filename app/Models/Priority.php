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
 * Impact / priority level. Each vertical has its own set: FTTH rates impact by customers affected
 * (the min / max range here), 4G by network elements (see ImpactRule).
 */
#[Fillable(['name', 'code', 'vertical_id', 'severity', 'queue_rank', 'min_customers', 'max_customers', 'color', 'description', 'is_default', 'is_active'])]
class Priority extends Model
{
    use Auditable, HasAuditColumns;

    public const CRITICAL = 'critical';

    public const HIGH = 'high';

    // 4G severities (spec: Minor / Major / Critical by eNodeBs and transmission nodes affected).
    public const MOBILITY_MINOR = 'minor_4g';

    public const MOBILITY_MAJOR = 'major_4g';

    public const MOBILITY_CRITICAL = 'critical_4g';

    /** Top severity of every vertical: critical dashboards, alerts and the FIFO workload bypass. */
    public const CRITICAL_CODES = [self::CRITICAL, self::MOBILITY_CRITICAL];

    /** Critical and the level below it, per vertical. */
    public const MAJOR_CODES = [self::CRITICAL, self::HIGH, self::MOBILITY_CRITICAL, self::MOBILITY_MAJOR];

    protected function casts(): array
    {
        return [
            'severity' => 'integer',
            'queue_rank' => 'integer',
            'min_customers' => 'integer',
            'max_customers' => 'integer',
            'is_default' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saved(fn () => PriorityResolver::flush());
    }

    public function vertical(): BelongsTo
    {
        return $this->belongsTo(Vertical::class);
    }

    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    public function isCritical(): bool
    {
        return in_array($this->code, self::CRITICAL_CODES, true);
    }

    public function matchesCustomers(int $customers): bool
    {
        return ($this->min_customers === null || $customers >= $this->min_customers)
            && ($this->max_customers === null || $customers <= $this->max_customers);
    }
}
