<?php

namespace App\Services\Outages;

use App\Models\ImpactRule;
use App\Models\Priority;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Evaluates the UI-configurable impact rules to a priority. Each vertical has its own priorities:
 * FTTH rates by customers affected (thresholds on the priority), 4G by network elements affected
 * (impact rules per outage type). When several ranges / rules match, the most severe priority wins.
 */
class PriorityResolver
{
    public const CACHE_KEY = 'oms.priorities.active';

    public const RULES_CACHE_KEY = 'oms.impact-rules.active';

    public function forCustomers(int $customers, ?int $verticalId = null): ?Priority
    {
        $priorities = $this->forVertical($verticalId);

        return $priorities
            ->filter(fn (Priority $priority) => ($priority->min_customers !== null || $priority->max_customers !== null)
                && $priority->matchesCustomers($customers))
            ->sortByDesc('severity')
            ->first()
            ?? $priorities->firstWhere('is_default', true);
    }

    /**
     * 4G: severity from the elements affected, e.g. ['enodebs' => 4, 'ssg' => 1, 'asg' => 0].
     *
     * @param  array<string, int>  $counts
     * @return array{priority: ?Priority, rule: ?ImpactRule} the winning priority and the rule that set it
     */
    public function forImpact(int $verticalId, ?int $outageTypeId, array $counts): array
    {
        $priorities = $this->forVertical($verticalId)->keyBy('id');
        $winner = null;
        $winningRule = null;

        foreach ($this->rules() as $rule) {
            $priority = $priorities->get($rule->priority_id);

            if (! $priority
                || (int) $rule->vertical_id !== $verticalId
                || ($rule->outage_type_id !== null && (int) $rule->outage_type_id !== (int) $outageTypeId)
                || ! $rule->matchesValue((int) ($counts[$rule->metric] ?? 0))) {
                continue;
            }

            if (! $winner || $priority->severity > $winner->severity) {
                [$winner, $winningRule] = [$priority, $rule];
            }
        }

        return [
            'priority' => $winner ?? $priorities->firstWhere('is_default', true) ?? $priorities->sortBy('severity')->first(),
            'rule' => $winningRule,
        ];
    }

    /**
     * The priorities of a vertical. A vertical without its own set uses the shared ones (no
     * vertical), and failing that the customer-based (FTTH) set.
     *
     * @return Collection<int, Priority>
     */
    public function forVertical(?int $verticalId): Collection
    {
        $all = $this->active();

        if ($verticalId) {
            $own = $all->filter(fn (Priority $priority) => (int) $priority->vertical_id === $verticalId)->values();

            if ($own->isNotEmpty()) {
                return $own;
            }
        }

        $shared = $all->filter(fn (Priority $priority) => $priority->vertical_id === null)->values();

        return $shared->isNotEmpty()
            ? $shared
            : $all->filter(fn (Priority $priority) => $priority->min_customers !== null || $priority->max_customers !== null || ! str_ends_with((string) $priority->code, '_4g'))->values();
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

    /** @return Collection<int, ImpactRule> */
    public function rules(): Collection
    {
        return ImpactRule::hydrate(Cache::remember(
            self::RULES_CACHE_KEY,
            now()->addMinutes(10),
            fn () => ImpactRule::query()->active()->orderBy('id')->get()->map->getAttributes()->all(),
        ));
    }

    public static function flush(): void
    {
        Cache::forget(self::CACHE_KEY);
        Cache::forget(self::RULES_CACHE_KEY);
    }
}
