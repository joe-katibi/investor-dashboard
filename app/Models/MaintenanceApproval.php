<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Preventive maintenance downtime approval by the Dispatcher or the Infrastructure Manager. */
#[Fillable(['outage_id', 'slot', 'user_id', 'decision', 'comment'])]
class MaintenanceApproval extends Model
{
    public const DISPATCHER = 'dispatcher';

    public const MANAGER = 'manager';

    /** Slot => [label, permission]. */
    public const SLOTS = [
        self::DISPATCHER => ['Dispatcher', 'preventive.approve_dispatch'],
        self::MANAGER => ['Infrastructure Manager', 'preventive.approve_manager'],
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function slotLabel(): string
    {
        return self::SLOTS[$this->slot][0] ?? $this->slot;
    }

    /**
     * The approval this user may still give on the ticket: one person gives at most one approval,
     * so the Dispatcher and the Infrastructure Manager are always two different people.
     */
    public static function openSlotFor(Outage $outage, User $user): ?string
    {
        $given = static::query()->where('outage_id', $outage->id)->get(['slot', 'user_id']);

        if ($given->contains('user_id', $user->id)) {
            return null;
        }

        foreach (self::SLOTS as $slot => [, $permission]) {
            if (! $given->contains('slot', $slot) && $user->can($permission)) {
                return $slot;
            }
        }

        return null;
    }
}
