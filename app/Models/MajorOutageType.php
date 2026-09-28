<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasAuditColumns;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Cache;

#[Fillable(['name', 'code', 'description', 'requires_noc_confirmation', 'requires_infrastructure', 'uses_topology', 'requires_it_review', 'sort_order', 'is_active'])]
class MajorOutageType extends Model
{
    use Auditable, HasAuditColumns;

    public const NETWORK = 'network';

    public const CORE = 'core';

    public const SYSTEM = 'system';

    protected function casts(): array
    {
        return [
            'requires_noc_confirmation' => 'boolean',
            'requires_infrastructure' => 'boolean',
            'uses_topology' => 'boolean',
            'requires_it_review' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public const FLAGS_CACHE_KEY = 'oms.major-type-flags.v2';

    protected static function booted(): void
    {
        static::saved(fn () => Cache::forget(self::FLAGS_CACHE_KEY));
    }

    /**
     * Workflow flags by id, cached — lets policies decide per table row without a query.
     *
     * @return array{requires_noc_confirmation: bool, requires_infrastructure: bool, uses_topology: bool, requires_it_review: bool}
     */
    public static function flags(?int $id): array
    {
        $all = Cache::rememberForever(self::FLAGS_CACHE_KEY, fn () => static::query()
            ->get(['id', 'requires_noc_confirmation', 'requires_infrastructure', 'uses_topology', 'requires_it_review'])
            ->mapWithKeys(fn (self $type) => [$type->id => [
                'requires_noc_confirmation' => $type->requires_noc_confirmation,
                'requires_infrastructure' => $type->requires_infrastructure,
                'uses_topology' => $type->uses_topology,
                'requires_it_review' => $type->requires_it_review,
            ]])->all());

        return $all[$id] ?? ['requires_noc_confirmation' => true, 'requires_infrastructure' => true, 'uses_topology' => false, 'requires_it_review' => false];
    }

    public function outageTypes(): HasMany
    {
        return $this->hasMany(OutageType::class);
    }

    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }
}
