<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasAuditColumns;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** What the customer contacted us about, at the top level: Technical, Billing, Refund, Credit… */
#[Fillable(['name', 'code', 'description', 'sort_order', 'is_active'])]
class TicketCategory extends Model
{
    use Auditable, HasAuditColumns;

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function subcategories(): HasMany
    {
        return $this->hasMany(TicketSubcategory::class)->orderBy('sort_order')->orderBy('name');
    }

    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }
}
