<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Credit / SMS YES-NO change history (spec §18). */
#[Fillable(['outage_id', 'flag', 'old_value', 'new_value', 'changed_by'])]
class OutageFlagEvent extends Model
{
    protected function casts(): array
    {
        return ['old_value' => 'boolean', 'new_value' => 'boolean'];
    }

    public function outage(): BelongsTo
    {
        return $this->belongsTo(Outage::class);
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
