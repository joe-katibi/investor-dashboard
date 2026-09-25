<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasAuditColumns;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['user_id', 'team_id', 'work_date', 'shift_start', 'shift_end', 'status', 'notes'])]
class ShiftAssignment extends Model
{
    use Auditable, HasAuditColumns;

    protected function casts(): array
    {
        return [
            'work_date' => 'date',
            'shift_start' => 'datetime',
            'shift_end' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function scopeActiveAt(Builder $query, CarbonInterface $moment): void
    {
        $query->where('status', 'scheduled')
            ->where('shift_start', '<=', $moment)
            ->where('shift_end', '>=', $moment);
    }
}
