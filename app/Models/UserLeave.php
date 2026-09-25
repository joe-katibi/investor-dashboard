<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasAuditColumns;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['user_id', 'starts_at', 'ends_at', 'leave_type', 'reason', 'status', 'approved_by'])]
class UserLeave extends Model
{
    use Auditable, HasAuditColumns;

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function scopeApprovedAt(Builder $query, CarbonInterface $moment): void
    {
        $query->where('status', 'approved')
            ->where('starts_at', '<=', $moment)
            ->where('ends_at', '>=', $moment);
    }
}
