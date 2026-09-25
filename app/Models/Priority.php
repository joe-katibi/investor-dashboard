<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasAuditColumns;
use App\Services\Outages\PriorityResolver;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['name', 'code', 'severity', 'queue_rank', 'min_customers', 'max_customers', 'color', 'description', 'is_default', 'is_active'])]
class Priority extends Model
{
    use Auditable, HasAuditColumns;

    public const CRITICAL = 'critical';

    public const HIGH = 'high';

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

    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    public function isCritical(): bool
    {
        return $this->code === self::CRITICAL;
    }

    public function matchesCustomers(int $customers): bool
    {
        return ($this->min_customers === null || $customers >= $this->min_customers)
            && ($this->max_customers === null || $customers <= $this->max_customers);
    }
}
