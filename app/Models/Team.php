<?php

namespace App\Models;

use App\Enums\TeamType;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasAuditColumns;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'department_id', 'parent_id', 'team_type', 'leader_id', 'manager_id', 'description', 'is_active'])]
class Team extends Model
{
    use Auditable, HasAuditColumns;

    protected function casts(): array
    {
        return [
            'team_type' => TeamType::class,
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        // Sub-teams always inherit the parent's department and team type.
        static::saving(function (Team $team): void {
            if ($team->parent_id && $parent = Team::query()->find($team->parent_id)) {
                $team->department_id = $parent->department_id;
                $team->team_type = $parent->team_type;
            }
        });
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Team::class, 'parent_id');
    }

    public function subTeams(): HasMany
    {
        return $this->hasMany(Team::class, 'parent_id');
    }

    public function leader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'leader_id');
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'manager_id');
    }

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withTimestamps();
    }

    public function olts(): BelongsToMany
    {
        return $this->belongsToMany(Olt::class);
    }

    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    public function scopeTopLevel(Builder $query): void
    {
        $query->whereNull('parent_id');
    }

    public function isSubTeam(): bool
    {
        return $this->parent_id !== null;
    }

    /** This team plus its direct sub-teams. */
    public function selfAndSubTeamIds(): array
    {
        return array_merge([$this->id], $this->subTeams()->pluck('id')->all());
    }
}
