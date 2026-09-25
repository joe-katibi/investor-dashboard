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
    'name', 'code', 'description', 'color', 'prompt_final_reason', 'is_closed', 'is_resolved',
    'pauses_sla', 'is_manual_selectable', 'is_system', 'sort_order', 'is_active',
])]
class OutageStatus extends Model
{
    use Auditable, HasAuditColumns;

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
}
