<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasAuditColumns;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Operating region. OLTs (and through them customers and tickets) belong to a region; dispatchers
 * and CX field support locked to regions only receive tickets of those regions.
 */
#[Fillable(['name', 'code', 'description', 'is_active'])]
class Region extends Model
{
    use Auditable, HasAuditColumns;

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function olts(): HasMany
    {
        return $this->hasMany(Olt::class);
    }

    public function customers(): HasMany
    {
        return $this->hasMany(Customer::class);
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class);
    }

    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }
}
