<?php

namespace App\Models;

use App\Enums\QueueType;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasAuditColumns;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/** One allowed hand-off: the people at "from" may send a ticket to "to". */
#[Fillable(['from_desk', 'to_desk', 'label', 'sort_order', 'is_active'])]
class DeskRoute extends Model
{
    use Auditable, HasAuditColumns;

    private const CACHE_KEY = 'oms.desk-routes';

    protected function casts(): array
    {
        return ['from_desk' => QueueType::class, 'to_desk' => QueueType::class, 'is_active' => 'boolean'];
    }

    protected static function booted(): void
    {
        static::saved(fn () => Cache::forget(self::CACHE_KEY));
        static::deleted(fn () => Cache::forget(self::CACHE_KEY));
    }

    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * Desks a ticket may be sent to from the given desk, in menu order: desk value => label.
     *
     * @return array<string, string>
     */
    public static function targets(?QueueType $from): array
    {
        if (! $from) {
            return [];
        }

        $all = Cache::remember(self::CACHE_KEY, now()->addMinutes(10), fn () => static::query()->active()
            ->orderBy('sort_order')->orderBy('id')->get(['from_desk', 'to_desk', 'label'])
            ->map(fn (self $route) => [$route->from_desk->value, $route->to_desk->value, $route->label])->all());

        $targets = [];

        foreach ($all as [$source, $target, $label]) {
            if ($source === $from->value && ($desk = QueueType::tryFrom($target))) {
                $targets[$target] = $label ?: $desk->getLabel();
            }
        }

        return $targets;
    }

    public static function allows(?QueueType $from, QueueType $to): bool
    {
        return array_key_exists($to->value, self::targets($from));
    }
}
