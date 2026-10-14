<?php

namespace App\Models;

use App\Enums\QueueType;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToVertical;
use App\Models\Concerns\HasAuditColumns;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** What fixed / ended a ticket ("Splicing done at the FAT", "Faulty ONU replaced"…), picked on closing. */
#[Fillable(['name', 'description', 'applies_to', 'desks', 'delay_reason_id', 'vertical_id', 'sort_order', 'is_active'])]
class FinalReason extends Model
{
    use Auditable, BelongsToVertical, HasAuditColumns;

    public const APPLIES_TO = ['ticket' => 'Customer tickets', 'visit' => 'Site visits'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'desks' => 'array'];
    }

    public function delayReason(): BelongsTo
    {
        return $this->belongsTo(DelayReason::class);
    }

    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * Offered at this desk? A reason with no desks is offered everywhere; one with desks only to
     * the people working those desks (a billing agent sees the billing reasons, not the NOC's).
     */
    public function isOfferedAt(?QueueType $desk): bool
    {
        $desks = array_filter((array) $this->desks);

        return $desks === [] || $desk === null || in_array($desk->value, $desks, true);
    }

    /** "Billing, Billing Manager" — or null for a reason offered at every desk. */
    public function desksLabel(): ?string
    {
        return collect((array) $this->desks)->map(fn (string $desk) => QueueType::tryFrom($desk)?->getLabel())->filter()->implode(', ') ?: null;
    }

    /** Reasons offered for a customer ticket ("ticket") or a site visit ("visit"). */
    public function scopeFor(Builder $query, string $kind): void
    {
        $query->where(fn (Builder $q) => $q->whereNull('applies_to')->orWhere('applies_to', $kind));
    }
}
