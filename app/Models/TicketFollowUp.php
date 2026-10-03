<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A call to the customer about a ticket: who called, when, and how it went. */
class TicketFollowUp extends Model
{
    protected $guarded = [];

    public const OUTCOMES = [
        'confirmed' => 'Reached — customer confirms it is resolved',
        'not_resolved' => 'Reached — still not resolved',
        'updated' => 'Reached — customer updated on progress',
        'rescheduled' => 'Reached — customer asked for another date / time',
        'call_back' => 'Reached — asked to be called back',
        'not_picking' => 'Not picking',
        'unreachable' => 'Unreachable / switched off',
        'voicemail' => 'Voice mail',
        'dropped' => 'Call dropped',
    ];

    /** Outcomes where nobody spoke to the customer. */
    public const NOT_REACHED = ['not_picking', 'unreachable', 'voicemail', 'dropped'];

    public const KINDS = [
        'callback' => 'Call-back', 'pending' => 'Pending ticket follow-up', 'confirmation' => 'Closure confirmation',
        'field_support' => 'Field support',
    ];

    protected function casts(): array
    {
        return ['called_at' => 'datetime'];
    }

    public function outage(): BelongsTo
    {
        return $this->belongsTo(Outage::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function outcomeLabel(): string
    {
        return self::OUTCOMES[$this->outcome] ?? (string) $this->outcome;
    }

    public function reached(): bool
    {
        return ! in_array($this->outcome, self::NOT_REACHED, true);
    }
}
