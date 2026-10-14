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
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

#[Fillable(['name', 'code', 'aliases', 'location', 'vertical_id', 'region_id', 'description', 'is_active'])]
class Olt extends Model
{
    use Auditable, HasAuditColumns;

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'aliases' => 'array'];
    }

    /**
     * The name as the imports compare it: no case, no spaces or punctuation, no word "OLT" — so
     * "Seguku-OLT" is SEGUKU, "Kabowa-FH-OLT" is "KABOWA FH" and "Nsambya-OLT_2" is "NSAMBYA 2-OLT".
     */
    public static function matchKey(string $name): string
    {
        $plain = strtolower(trim($name));
        $key = (string) preg_replace('/[^a-z0-9]/', '', (string) preg_replace('/(?<![a-z])olt(?![a-z])/', '', $plain));

        return $key !== '' ? $key : (string) preg_replace('/[^a-z0-9]/', '', $plain);
    }

    /** @return list<string> the keys this OLT answers to: its name and every alias */
    public function matchKeys(): array
    {
        return array_values(array_unique(array_map(fn (string $name) => self::matchKey($name), [$this->name, ...(array) $this->aliases])));
    }

    /**
     * Every OLT by each of its keys; where two share one, the oldest wins.
     *
     * @param  iterable<Olt>  $olts
     * @return array<string, Olt>
     */
    public static function byMatchKey(iterable $olts): array
    {
        $map = [];

        foreach (collect($olts)->sortBy('id') as $olt) {
            foreach ($olt->matchKeys() as $key) {
                $map[$key] ??= $olt;
            }
        }

        return $map;
    }

    public function vertical(): BelongsTo
    {
        return $this->belongsTo(Vertical::class);
    }

    public function region(): BelongsTo
    {
        return $this->belongsTo(Region::class);
    }

    public function fdts(): HasMany
    {
        return $this->hasMany(Fdt::class);
    }

    public function slots(): HasManyThrough
    {
        return $this->hasManyThrough(Slot::class, Fdt::class);
    }

    public function teams(): BelongsToMany
    {
        return $this->belongsToMany(Team::class);
    }

    public function outages(): BelongsToMany
    {
        return $this->belongsToMany(Outage::class, 'outage_olt');
    }

    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }
}
