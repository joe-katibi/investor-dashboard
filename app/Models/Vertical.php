<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasAuditColumns;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'code', 'description', 'is_active'])]
class Vertical extends Model
{
    use Auditable, HasAuditColumns;

    public const FTTH = 'FTTH';

    public const MOBILITY_4G = '4G';

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    /** The id of a vertical by its code (Vertical::FTTH, Vertical::MOBILITY_4G); null when it does not exist. */
    public static function idFor(string $code): ?int
    {
        $id = static::query()->where('code', $code)->value('id');

        return $id ? (int) $id : null;
    }

    public function services(): HasMany
    {
        return $this->hasMany(Service::class);
    }

    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }
}
