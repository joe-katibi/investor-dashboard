<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasAuditColumns;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Kind of site visit; its prefix numbers the visits: INS-1, WiE-1, SUP-1, SHI-1. */
#[Fillable(['name', 'code_prefix', 'description', 'default_sla_minutes', 'priority_id', 'requires_evidence', 'photo_set', 'technician_permission', 'is_relocation', 'sort_order', 'is_active'])]
class VisitType extends Model
{
    use Auditable, HasAuditColumns;

    /** Shifting / Relocation visits: only the technicians given this permission get them. */
    public const SHIFTING_PERMISSION = 'visit.work_shifting';

    /** The permissions a visit type can be kept for ("Technicians" on the visit type screen). */
    public const TECHNICIAN_PERMISSIONS = [
        self::SHIFTING_PERMISSION => 'Shifting / Relocation technicians only',
    ];

    /** Which photos a visit of this kind is completed with (App\Support\VisitPhotos). */
    protected $attributes = ['photo_set' => 'standard'];

    protected function casts(): array
    {
        return [
            'default_sla_minutes' => 'integer',
            'requires_evidence' => 'boolean',
            'is_relocation' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saving(fn (VisitType $type) => $type->code_prefix = trim((string) $type->code_prefix));
    }

    /** The permission a technician needs for visits of this kind; null = every site visit technician. */
    public function technicianPermission(): ?string
    {
        return filled($this->technician_permission) ? $this->technician_permission : null;
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
