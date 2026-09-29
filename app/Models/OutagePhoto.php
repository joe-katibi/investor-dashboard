<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Site photo of a field maintenance ticket, stored privately on the local disk. */
#[Fillable(['outage_id', 'stage', 'position', 'path', 'original_name', 'user_id'])]
class OutagePhoto extends Model
{
    public const BEFORE = 'before';

    public const AFTER = 'after';

    public const POSITIONS = [
        'outside' => 'Outside the FAT / FDT (label visible)',
        'inside' => 'Inside the FAT / FDT',
    ];

    public function outage(): BelongsTo
    {
        return $this->belongsTo(Outage::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function label(): string
    {
        return ($this->stage === self::AFTER ? 'After — ' : 'Before — ').(self::POSITIONS[$this->position] ?? $this->position);
    }

    public function url(): string
    {
        return route('outage-photos.show', $this);
    }
}
