<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasAuditColumns;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'code', 'location', 'vertical_id', 'region_id', 'description', 'is_active'])]
class Olt extends Model
{
    use Auditable, HasAuditColumns;

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function vertical(): BelongsTo
    {
        return $this->belongsTo(Vertical::class);
    }

    public function region(): BelongsTo
    {
        return $this->belongsTo(Region::class);
    }

    public function fdts(): HasMany
    {
        return $this->hasMany(Fdt::class);
    }

    public function teams(): BelongsToMany
    {
        return $this->belongsToMany(Team::class);
    }

    public function outages(): BelongsToMany
    {
        return $this->belongsToMany(Outage::class, 'outage_olt');
    }

    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }
}
