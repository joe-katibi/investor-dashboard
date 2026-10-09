<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasAuditColumns;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

#[Fillable(['name', 'vertical_id', 'review_team_id', 'description', 'is_active'])]
class Service extends Model
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

    /**
     * The team that reviews system outages of this system (Savbill → CRM Managers, CPaaS → CPaaS
     * Manager). None: the IT desk reviews them.
     */
    public function reviewTeam(): BelongsTo
    {
        return $this->belongsTo(Team::class, 'review_team_id');
    }

    public function outages(): BelongsToMany
    {
        return $this->belongsToMany(Outage::class, 'outage_service');
    }

    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }
}
