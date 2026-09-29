<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasAuditColumns;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['name', 'code', 'major_outage_type_id', 'prefix', 'is_planned', 'is_emergency', 'maintenance_kind', 'description', 'sort_order', 'is_active'])]
class OutageType extends Model
{
    use Auditable, HasAuditColumns;

    protected function casts(): array
    {
        return [
            'is_planned' => 'boolean',
            'is_emergency' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saving(fn (OutageType $type) => $type->prefix = strtoupper(trim((string) $type->prefix)));
    }

    public function majorOutageType(): BelongsTo
    {
        return $this->belongsTo(MajorOutageType::class);
    }

    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    public const PREVENTIVE = 'preventive';

    public const CORRECTIVE = 'corrective';

    /** Types reported through the normal "Report outage" form (planned / field maintenance have their own flows). */
    public function scopeReportable(Builder $query): void
    {
        $query->where('is_planned', false)->whereNull('maintenance_kind');
    }
}
