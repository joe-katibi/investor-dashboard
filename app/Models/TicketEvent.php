<?php

namespace App\Models;

use App\Enums\QueueType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One step of a ticket's time line (created, queued, assigned, accepted, en route, arrived,
 * paused, resumed, team leader intervention, completed, verified, closed, escalated…). The TAT
 * reports are worked out from these.
 */
class TicketEvent extends Model
{
    protected $guarded = [];

    public const LABELS = [
        'created' => 'Created',
        'queued' => 'Queued',
        'assigned' => 'Assigned',
        'accepted' => 'Accepted',
        'returned_to_queue' => 'Returned to the queue',
        'en_route' => 'En route',
        'arrived' => 'Arrived at premise',
        'paused' => 'Paused',
        'resumed' => 'Resumed',
        'intervention_started' => 'Team leader intervention started',
        'intervention_ended' => 'Team leader intervention ended',
        'completed' => 'Resolved / completed',
        'not_completed' => 'Could not complete',
        'provision_requested' => 'Sent to the NOC for provisioning',
        'provisioned' => 'Provisioned by the NOC',
        'relocated' => 'Relocated to another OLT / region',
        'aftercare_due' => '72-hour follow-up call due',
        'aftercare_call' => '72-hour follow-up call',
        'verified' => 'Verified by dispatcher',
        'confirmed' => 'Confirmed with the customer',
        'escalated' => 'Escalated',
        'returned' => 'Returned',
        'approved' => 'Approved',
        'declined' => 'Declined',
        'scheduled' => 'Scheduled',
        'rescheduled' => 'Rescheduled',
        'follow_up' => 'Customer follow-up',
        'follow_up_due' => 'Follow-up due',
        'customer_sms' => 'SMS to customer',
        'site_visit_raised' => 'Site visit raised',
        'outage_linked' => 'Linked to an outage',
        'closed' => 'Closed',
        'reopened' => 'Reopened',
        'cancelled' => 'Cancelled',
    ];

    protected function casts(): array
    {
        return ['desk' => QueueType::class, 'meta' => 'array', 'occurred_at' => 'datetime'];
    }

    public function outage(): BelongsTo
    {
        return $this->belongsTo(Outage::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function subjectUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'subject_user_id');
    }

    public function label(): string
    {
        return self::LABELS[$this->event] ?? ucfirst(str_replace('_', ' ', (string) $this->event));
    }
}
