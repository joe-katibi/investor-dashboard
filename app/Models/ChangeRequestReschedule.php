<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['change_request_id', 'from_start', 'from_end', 'to_start', 'to_end', 'reason', 'user_id'])]
class ChangeRequestReschedule extends Model
{
    protected function casts(): array
    {
        return [
            'from_start' => 'datetime',
            'from_end' => 'datetime',
            'to_start' => 'datetime',
            'to_end' => 'datetime',
        ];
    }

    public function changeRequest(): BelongsTo
    {
        return $this->belongsTo(ChangeRequest::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
