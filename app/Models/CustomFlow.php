<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasAuditColumns;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A workflow built from the screen (Configuration → Custom Workflows): a name, a ticket prefix
 * and an ordered list of steps. Tickets raised in it carry a copy of the steps, so the flow can
 * be edited at any time without touching tickets already on their way.
 */
#[Fillable(['name', 'prefix', 'description', 'sla_minutes', 'raise_roles', 'requires_resolution', 'is_active'])]
class CustomFlow extends Model
{
    use Auditable, HasAuditColumns;

    protected $attributes = ['version' => 1, 'is_active' => true, 'requires_resolution' => true];

    protected function casts(): array
    {
        return [
            'raise_roles' => 'array',
            'requires_resolution' => 'boolean',
            'is_active' => 'boolean',
            'sla_minutes' => 'integer',
            'version' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (CustomFlow $flow): void {
            $flow->prefix = strtoupper(trim((string) $flow->prefix));

            // Every change is a new version; tickets remember the one they were raised under.
            if ($flow->exists && $flow->isDirty() && ! $flow->isDirty('version')) {
                $flow->version = (int) $flow->version + 1;
            }
        });
    }

    public function steps(): HasMany
    {
        return $this->hasMany(CustomFlowStep::class)->orderBy('position')->orderBy('id');
    }

    public function tickets(): HasMany
    {
        return $this->hasMany(CustomTicket::class);
    }

    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /** May this person raise a ticket in the flow? (Besides holding the permission to raise custom tickets.) */
    public function mayBeRaisedBy(User $user): bool
    {
        $roles = array_values(array_filter((array) $this->raise_roles));

        return $this->is_active && ($roles === [] || $user->hasAnyRole($roles));
    }

    /** Usable: active, and every step names the team that works it. */
    public function problems(): array
    {
        $steps = $this->steps;

        return array_values(array_filter([
            $steps->isEmpty() ? 'The flow has no steps yet.' : null,
            ...$steps->filter(fn (CustomFlowStep $step) => $step->team_id === null)
                ->map(fn (CustomFlowStep $step) => "Step “{$step->name}” has no team — nobody would receive its tickets.")->all(),
        ]));
    }

    /**
     * The steps as a ticket keeps them.
     *
     * @return list<array<string, mixed>>
     */
    public function snapshot(): array
    {
        return $this->steps->values()->map(fn (CustomFlowStep $step) => [
            'id' => $step->id,
            'name' => $step->name,
            'instructions' => $step->instructions,
            'team_id' => $step->team_id,
            'team' => $step->team?->name,
            'assignment_mode' => $step->assignment_mode,
            'target_minutes' => (int) $step->target_minutes,
            'can_send_back' => (bool) $step->can_send_back,
            'note_required' => (bool) $step->note_required,
        ])->all();
    }

    /** A change of the steps is a change of the flow. */
    public function touchVersion(): void
    {
        $this->forceFill(['version' => (int) $this->version + 1])->saveQuietly();
    }
}
