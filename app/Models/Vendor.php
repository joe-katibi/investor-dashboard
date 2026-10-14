<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasAuditColumns;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * External party responsible for part of the 4G network (power / tower companies such as ATC,
 * TOA, UTEL). A vendor is a record only: it has no login and never sees the system. The NOC
 * e-mails the vendor from the ticket and captures the vendor's replies by hand.
 */
#[Fillable(['name', 'code', 'vendor_type', 'contact_person', 'email', 'cc_emails', 'phone', 'description', 'is_active'])]
class Vendor extends Model
{
    use Auditable, HasAuditColumns;

    public const TYPES = [
        'power' => 'Power',
        'tower' => 'Tower company',
        'transmission' => 'Transmission / backhaul',
        'equipment' => 'Equipment',
        // The engineering teams of 4G customer tickets call in their own vendors.
        'ip' => 'IP (4G customer tickets)',
        'rf' => 'RF (4G customer tickets)',
        'core' => 'Core (4G customer tickets)',
        'other' => 'Other',
    ];

    protected function casts(): array
    {
        return ['cc_emails' => 'array', 'is_active' => 'boolean'];
    }

    protected static function booted(): void
    {
        static::saving(fn (Vendor $vendor) => $vendor->code = strtoupper(trim((string) $vendor->code)));
    }

    public function outages(): HasMany
    {
        return $this->hasMany(Outage::class);
    }

    public function updates(): HasMany
    {
        return $this->hasMany(VendorUpdate::class);
    }

    public function enodebs(): HasMany
    {
        return $this->hasMany(Enodeb::class);
    }

    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    public function typeLabel(): string
    {
        return self::TYPES[$this->vendor_type] ?? ucfirst((string) $this->vendor_type);
    }

    /** @return list<string> valid CC addresses */
    public function ccList(): array
    {
        return array_values(array_filter(
            array_map(fn ($email) => strtolower(trim((string) $email)), (array) $this->cc_emails),
            fn (string $email) => (bool) filter_var($email, FILTER_VALIDATE_EMAIL),
        ));
    }
}
