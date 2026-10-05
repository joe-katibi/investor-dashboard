<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasAuditColumns;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Cache;

#[Fillable([
    'name', 'code', 'major_outage_type_id', 'vertical_id', 'prefix', 'is_planned', 'is_emergency', 'requires_site_photos',
    'maintenance_kind', 'workflow', 'fault_domain', 'description', 'sort_order', 'is_active',
])]
class OutageType extends Model
{
    use Auditable, HasAuditColumns;

    protected function casts(): array
    {
        return [
            'is_planned' => 'boolean',
            'is_emergency' => 'boolean',
            'requires_site_photos' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public const SITE_PHOTOS_CACHE_KEY = 'oms.outage-types.site-photos.v1';

    protected static function booted(): void
    {
        static::saving(fn (OutageType $type) => $type->prefix = strtoupper(trim((string) $type->prefix)));
        static::saved(fn () => Cache::forget(self::SITE_PHOTOS_CACHE_KEY));
        static::deleted(fn () => Cache::forget(self::SITE_PHOTOS_CACHE_KEY));
    }

    /**
     * Is an outage of this type reported restored with the three site photos? Cached by id, so
     * policies can decide per table row without a query (and whatever columns the type was loaded with).
     */
    public static function requiresSitePhotos(?int $id): bool
    {
        $ids = Cache::rememberForever(self::SITE_PHOTOS_CACHE_KEY, fn () => static::query()->where('requires_site_photos', true)->pluck('id')->all());

        return $id !== null && in_array($id, $ids, true);
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

    /**
     * Who works an internal 4G ticket, by fault domain: transmission outages go to the 4G engineers,
     * eNodeB outages to the 4G technicians. Fault domain => [permission the person needs, what they are called].
     * A domain that is not listed is open to everyone in the 4G engineer / technician queue.
     */
    public const HANDLERS = [
        self::DOMAIN_TRANSMISSION => ['mobility.handle_transmission', '4G engineer'],
        self::DOMAIN_ENODEB => ['mobility.handle_enodeb', '4G technician'],
    ];

    /** The permission a person needs to be given a ticket of this fault domain (null: no restriction). */
    public static function handlerPermission(?string $domain): ?string
    {
        return self::HANDLERS[$domain][0] ?? null;
    }

    /** "4G engineer", "4G technician" — or "engineer / technician" where the domain is open to both. */
    public static function handlerLabel(?string $domain): string
    {
        return self::HANDLERS[$domain][1] ?? 'engineer / technician';
    }

    /** 4G ticket numbers are short: 4G-PWR-000123. */
    public const MOBILITY_TICKET_DIGITS = 6;

    public function vertical(): BelongsTo
    {
        return $this->belongsTo(Vertical::class);
    }

    public const MOBILITY_WORKFLOWS = [self::WORKFLOW_MOBILITY_INTERNAL, self::WORKFLOW_MOBILITY_VENDOR];

    // Customer ticketing: tickets worked desk by desk, and site visits. One system type each.
    public const WORKFLOW_CX_TICKET = 'cx_ticket';

    public const WORKFLOW_SITE_VISIT = 'site_visit';

    public const TICKET_WORKFLOWS = [self::WORKFLOW_CX_TICKET, self::WORKFLOW_SITE_VISIT];

    public const CODE_CX_TICKET = 'customer_ticket';

    public const CODE_SITE_VISIT = 'site_visit';

    /** Customer tickets are numbered TKT-000123; site visits by their visit type (INS-1, SUP-1…). */
    public const TICKET_DIGITS = 6;

    public function isMobility(): bool
    {
        return in_array($this->workflow, self::MOBILITY_WORKFLOWS, true);
    }

    public function isTicketing(): bool
    {
        return in_array($this->workflow, self::TICKET_WORKFLOWS, true);
    }

    public function scopeTicketing(Builder $query): void
    {
        $query->whereIn('workflow', self::TICKET_WORKFLOWS);
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
        $query->whereIn('workflow', self::MOBILITY_WORKFLOWS);
    }
}
