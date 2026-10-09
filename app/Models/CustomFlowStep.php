<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One step of a custom flow: the team that works it, how tickets are given out, and its times. */
#[Fillable([
    'custom_flow_id', 'position', 'name', 'instructions', 'team_id', 'assignment_mode', 'target_minutes',
    'can_send_back', 'note_required',
])]
class CustomFlowStep extends Model
{
    use Auditable;

    /** What a new step starts with (also on a model that was just created and not read back). */
    protected $attributes = ['position' => 0, 'assignment_mode' => 'auto', 'can_send_back' => true, 'note_required' => false];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'target_minutes' => 'integer',
            'can_send_back' => 'boolean',
            'note_required' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        // Adding, changing, moving or removing a step makes a new version of the flow.
        $bump = fn (CustomFlowStep $step) => $step->flow?->touchVersion();

        static::saved(function (CustomFlowStep $step) use ($bump): void {
            if ($step->wasRecentlyCreated || $step->wasChanged()) {
                $bump($step);
            }
        });
        static::deleted($bump);
    }

    public function flow(): BelongsTo
    {
        return $this->belongsTo(CustomFlow::class, 'custom_flow_id');
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }
}
