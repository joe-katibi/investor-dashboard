<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasAuditColumns;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** What fixed / ended a ticket ("Splicing done at the FAT", "Faulty ONU replaced"…), picked on closing. */
#[Fillable(['name', 'description', 'applies_to', 'delay_reason_id', 'sort_order', 'is_active'])]
class FinalReason extends Model
{
    use Auditable, HasAuditColumns;

    public const APPLIES_TO = ['ticket' => 'Customer tickets', 'visit' => 'Site visits'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function delayReason(): BelongsTo
    {
        return $this->belongsTo(DelayReason::class);
    }

    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /** Reasons offered for a customer ticket ("ticket") or a site visit ("visit"). */
    public function scopeFor(Builder $query, string $kind): void
    {
        $query->where(fn (Builder $q) => $q->whereNull('applies_to')->orWhere('applies_to', $kind));
    }
}
