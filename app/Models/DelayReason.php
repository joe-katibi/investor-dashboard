<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasAuditColumns;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** Why a ticket is / was late: rescheduled by the customer, client unreachable, escalated to infrastructure… */
#[Fillable(['code', 'name', 'within_control', 'description', 'sort_order', 'is_active'])]
class DelayReason extends Model
{
    use Auditable, HasAuditColumns;

    // Reasons the workflow sets by itself.
    public const OUTAGE = 'D06';

    public const RESCHEDULED = 'D01';

    public const UNREACHABLE = 'D02';

    public const ESCALATED_INFRA = 'D07';

    protected function casts(): array
    {
        return ['within_control' => 'boolean', 'is_active' => 'boolean'];
    }

    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    public static function idForCode(string $code): ?int
    {
        return static::query()->where('code', $code)->value('id');
    }
}
