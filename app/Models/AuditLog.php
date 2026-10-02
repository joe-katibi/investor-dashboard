<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Str;

/** Append-only: audit rows are never updated or deleted by the application. */
#[Fillable([
    'auditable_type', 'auditable_id', 'event', 'old_values', 'new_values', 'user_id', 'trigger',
    'ip_address', 'user_agent', 'url', 'created_at',
])]
class AuditLog extends Model
{
    public const UPDATED_AT = null;

    /** Named actions on 4G NOC tickets: 4g_created, 4g_engineer_assigned, 4g_vendor_update, 4g_closed… */
    public const MOBILITY_PREFIX = '4g_';

    /** Everything else that belongs to the 4G NOC module (records and configuration). */
    public const MOBILITY_MODELS = [
        Vendor::class, VendorUpdate::class, VendorTemplate::class, Enodeb::class, TransmissionNode::class,
        ImpactRule::class, OutageAttachment::class, OutageReopening::class,
    ];

    protected function casts(): array
    {
        return [
            'old_values' => 'array',
            'new_values' => 'array',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => false);
        static::deleting(fn () => false);
    }

    public function auditable(): MorphTo
    {
        return $this->morphTo();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The whole 4G NOC audit trail: every entry of a 4G ticket (named actions and field changes),
     * vendors, vendor updates, attachments, reopenings, sites, transmission nodes, severity rules,
     * vendor templates, and the 4G settings.
     */
    public function scopeMobility(Builder $query): void
    {
        $morph = fn (string $class) => (new $class)->getMorphClass();

        $query->where(fn (Builder $q) => $q
            ->where('event', 'like', self::MOBILITY_PREFIX.'%')
            ->orWhereIn('auditable_type', array_map($morph, self::MOBILITY_MODELS))
            ->orWhere(fn (Builder $ticket) => $ticket->where('auditable_type', $morph(Outage::class))
                ->whereIn('auditable_id', Outage::query()->mobility()->select('id')))
            ->orWhere(fn (Builder $setting) => $setting->where('auditable_type', $morph(Setting::class))
                ->whereIn('auditable_id', Setting::query()->where('key', 'like', 'mobility.%')->select('id'))));
    }

    public function isMobilityAction(): bool
    {
        return str_starts_with((string) $this->event, self::MOBILITY_PREFIX);
    }

    /** "4g_vendor_update" → "4G: Vendor update"; "created" → "Created". */
    public function eventLabel(): string
    {
        return self::labelFor((string) $this->event);
    }

    public static function labelFor(string $event): string
    {
        return str_starts_with($event, self::MOBILITY_PREFIX)
            ? '4G: '.Str::of($event)->after(self::MOBILITY_PREFIX)->replace('_', ' ')->ucfirst()->replace('noc', 'NOC')->replace('fifo', 'FIFO')->replace('Fifo', 'FIFO')
            : Str::of($event)->replace('_', ' ')->ucfirst()->toString();
    }

    public function subjectLabel(): string
    {
        // 4G action entries carry the ticket number, which says more than "Outage #12".
        if ($this->isMobilityAction() && filled($this->new_values['ticket'] ?? null)) {
            return (string) $this->new_values['ticket'];
        }

        return class_basename($this->auditable_type).' #'.$this->auditable_id;
    }

    /** One line per value: "status: New → Assigned", or just "details: …" when there was no previous value. */
    public function changeLines(): array
    {
        $format = fn (mixed $value) => $value === null ? '∅' : (is_scalar($value) ? (string) $value : json_encode($value));
        $old = $this->old_values ?? [];

        return collect($this->new_values ?? [])
            ->map(fn ($new, $key) => array_key_exists($key, $old) ? $key.': '.$format($old[$key]).' → '.$format($new) : $key.': '.$format($new))
            ->values()->all();
    }
}
