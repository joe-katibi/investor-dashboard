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

#[Fillable(['fdt_id', 'name', 'description', 'is_active'])]
class Slot extends Model
{
    use Auditable, HasAuditColumns;

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function fdt(): BelongsTo
    {
        return $this->belongsTo(Fdt::class);
    }

    public function ports(): HasMany
    {
        return $this->hasMany(Port::class);
    }

    public function outages(): BelongsToMany
    {
        return $this->belongsToMany(Outage::class, 'outage_slot');
    }

    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    public function getPathAttribute(): string
    {
        return ($this->fdt?->path ?? '?').' › Slot '.$this->name;
    }
}
