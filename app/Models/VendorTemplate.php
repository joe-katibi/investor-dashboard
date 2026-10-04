<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasAuditColumns;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Editable e-mail to an external vendor. The trigger decides when it is sent; it goes to the
 * ticket's vendor (e-mail + CC addresses on the vendor record) plus any fixed TO / CC addresses.
 */
#[Fillable([
    'name', 'code', 'trigger', 'outage_type_id', 'send_to_vendor', 'cc_vendor_cc', 'to', 'cc',
    'subject', 'body', 'description', 'is_active',
])]
class VendorTemplate extends Model
{
    use Auditable, HasAuditColumns;

    public const TICKET_CREATED = 'ticket_created';

    public const VENDOR_CHANGED = 'vendor_changed';

    public const REMINDER = 'reminder';

    public const REOPENED = 'reopened';

    public const CLOSED = 'closed';

    public const CANCELLED = 'cancelled';

    public const MANUAL = 'manual';

    public const TRIGGERS = [
        self::TICKET_CREATED => 'Vendor ticket created (sent automatically to the selected vendor)',
        self::VENDOR_CHANGED => 'Vendor changed on an open ticket (sent to the new vendor)',
        self::REMINDER => 'No vendor update within the escalation time (reminder to the vendor)',
        self::REOPENED => 'Ticket reopened',
        self::CLOSED => 'Ticket closed — restoration verified by the NOC',
        self::CANCELLED => 'Ticket cancelled',
        self::MANUAL => 'Manual — “Send vendor notification” on the ticket',
    ];

    /** Placeholders available in vendor templates. */
    public const PLACEHOLDERS = [
        'ticket_number', 'site_id', 'site_name', 'tower_co_id', 'location', 'outage_type', 'priority', 'outage_start_time', 'detection_time',
        'affected_services', 'affected_sites', 'affected_nodes', 'description', 'service_impact', 'vendor_name',
        'vendor_contact', 'sla', 'sla_due', 'status', 'noc_contact', 'ticket_url', 'reason', 'outage_duration',
        'restoration_time', 'root_cause', 'resolution',
    ];

    protected function casts(): array
    {
        return [
            'to' => 'array',
            'cc' => 'array',
            'send_to_vendor' => 'boolean',
            'cc_vendor_cc' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function outageType(): BelongsTo
    {
        return $this->belongsTo(OutageType::class);
    }

    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    public function triggerLabel(): string
    {
        return self::TRIGGERS[$this->trigger] ?? $this->trigger;
    }
}
