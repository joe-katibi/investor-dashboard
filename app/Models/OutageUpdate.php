<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['outage_id', 'user_id', 'type', 'actor_type', 'body', 'is_system'])]
class OutageUpdate extends Model
{
    public const TYPES = [
        'note' => 'Note',
        'progress' => 'Progress Update',
        'action' => 'Action Taken',
        'restoration' => 'Restoration',
        'escalation' => 'Escalation',
        'vendor' => 'Vendor Update',
        'attachment' => 'Attachment',
        'system' => 'System',
    ];

    /** Types a person can pick when posting an update. */
    public const MANUAL_TYPES = ['note', 'progress', 'action', 'restoration', 'escalation'];

    // Who an entry of the 4G ticket timeline came from.
    public const ACTOR_SYSTEM = 'System';

    public const ACTOR_NOC = 'NOC';

    public const ACTOR_ENGINEER = 'Engineer';

    public const ACTOR_TECHNICIAN = 'Technician';

    /** System, NOC, Engineer or Technician, from the permissions / role of the person acting. */
    public static function actorFor(?User $user): string
    {
        return match (true) {
            $user === null => self::ACTOR_SYSTEM,
            $user->can('mobility.verify') || $user->can('mobility.create') => self::ACTOR_NOC,
            $user->roles->contains(fn ($role) => str_contains(strtolower($role->name), 'technician')) => self::ACTOR_TECHNICIAN,
            $user->can('mobility.work') => self::ACTOR_ENGINEER,
            default => self::ACTOR_NOC,
        };
    }

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
