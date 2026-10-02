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

/**
 * 4G transmission / backhaul node. The hierarchy is RSG → ASG → CSG → SSG:
 * an SSG carries one eNodeB, a CSG a ring of SSGs, an ASG the CSG rings and the RSG every ASG ring.
 */
#[Fillable([
    'type', 'code', 'name', 'parent_id', 'enodeb_id', 'hostname', 'management_ip', 'gateway_ip', 'equipment',
    'site_name', 'tower_company', 'tower_co_site_id', 'status_note', 'description', 'is_active',
])]
class TransmissionNode extends Model
{
    use Auditable, HasAuditColumns;

    public const RSG = 'RSG';

    public const ASG = 'ASG';

    public const CSG = 'CSG';

    public const SSG = 'SSG';

    /** Top of the hierarchy first. */
    public const TYPES = [
        self::RSG => 'RSG — carries all ASG rings',
        self::ASG => 'ASG — carries the CSG rings',
        self::CSG => 'CSG — ring of eNodeBs',
        self::SSG => 'SSG — one eNodeB',
    ];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function enodeb(): BelongsTo
    {
        return $this->belongsTo(Enodeb::class);
    }

    public function outages(): BelongsToMany
    {
        return $this->belongsToMany(Outage::class, 'outage_transmission_node');
    }

    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    public function scopeOfType(Builder $query, string $type): void
    {
        $query->where('type', $type);
    }

    /** "SSG-1111 — Nsambya Kevina" */
    public function label(): string
    {
        return $this->code.($this->name !== $this->code ? ' — '.$this->name : '');
    }

    /**
     * The given nodes plus everything below them in the hierarchy, as id => type.
     *
     * @param  array<int>  $ids
     * @return array<int, string>
     */
    public static function withDescendants(array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', array_filter($ids))));

        if ($ids === []) {
            return [];
        }

        $nodes = static::query()->get(['id', 'parent_id', 'type', 'is_active']);
        $children = $nodes->groupBy('parent_id');
        $types = $nodes->pluck('type', 'id');
        $found = [];
        $queue = $ids;

        while ($queue !== []) {
            $id = array_shift($queue);

            if (isset($found[$id]) || ! isset($types[$id])) {
                continue;
            }

            $found[$id] = $types[$id];

            // A selected node counts whatever its state; below it only nodes in service do.
            foreach ($children->get($id, []) as $child) {
                if ($child->is_active) {
                    $queue[] = $child->id;
                }
            }
        }

        return $found;
    }
}
