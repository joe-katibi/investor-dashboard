<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** CX Tier II callback on a NOC-rejected ticket, with an optional site visit raised. */
#[Fillable([
    'outage_id', 'user_id', 'called_at', 'call_outcome', 'callback_notes', 'site_visit_required',
    'site_visit_at', 'site_visit_reference', 'site_visit_notes', 'closed_ticket',
])]
class OutageFollowUp extends Model
{
    public const OUTCOMES = [
        'reached_resolved' => 'Customer reached — service working now',
        'reached_affected' => 'Customer reached — still affected',
        'reached_cpe_fault' => 'Customer reached — CPE / in-house fault',
        'no_answer' => 'No answer / unreachable',
        'wrong_number' => 'Wrong number',
        'call_back_later' => 'Customer asked to be called back later',
    ];

    protected function casts(): array
    {
        return [
            'called_at' => 'datetime',
            'site_visit_required' => 'boolean',
            'site_visit_at' => 'datetime',
            'closed_ticket' => 'boolean',
        ];
    }

    public function outcomeLabel(): string
    {
        return self::OUTCOMES[$this->call_outcome] ?? $this->call_outcome;
    }

    public function outage(): BelongsTo
    {
        return $this->belongsTo(Outage::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
