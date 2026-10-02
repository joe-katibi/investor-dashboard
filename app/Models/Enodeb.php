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

/** A 4G base station site (eNodeB), hosted on a tower company's site. */
#[Fillable([
    'site_id', 'name', 'tower_company', 'tower_co_site_id', 'vendor_id', 'latitude', 'longitude',
    'bracket_height', 'tower_height', 'location', 'description', 'is_active',
])]
class Enodeb extends Model
{
    use Auditable, HasAuditColumns;

    protected function casts(): array
    {
        return ['latitude' => 'float', 'longitude' => 'float', 'is_active' => 'boolean'];
    }

    /** Tower company powering the site (default vendor for a power outage here). */
    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function transmissionNodes(): HasMany
    {
        return $this->hasMany(TransmissionNode::class);
    }

    public function outages(): BelongsToMany
    {
        return $this->belongsToMany(Outage::class, 'outage_enodeb');
    }

    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /** "4624 — Nsambya Kevina (ATC)" */
    public function label(): string
    {
        return $this->site_id.' — '.$this->name.($this->tower_company ? " ({$this->tower_company})" : '');
    }
}
