<?php

namespace App\Models;

use App\Enums\QueueType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Customer / visit details of a ticket. The ticket itself is an Outage row (workflow "cx_ticket"
 * or "site_visit") so the FIFO queues, SLA clock, notifications, timeline and audit trail are the
 * same as for network outages; this row carries what only customer work has. Written by the
 * ticket workflow only.
 */
class Ticket extends Model
{
    protected $guarded = [];

    public const TYPES = [
        'reactive' => 'Reactive',
        'proactive' => 'Proactive',
        'reactive_repeat' => 'Reactive Repeat',
        'proactive_churn' => 'Proactive CHURN',
    ];

    public const SOURCES = [
        'call' => 'Phone call',
        'walk_in' => 'Walk-in',
        'whatsapp' => 'WhatsApp',
        'email' => 'E-mail',
        'social' => 'Social media',
        'retention' => 'Retention call',
        'follow_up' => 'Follow-up call',
        'monitoring' => 'Proactive monitoring',
        'other' => 'Other',
    ];

    public const APPROVAL_PENDING = 'pending';

    public const APPROVAL_APPROVED = 'approved';

    public const APPROVAL_DECLINED = 'declined';

    protected function casts(): array
    {
        return [
            'home_desk' => QueueType::class,
            'desk' => QueueType::class,
            'escalated_to' => QueueType::class,
            'scheduled_at' => 'datetime',
            'desk_since' => 'datetime',
            'first_assigned_at' => 'datetime',
            'assigned_at' => 'datetime',
            'accepted_at' => 'datetime',
            'escalated_at' => 'datetime',
            'returned_at' => 'datetime',
            'approval_at' => 'datetime',
            'amount' => 'decimal:2',
            'customer_confirmed' => 'boolean',
            'en_route_at' => 'datetime',
            'arrived_at' => 'datetime',
            'completed_at' => 'datetime',
            'verified_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'paused_at' => 'datetime',
            'intervention_started_at' => 'datetime',
            'intervention_ended_at' => 'datetime',
            'follow_up_due_at' => 'datetime',
            'followed_up_at' => 'datetime',
            'credit_ready_at' => 'datetime',
            'credit_notified_at' => 'datetime',
            'rescheduled_at' => 'datetime',
            'customer_sms_at' => 'datetime',
            'escalation_count' => 'integer',
            'follow_up_count' => 'integer',
            'reschedule_count' => 'integer',
            'paused_seconds' => 'integer',
            'intervention_seconds' => 'integer',
        ];
    }

    public function outage(): BelongsTo
    {
        return $this->belongsTo(Outage::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
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

    public function category(): BelongsTo
    {
        return $this->belongsTo(TicketCategory::class, 'category_id');
    }

    public function subcategory(): BelongsTo
    {
        return $this->belongsTo(TicketSubcategory::class, 'subcategory_id');
    }

    public function visitType(): BelongsTo
    {
        return $this->belongsTo(VisitType::class);
    }

    public function parentOutage(): BelongsTo
    {
        return $this->belongsTo(Outage::class, 'parent_outage_id');
    }

    public function linkedOutage(): BelongsTo
    {
        return $this->belongsTo(Outage::class, 'linked_outage_id');
    }

    public function delayReason(): BelongsTo
    {
        return $this->belongsTo(DelayReason::class);
    }

    public function finalReason(): BelongsTo
    {
        return $this->belongsTo(FinalReason::class);
    }

    public function approvalBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approval_by');
    }

    public function completedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    public function verifiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    public function confirmedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }

    public function followedUpBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'followed_up_by');
    }

    public function interventionBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'intervention_by');
    }

    /** Every number the customer can be reached on, the one they called from first. */
    public function phones(): array
    {
        return array_values(array_unique(array_filter(array_map(
            fn ($number) => trim((string) $number),
            [$this->calling_number, $this->primary_number, $this->alt_number_1, $this->alt_number_2],
        ))));
    }

    /**
     * The same numbers named by what they are, for someone choosing which one to call. A number
     * that repeats another is kept: "primary" being the calling number is worth seeing too.
     *
     * @return list<array{key: string, label: string, number: string}>
     */
    public function contacts(): array
    {
        $numbers = [
            'calling' => ['Calling number', $this->calling_number],
            'primary' => ['Primary number', $this->primary_number],
            'alt_1' => ['Alternative number 1', $this->alt_number_1],
            'alt_2' => ['Alternative number 2', $this->alt_number_2],
        ];

        return collect($numbers)
            ->map(fn (array $entry, string $key) => ['key' => $key, 'label' => $entry[0], 'number' => trim((string) $entry[1])])
            ->filter(fn (array $entry) => $entry['number'] !== '')
            ->values()->all();
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

    public function typeLabel(): string
    {
        return self::TYPES[$this->ticket_type] ?? (string) $this->ticket_type;
    }

    /** Seconds the technician has been at the customer's premises, pauses excluded. */
    public function onPremiseSeconds(): ?int
    {
        if (! $this->arrived_at) {
            return null;
        }

        $end = $this->completed_at ?? $this->paused_at ?? now();

        return max(0, (int) $this->arrived_at->diffInSeconds($end) - (int) $this->paused_seconds);
    }
}
