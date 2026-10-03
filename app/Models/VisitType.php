<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasAuditColumns;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Kind of site visit; its prefix numbers the visits: INS-1, WiE-1, SUP-1, SHI-1. */
#[Fillable(['name', 'code_prefix', 'description', 'default_sla_minutes', 'priority_id', 'requires_evidence', 'sort_order', 'is_active'])]
class VisitType extends Model
{
    use Auditable, HasAuditColumns;

    protected function casts(): array
    {
        return [
            'default_sla_minutes' => 'integer',
            'requires_evidence' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saving(fn (VisitType $type) => $type->code_prefix = trim((string) $type->code_prefix));
    }

    public function priority(): BelongsTo
    {
        return $this->belongsTo(Priority::class);
    }

    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }
}
