<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasAuditColumns;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'name', 'code', 'major_outage_type_id', 'vertical_id', 'prefix', 'is_planned', 'is_emergency', 'maintenance_kind',
    'workflow', 'fault_domain', 'description', 'sort_order', 'is_active',
])]
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

    // 4G NOC workflows. A new 4G outage type only needs one of these two flows, a fault domain and a prefix.
    public const WORKFLOW_MOBILITY_INTERNAL = 'mobility_internal';

    public const WORKFLOW_MOBILITY_VENDOR = 'mobility_vendor';

    public const WORKFLOWS = [
        self::WORKFLOW_MOBILITY_INTERNAL => '4G NOC → Engineer / Technician → 4G NOC verifies → Closed',
        self::WORKFLOW_MOBILITY_VENDOR => '4G NOC → external Vendor (e-mail) → NOC records vendor updates → NOC verifies → Closed',
    ];

    public const DOMAIN_ENODEB = 'enodeb';

    public const DOMAIN_TRANSMISSION = 'transmission';

    public const DOMAIN_POWER = 'power';

    public const FAULT_DOMAINS = [
        self::DOMAIN_ENODEB => 'eNodeB',
        self::DOMAIN_TRANSMISSION => 'Transmission',
        self::DOMAIN_POWER => 'Power',
    ];

    /** 4G ticket numbers are short: 4G-PWR-000123. */
    public const MOBILITY_TICKET_DIGITS = 6;

    public function vertical(): BelongsTo
    {
        return $this->belongsTo(Vertical::class);
    }

    public function isMobility(): bool
    {
        return $this->workflow !== null;
    }

    public function usesVendor(): bool
    {
        return $this->workflow === self::WORKFLOW_MOBILITY_VENDOR;
    }

    /** Types reported through the normal "Report outage" form (planned / field maintenance / 4G have their own flows). */
    public function scopeReportable(Builder $query): void
    {
        $query->where('is_planned', false)->whereNull('maintenance_kind')->whereNull('workflow');
    }

    public function scopeMobility(Builder $query): void
    {
        $query->whereNotNull('workflow');
    }
}
