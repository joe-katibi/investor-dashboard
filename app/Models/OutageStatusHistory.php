<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['outage_id', 'from_status_id', 'to_status_id', 'reason', 'changed_by', 'trigger', 'snapshot'])]
class OutageStatusHistory extends Model
{
    protected function casts(): array
    {
        return ['snapshot' => 'array'];
    }

    public function outage(): BelongsTo
    {
        return $this->belongsTo(Outage::class);
    }

    public function fromStatus(): BelongsTo
    {
        return $this->belongsTo(OutageStatus::class, 'from_status_id');
    }

    public function toStatus(): BelongsTo
    {
        return $this->belongsTo(OutageStatus::class, 'to_status_id');
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
