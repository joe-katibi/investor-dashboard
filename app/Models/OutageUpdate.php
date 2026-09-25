<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['outage_id', 'user_id', 'type', 'body', 'is_system'])]
class OutageUpdate extends Model
{
    public const TYPES = [
        'note' => 'Note',
        'progress' => 'Progress Update',
        'action' => 'Action Taken',
        'restoration' => 'Restoration',
        'escalation' => 'Escalation',
        'system' => 'System',
    ];

    protected function casts(): array
    {
        return ['is_system' => 'boolean'];
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
