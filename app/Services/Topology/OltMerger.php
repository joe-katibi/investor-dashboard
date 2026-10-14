<?php

namespace App\Services\Topology;

use App\Models\Olt;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Folds duplicate OLTs into the one that was there first — the OLTs a customer file created under
 * another spelling ("Seguku-OLT" next to SEGUKU). The FDTs, slots and ports are merged by name; one
 * that exists only under the duplicate is moved across. Customers, tickets, outages and team
 * coverage then point at what is kept, the duplicates are deleted, and their names become aliases
 * of the OLT kept, so the imports match them from then on.
 */
class OltMerger
{
    /** Where each level of the topology is referenced: table => column. */
    private const REFERENCES = [
        'olt' => ['customers' => 'olt_id', 'tickets' => 'olt_id'],
        'fdt' => ['customers' => 'fdt_id', 'tickets' => 'fdt_id'],
        'slot' => ['customers' => 'slot_id', 'tickets' => 'slot_id'],
        'port' => ['customers' => 'port_id', 'tickets' => 'port_id'],
    ];

    /** Link tables (outage_id / team_id + the topology id), where a pair may already exist for the one kept. */
    private const PIVOTS = [
        'olt' => [['outage_olt', 'outage_id'], ['olt_team', 'team_id']],
        'fdt' => [['outage_fdt', 'outage_id']],
        'slot' => [['outage_slot', 'outage_id']],
        'port' => [['outage_port', 'outage_id']],
    ];

    /**
     * The merges to make: OLTs whose names match (Olt::matchKey) — the oldest is kept — and the
     * pairs given ("Duplicate=Kept", by name or id) for names spelt differently.
     *
     * @param  list<string>  $pairs
     * @return list<array{from: Olt, into: Olt}>
     */
    public function plan(array $pairs = []): array
    {
        $olts = Olt::query()->orderBy('id')->get();
        $find = function (string $ref) use ($olts): Olt {
            $ref = trim($ref);
            $match = ctype_digit($ref) ? $olts->firstWhere('id', (int) $ref) : $olts->first(fn (Olt $olt) => strcasecmp($olt->name, $ref) === 0);

            return $match ?? throw new InvalidArgumentException("No OLT called or numbered “{$ref}”.");
        };

        $merges = [];

        foreach ($olts->groupBy(fn (Olt $olt) => Olt::matchKey($olt->name)) as $group) {
            $keep = $group->sortBy([['created_at', 'asc'], ['id', 'asc']])->first();

            foreach ($group->reject(fn (Olt $olt) => $olt->is($keep)) as $duplicate) {
                $merges[$duplicate->id] = ['from' => $duplicate, 'into' => $keep];
            }
        }

        foreach ($pairs as $pair) {
            if (! str_contains($pair, '=')) {
                throw new InvalidArgumentException("“{$pair}”: write it as Duplicate=Kept, e.g. \"Salama-OLT=Salaama\".");
            }

            [$from, $into] = array_map($find, explode('=', $pair, 2));

            if ($from->is($into)) {
                throw new InvalidArgumentException("“{$pair}”: the same OLT on both sides.");
            }

            $merges[$from->id] = ['from' => $from, 'into' => $into];
        }

        // An OLT that is itself merged away cannot receive another one.
        foreach ($merges as $merge) {
            if (isset($merges[$merge['into']->id])) {
                throw new InvalidArgumentException("{$merge['from']->name} would be merged into {$merge['into']->name}, which is itself merged away.");
            }
        }

        return array_values($merges);
    }

    /**
     * What a merge would do (and, once applied, did).
     *
     * @return array{fdts_merged: int, fdts_moved: int, slots_merged: int, slots_moved: int, ports_merged: int, ports_moved: int, customers: int, tickets: int, outages: int}
     */
    public function preview(Olt $from, Olt $into): array
    {
        $counts = ['fdts_merged' => 0, 'fdts_moved' => 0, 'slots_merged' => 0, 'slots_moved' => 0, 'ports_merged' => 0, 'ports_moved' => 0];

        foreach ($this->children('fdts', 'olt_id', $from->id) as $fdt) {
            $twin = $this->twin('fdts', 'olt_id', $into->id, $fdt->name);
            $counts[$twin ? 'fdts_merged' : 'fdts_moved']++;

            if (! $twin) {
                continue;
            }

            foreach ($this->children('slots', 'fdt_id', $fdt->id) as $slot) {
                $twinSlot = $this->twin('slots', 'fdt_id', $twin, $slot->name);
                $counts[$twinSlot ? 'slots_merged' : 'slots_moved']++;

                if ($twinSlot) {
                    foreach ($this->children('ports', 'slot_id', $slot->id) as $port) {
                        $counts[$this->twin('ports', 'slot_id', $twinSlot, $port->name) ? 'ports_merged' : 'ports_moved']++;
                    }
                }
            }
        }

        return $counts + [
            'customers' => DB::table('customers')->where('olt_id', $from->id)->count(),
            'tickets' => DB::table('tickets')->where('olt_id', $from->id)->count(),
            'outages' => DB::table('outage_olt')->where('olt_id', $from->id)->count(),
        ];
    }

    /** Merge one duplicate into the OLT kept. Run inside a transaction. */
    public function merge(Olt $from, Olt $into): void
    {
        foreach ($this->children('fdts', 'olt_id', $from->id) as $fdt) {
            $twin = $this->twin('fdts', 'olt_id', $into->id, $fdt->name);

            if (! $twin) {
                DB::table('fdts')->where('id', $fdt->id)->update(['olt_id' => $into->id, 'updated_at' => now()]);

                continue;
            }

            foreach ($this->children('slots', 'fdt_id', $fdt->id) as $slot) {
                $twinSlot = $this->twin('slots', 'fdt_id', $twin, $slot->name);

                if (! $twinSlot) {
                    DB::table('slots')->where('id', $slot->id)->update(['fdt_id' => $twin, 'updated_at' => now()]);

                    continue;
                }

                foreach ($this->children('ports', 'slot_id', $slot->id) as $port) {
                    $twinPort = $this->twin('ports', 'slot_id', $twinSlot, $port->name);

                    if (! $twinPort) {
                        DB::table('ports')->where('id', $port->id)->update(['slot_id' => $twinSlot, 'updated_at' => now()]);

                        continue;
                    }

                    $this->repoint('port', $port->id, $twinPort);
                    DB::table('ports')->where('id', $port->id)->delete();
                }

                $this->repoint('slot', $slot->id, $twinSlot);
                DB::table('slots')->where('id', $slot->id)->delete();
            }

            $this->repoint('fdt', $fdt->id, $twin);
            DB::table('fdts')->where('id', $fdt->id)->delete();
        }

        $this->repoint('olt', $from->id, $into->id);

        // The name the duplicate had is how the files call it: kept as an alias. A region it had is kept if the OLT has none.
        $into->forceFill([
            'aliases' => array_values(array_unique([...(array) $into->aliases, $from->name, ...(array) $from->aliases])),
            'region_id' => $into->region_id ?? $from->region_id,
        ])->save();

        DB::table('olts')->where('id', $from->id)->delete();
    }

    /** @return Collection<int, object{id: int, name: string}> */
    private function children(string $table, string $parent, int $parentId): Collection
    {
        return DB::table($table)->where($parent, $parentId)->orderBy('id')->get(['id', 'name']);
    }

    /** The row with the same name (no case, no outer spaces) under the parent kept. */
    private function twin(string $table, string $parent, int $parentId, string $name): ?int
    {
        $id = DB::table($table)->where($parent, $parentId)->whereRaw('LOWER(TRIM(name)) = ?', [strtolower(trim($name))])->orderBy('id')->value('id');

        return $id ? (int) $id : null;
    }

    private function repoint(string $level, int $from, int $to): void
    {
        foreach (self::REFERENCES[$level] as $table => $column) {
            DB::table($table)->where($column, $from)->update([$column => $to]);
        }

        foreach (self::PIVOTS[$level] as [$pivot, $other]) {
            $column = $level.'_id';
            // Already linked to the one kept: the duplicate's link goes; otherwise it moves across.
            $both = DB::table($pivot)->where($column, $to)->pluck($other)->all();
            DB::table($pivot)->where($column, $from)->whereIn($other, $both)->delete();
            DB::table($pivot)->where($column, $from)->update([$column => $to]);
        }
    }
}
