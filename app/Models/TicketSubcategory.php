<?php

namespace App\Models;

use App\Enums\QueueType;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasAuditColumns;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The issue reported, and how it is routed: the desk a new ticket goes to, the desk that approves
 * it (refunds, credits) and the desk that then carries it out. From there the people at each desk
 * move it on by hand along the configured desk routes (e.g. CX Tier II decides between NOC and a
 * site visit after troubleshooting).
 */
#[Fillable([
    'ticket_category_id', 'name', 'code', 'description', 'first_desk', 'approval_desk', 'fulfilment_desk', 'allows_site_visit',
    'visit_type_id', 'check_outage', 'priority_id', 'sla_minutes', 'sort_order', 'is_active',
])]
class TicketSubcategory extends Model
{
    use Auditable, HasAuditColumns;

    protected function casts(): array
    {
        return [
            'first_desk' => QueueType::class,
            'approval_desk' => QueueType::class,
            'fulfilment_desk' => QueueType::class,
            'allows_site_visit' => 'boolean',
            'check_outage' => 'boolean',
            'sla_minutes' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(TicketCategory::class, 'ticket_category_id');
    }

    public function visitType(): BelongsTo
    {
        return $this->belongsTo(VisitType::class);
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
