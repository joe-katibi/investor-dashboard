<?php

namespace App\Services\Outages;

use App\Models\Priority;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Evaluates the UI-configurable impact rules (customer thresholds) to a priority.
 * When several ranges overlap, the most severe priority wins.
 */
class PriorityResolver
{
    public const CACHE_KEY = 'oms.priorities.active';

    public function forCustomers(int $customers): ?Priority
    {
        $priorities = $this->active();

        return $priorities
            ->filter(fn (Priority $priority) => ($priority->min_customers !== null || $priority->max_customers !== null)
                && $priority->matchesCustomers($customers))
            ->sortByDesc('severity')
            ->first()
            ?? $priorities->firstWhere('is_default', true);
    }

    /** @return Collection<int, Priority> */
    public function active(): Collection
    {
        // Cache raw attributes only; the cache store refuses to unserialize objects.
        return Priority::hydrate(Cache::remember(
            self::CACHE_KEY,
            now()->addMinutes(10),
            fn () => Priority::query()->active()->orderByDesc('severity')->get()->map->getAttributes()->all(),
        ));
    }

    public static function flush(): void
    {
        Cache::forget(self::CACHE_KEY);
    }
}
