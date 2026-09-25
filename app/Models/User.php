<?php

namespace App\Models;

use App\Enums\AssignmentStatus;
use App\Enums\AvailabilityStatus;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasAuditColumns;
use Database\Factories\UserFactory;
use Filament\Auth\MultiFactor\App\Concerns\InteractsWithAppAuthentication;
use Filament\Auth\MultiFactor\App\Concerns\InteractsWithAppAuthenticationRecovery;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthentication;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthenticationRecovery;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;

#[Fillable([
    'name', 'username', 'email', 'phone', 'password', 'department_id', 'description', 'is_active',
    'availability_status', 'availability_changed_at', 'last_assigned_at', 'max_active_assignments',
])]
#[Hidden(['password', 'remember_token', 'app_authentication_secret', 'app_authentication_recovery_codes'])]
class User extends Authenticatable implements FilamentUser, HasAppAuthentication, HasAppAuthenticationRecovery
{
    /** @use HasFactory<UserFactory> */
    use Auditable, HasAuditColumns, HasFactory, HasRoles, Notifiable;

    use InteractsWithAppAuthentication, InteractsWithAppAuthenticationRecovery;

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'availability_status' => AvailabilityStatus::class,
            'availability_changed_at' => 'datetime',
            'last_assigned_at' => 'datetime',
            'last_login_at' => 'datetime',
            'max_active_assignments' => 'integer',
        ];
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return $this->is_active;
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function teams(): BelongsToMany
    {
        return $this->belongsToMany(Team::class)->withTimestamps();
    }

    public function skills(): HasMany
    {
        return $this->hasMany(UserSkill::class);
    }

    public function shiftAssignments(): HasMany
    {
        return $this->hasMany(ShiftAssignment::class);
    }

    public function leaves(): HasMany
    {
        return $this->hasMany(UserLeave::class);
    }

    public function availabilityHistory(): HasMany
    {
        return $this->hasMany(UserAvailability::class)->latest('effective_from');
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(OutageAssignment::class);
    }

    public function activeAssignments(): HasMany
    {
        return $this->assignments()->whereIn('status', AssignmentStatus::activeValues());
    }

    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    public function isAvailable(): bool
    {
        return $this->availability_status === AvailabilityStatus::Available;
    }

    /** All team ids the user belongs to, including parents of their sub-teams. */
    public function teamIdsWithParents(): array
    {
        $teams = $this->relationLoaded('teams') ? $this->teams : $this->teams()->get(['teams.id', 'teams.parent_id']);

        return $teams->flatMap(fn (Team $team) => array_filter([$team->id, $team->parent_id]))->unique()->values()->all();
    }
}
