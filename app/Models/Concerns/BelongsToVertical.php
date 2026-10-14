<?php

namespace App\Models\Concerns;

use App\Models\Vertical;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Something of one vertical (FTTH, 4G, …): a ticket category, a customer, a final reason, a reason
 * for delay. A row without a vertical is offered for every vertical.
 */
trait BelongsToVertical
{
    public function vertical(): BelongsTo
    {
        return $this->belongsTo(Vertical::class);
    }

    /** The rows of this vertical, and those of none; no vertical asked for = no narrowing. */
    public function scopeOfVertical(Builder $query, ?int $verticalId): void
    {
        if ($verticalId) {
            $column = $query->getModel()->qualifyColumn('vertical_id');
            $query->where(fn (Builder $q) => $q->whereNull($column)->orWhere($column, $verticalId));
        }
    }
}
