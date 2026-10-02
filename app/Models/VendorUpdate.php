<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * What an external vendor told the NOC (by e-mail, phone, WhatsApp…), captured by a NOC user.
 * Vendors never enter anything themselves.
 */
#[Fillable([
    'outage_id', 'vendor_id', 'user_id', 'occurred_at', 'contact_person', 'method', 'vendor_status',
    'eta_at', 'next_update_at', 'comments',
])]
class VendorUpdate extends Model
{
    public const STATUSES = [
        'acknowledged' => 'Acknowledged',
        'investigating' => 'Investigating',
        'technician_dispatched' => 'Technician Dispatched',
        'technician_on_site' => 'Technician On Site',
        'awaiting_parts' => 'Awaiting Parts',
        'awaiting_access' => 'Awaiting Access',
        'power_restored' => 'Power Restored',
        'monitoring' => 'Monitoring',
        'unable_to_resolve' => 'Unable to Resolve',
        'escalated' => 'Escalated',
        'resolved' => 'Resolved',
    ];

    /** Vendor says it is fixed: the ticket waits for the NOC to verify. */
    public const RESOLVED = ['power_restored', 'resolved'];

    public const METHODS = [
        'email' => 'E-mail',
        'phone' => 'Telephone',
        'whatsapp' => 'WhatsApp',
        'in_person' => 'In person',
        'other' => 'Other',
    ];

    protected function casts(): array
    {
        return ['occurred_at' => 'datetime', 'eta_at' => 'datetime', 'next_update_at' => 'datetime'];
    }

    public function outage(): BelongsTo
    {
        return $this->belongsTo(Outage::class);
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(OutageAttachment::class);
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->vendor_status] ?? $this->vendor_status;
    }

    public function methodLabel(): string
    {
        return self::METHODS[$this->method] ?? $this->method;
    }

    public function reportsResolved(): bool
    {
        return in_array($this->vendor_status, self::RESOLVED, true);
    }
}
