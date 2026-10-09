<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The custom-flow side of a ticket (an Outage row with workflow "custom"): which flow, the steps
 * as they were when it was raised, and the step it is at.
 */
#[Fillable(['outage_id', 'custom_flow_id', 'flow_name', 'flow_version', 'steps', 'step_index', 'step_since', 'sent_back_count'])]
class CustomTicket extends Model
{
    protected $attributes = ['step_index' => 0, 'sent_back_count' => 0, 'flow_version' => 1];

    protected function casts(): array
    {
        return [
            'steps' => 'array',
            'step_index' => 'integer',
            'flow_version' => 'integer',
            'sent_back_count' => 'integer',
            'step_since' => 'datetime',
        ];
    }

    public function outage(): BelongsTo
    {
        return $this->belongsTo(Outage::class);
    }

    public function flow(): BelongsTo
    {
        return $this->belongsTo(CustomFlow::class, 'custom_flow_id');
    }

    /** @return ?array<string, mixed> the step the ticket is at */
    public function step(?int $index = null): ?array
    {
        return ($this->steps ?? [])[$index ?? $this->step_index] ?? null;
    }

    public function isLastStep(): bool
    {
        return $this->step_index >= count($this->steps ?? []) - 1;
    }

    /** "Step 2 of 4: Finance approval" */
    public function progressLabel(): string
    {
        $step = $this->step();

        return $step ? 'Step '.($this->step_index + 1).' of '.count($this->steps).': '.$step['name'] : 'Finished';
    }
}
