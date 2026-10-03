<?php

namespace App\Models;

use App\Enums\StatusCode;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasAuditColumns;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

#[Fillable([
    'name', 'code', 'workflow', 'description', 'color', 'prompt_final_reason', 'is_closed', 'is_resolved',
    'pauses_sla', 'is_manual_selectable', 'is_system', 'sort_order', 'is_active',
])]
class OutageStatus extends Model
{
    use Auditable, HasAuditColumns;

    /** Statuses of the 4G NOC workflows (workflow column); null = the standard FTTH / core / system flows. */
    public const WORKFLOW_MOBILITY = 'mobility';

    private const CACHE_KEY = 'oms.status-codes';

    protected function casts(): array
    {
        return [
            'prompt_final_reason' => 'boolean',
            'is_closed' => 'boolean',
            'is_resolved' => 'boolean',
            'pauses_sla' => 'boolean',
            'is_manual_selectable' => 'boolean',
            'is_system' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saved(fn () => Cache::forget(self::CACHE_KEY));
        static::deleted(fn () => Cache::forget(self::CACHE_KEY));
    }

    /** Resolve a workflow status by its stable code (cached id lookup). */
    public static function forCode(StatusCode $code): self
    {
        $ids = Cache::rememberForever(self::CACHE_KEY, fn () => static::query()->whereNotNull('code')->pluck('id', 'code')->all());

        $id = $ids[$code->value] ?? null;

        return ($id ? static::query()->find($id) : null)
            ?? static::query()->where('code', $code->value)->firstOrFail();
    }

    /** Workflow code for a status id (cached), or null for custom statuses. */
    public static function codeForId(?int $id): ?string
    {
        $ids = Cache::rememberForever(self::CACHE_KEY, fn () => static::query()->whereNotNull('code')->pluck('id', 'code')->all());

        return array_search($id, $ids, false) ?: null;
    }

    public function isCode(StatusCode $code): bool
    {
        return $this->code === $code->value;
    }

    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    public function scopeStandard(Builder $query): void
    {
        $query->whereNull('workflow');
    }

    public function scopeMobility(Builder $query): void
    {
        $query->where('workflow', self::WORKFLOW_MOBILITY);
    }

    /** Statuses of customer tickets ("ticket") and site visits ("visit"). */
    public const WORKFLOW_TICKET = 'ticket';

    public const WORKFLOW_VISIT = 'visit';

    public function scopeTicketing(Builder $query, ?string $workflow = null): void
    {
        $query->whereIn('workflow', $workflow ? [$workflow] : [self::WORKFLOW_TICKET, self::WORKFLOW_VISIT]);
    }
}
