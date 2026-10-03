<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasAuditColumns;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * A customer account and where it hangs on the FTTH network (OLT → FDT → Slot → Port). Records
 * come from the customer file import, or are typed in by an agent while raising a ticket for an
 * account that is not on file yet ("manual"); a later import fills those in by account number.
 */
#[Fillable([
    'account_number', 'name', 'primary_phone', 'alt_phone_1', 'alt_phone_2', 'email', 'address', 'landmark',
    'region_id', 'olt_id', 'fdt_id', 'slot_id', 'port_id', 'bandwidth', 'source', 'verified_at', 'notes', 'is_active',
])]
class Customer extends Model
{
    use Auditable, HasAuditColumns;

    public const SOURCE_MANUAL = 'manual';

    public const SOURCE_IMPORT = 'import';

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'verified_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::saving(fn (Customer $customer) => $customer->account_number = self::normaliseAccount($customer->account_number));
    }

    /** "sf2575352 " → "SF2575352": account numbers are matched without case or spaces. */
    public static function normaliseAccount(?string $account): string
    {
        return Str::upper((string) preg_replace('/\s+/', '', (string) $account));
    }

    public static function findByAccount(?string $account): ?self
    {
        $account = self::normaliseAccount($account);

        return $account === '' ? null : static::query()->where('account_number', $account)->first();
    }

    public function region(): BelongsTo
    {
        return $this->belongsTo(Region::class);
    }

    public function olt(): BelongsTo
    {
        return $this->belongsTo(Olt::class);
    }

    public function fdt(): BelongsTo
    {
        return $this->belongsTo(Fdt::class);
    }

    public function slot(): BelongsTo
    {
        return $this->belongsTo(Slot::class);
    }

    public function port(): BelongsTo
    {
        return $this->belongsTo(Port::class);
    }

    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class);
    }

    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /** Entered by hand and not yet confirmed by the customer file. */
    public function isProvisional(): bool
    {
        return $this->source === self::SOURCE_MANUAL && $this->verified_at === null;
    }

    /** "Nsambya › FDT 6 › Slot 3 › Port 15" */
    public function topologyPath(): string
    {
        return collect([
            $this->olt?->name,
            $this->fdt?->name,
            $this->slot ? 'Slot '.$this->slot->name : null,
            $this->port ? 'Port '.$this->port->name : null,
        ])->filter()->implode(' › ');
    }

    /** @return list<string> */
    public function phones(): array
    {
        return array_values(array_unique(array_filter([$this->primary_phone, $this->alt_phone_1, $this->alt_phone_2])));
    }
}
