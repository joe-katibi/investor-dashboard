<?php

namespace App\Services\Outages;

use App\Models\Olt;
use App\Models\Outage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Moves outage tickets (with everything attached to them) from one OMS server to another that does
 * not share its database — e.g. the tickets raised on the old server after its database was copied
 * to the new one.
 *
 * export()  writes a folder: data.json (the outages, the rows of every table that hangs off them —
 *           found through the foreign keys — and how to recognise the users, OLTs, statuses… they
 *           point at) and files/ (their photos and attachments).
 * plan()    on the receiving server: which outages are already there (same ticket number and time
 *           raised — they came with the copy), which are new, which numbers are taken.
 * import()  inserts the new ones under fresh ids, re-pointing every reference; a ticket number
 *           already used there is replaced by the next free one.
 */
class OutageTransfer
{
    /** Tables that reference outages but are not part of an outage (customer tickets, logs of other things). */
    private const NOT_OWNED = ['tickets', 'notifications', 'jobs', 'failed_jobs', 'report_exports', 'audit_logs', 'outage_imports'];

    /** The columns that identify a row of a table that is not copied, besides its id. */
    private const KEYS = ['email', 'code', 'prefix', 'key', 'name'];

    // ------------------------------------------------------------------ export

    /**
     * @param  callable(Builder): void  $scope  which outages (e.g. FTTH network outages)
     * @return array<string, int> rows per table
     */
    public function export(Carbon $since, string $folder, callable $scope): array
    {
        $graph = $this->graph();
        $outageIds = DB::table('outages')->where(fn ($q) => $q->where('created_at', '>=', $since)->orWhere('updated_at', '>=', $since))
            ->whereIn('id', tap(Outage::query(), $scope)->select('id'))->orderBy('id')->pluck('id')->all();

        $rows = ['outages' => DB::table('outages')->whereIn('id', $outageIds)->orderBy('id')->get()->map(fn ($r) => (array) $r)->all()];
        $ids = ['outages' => $outageIds];

        foreach ($this->order($graph) as $table) {
            if ($table === 'outages') {
                continue;
            }

            $links = array_filter($graph[$table], fn (array $fk) => isset($ids[$fk['table']]) && $ids[$fk['table']] !== []);
            if ($links === []) {
                $rows[$table] = [];
                $ids[$table] = [];

                continue;
            }

            $query = DB::table($table)->where(function ($q) use ($links, $ids): void {
                foreach ($links as $fk) {
                    foreach (array_chunk($ids[$fk['table']], 1000) as $chunk) {
                        $q->orWhereIn($fk['column'], $chunk);
                    }
                }
            });
            $rows[$table] = $query->get()->map(fn ($r) => (array) $r)->all();
            $ids[$table] = Schema::hasColumn($table, 'id') ? array_column($rows[$table], 'id') : [];
        }

        $refs = $this->references($graph, $rows);
        $files = $this->files($rows);

        if (! is_dir($folder) && ! mkdir($folder, 0775, true) && ! is_dir($folder)) {
            throw new RuntimeException("Cannot create {$folder}.");
        }

        foreach ($files as $path) {
            $target = $folder.'/files/'.$path;
            @mkdir(dirname($target), 0775, true);
            copy(Storage::disk('local')->path($path), $target);
        }

        file_put_contents($folder.'/data.json', json_encode([
            'exported_at' => now()->toDateTimeString(),
            'since' => $since->toDateTimeString(),
            'server' => gethostname(),
            'graph' => $graph,
            'rows' => $rows,
            'refs' => $refs,
        ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));

        return array_map('count', array_filter($rows)) + ['files' => count($files)];
    }

    // ------------------------------------------------------------------ plan / import

    /**
     * What an import would do.
     *
     * @return array{new: list<array<string, mixed>>, present: list<array<string, mixed>>, changed: list<array<string, mixed>>, unresolved: array<string, list<string>>, export: array<string, mixed>}
     */
    public function plan(string $folder): array
    {
        $data = $this->read($folder);
        $plan = ['new' => [], 'present' => [], 'changed' => [], 'unresolved' => [], 'export' => ['server' => $data['server'], 'exported_at' => $data['exported_at'], 'since' => $data['since']]];

        foreach ($data['rows']['outages'] as $outage) {
            // Imported before (perhaps under a new number), or here since the copy: same ticket number and time raised.
            $imported = DB::table('outage_imports')->where('source_ticket_number', $outage['ticket_number'])
                ->where('source_created_at', Carbon::parse($outage['created_at']))->value('outage_id');
            $here = $imported
                ? DB::table('outages')->where('id', $imported)->first(['id', 'created_at', 'updated_at'])
                : DB::table('outages')->where('ticket_number', $outage['ticket_number'])->first(['id', 'created_at', 'updated_at']);
            $same = $here && ($imported || Carbon::parse($here->created_at)->equalTo(Carbon::parse($outage['created_at'])));
            $line = ['id' => $outage['id'], 'ticket' => $outage['ticket_number'], 'title' => $outage['title'] ?? '', 'raised' => $outage['created_at'],
                'number' => $here && ! $same ? 'taken here: new number' : 'kept'];

            if (! $same) {
                $plan['new'][] = $line;
            } elseif (Carbon::parse($outage['updated_at'])->greaterThan(Carbon::parse($here->updated_at))) {
                $plan['changed'][] = $line + ['updated_there' => $outage['updated_at'], 'updated_here' => $here->updated_at];
            } else {
                $plan['present'][] = $line;
            }
        }

        // References that cannot be found here, on the rows that would be imported.
        $resolver = $this->resolver($data);
        $new = array_flip(array_column($plan['new'], 'id'));
        foreach ($this->rowsToImport($data, $new) as $table => $rows) {
            foreach ($rows as $row) {
                foreach ($data['graph'][$table] ?? [] as $fk) {
                    $value = $row[$fk['column']] ?? null;
                    if ($value !== null && ! isset($data['rows'][$fk['table']]) && $resolver($fk['table'], $value) === null && ! $this->nullable($table, $fk['column'])) {
                        $plan['unresolved'][$fk['table']][] = $this->describe($data, $fk['table'], $value);
                    }
                }
            }
        }
        $plan['unresolved'] = array_map(fn (array $list) => array_values(array_unique($list)), $plan['unresolved']);

        return $plan;
    }

    /**
     * Import the outages not on this server yet. Runs in one transaction.
     *
     * @return list<array{from: string, to: string, id: int}> the outages imported
     */
    public function import(string $folder): array
    {
        $data = $this->read($folder);
        $plan = $this->plan($folder);

        if ($plan['unresolved'] !== []) {
            throw new RuntimeException('Some users / items the outages point at do not exist on this server: '.json_encode($plan['unresolved']));
        }

        $new = array_flip(array_column($plan['new'], 'id'));
        $taken = array_column(array_filter($plan['new'], fn (array $line) => $line['number'] !== 'kept'), 'ticket', 'id');
        $resolver = $this->resolver($data);
        $map = [];
        $imported = [];

        DB::transaction(function () use ($data, $new, $taken, $resolver, &$map, &$imported): void {
            // The numbers kept are reserved first: the counter moves past them, so a ticket that must be
            // renumbered never gets a number another imported ticket keeps.
            foreach ($data['rows']['outages'] as $outage) {
                if (isset($new[$outage['id']]) && ! isset($taken[$outage['id']])) {
                    $this->keepNumber($outage['ticket_number']);
                }
            }

            foreach ($this->rowsToImport($data, $new) as $table => $rows) {
                $columns = array_flip(Schema::getColumnListing($table));
                $hasId = isset($columns['id']);

                foreach ($rows as $row) {
                    $oldId = $row['id'] ?? null;

                    foreach ($data['graph'][$table] ?? [] as $fk) {
                        $value = $row[$fk['column']] ?? null;
                        if ($value === null) {
                            continue;
                        }
                        $row[$fk['column']] = isset($data['rows'][$fk['table']])
                            ? ($map[$fk['table']][$value] ?? $this->existingOutage($data, $fk['table'], $value))
                            : $resolver($fk['table'], $value);
                    }

                    $from = $row['ticket_number'] ?? null;
                    if ($table === 'outages') {
                        $row['ticket_number'] = isset($taken[$oldId]) ? $this->nextNumber($from) : $from;
                    }

                    $row = array_intersect_key($row, $columns);
                    unset($row['id']);

                    if ($hasId) {
                        $map[$table][$oldId] = DB::table($table)->insertGetId($row);
                    } else {
                        DB::table($table)->insert($row);
                    }

                    if ($table === 'outages') {
                        $imported[] = ['from' => $from, 'to' => $row['ticket_number'], 'id' => $map[$table][$oldId]];
                        DB::table('outage_imports')->insert([
                            'outage_id' => $map[$table][$oldId], 'source_server' => mb_substr((string) $data['server'], 0, 120), 'source_id' => $oldId,
                            'source_ticket_number' => $from, 'source_created_at' => Carbon::parse($row['created_at']), 'created_at' => now(), 'updated_at' => now(),
                        ]);
                    }
                }
            }
        });

        foreach ($this->files($this->rowsToImport($data, $new), onDisk: false) as $path) {
            $source = $folder.'/files/'.$path;
            if (is_file($source) && ! Storage::disk('local')->exists($path)) {
                Storage::disk('local')->put($path, file_get_contents($source));
            }
        }

        return $imported;
    }

    // ------------------------------------------------------------------ internals

    /**
     * Every table and its foreign keys: table => list of [column, table].
     *
     * @return array<string, list<array{column: string, table: string}>>
     */
    private function graph(): array
    {
        $graph = [];

        foreach (Schema::getTableListing(schemaQualified: false) as $table) {
            $graph[$table] = collect(Schema::getForeignKeys($table))
                ->filter(fn (array $fk) => count($fk['columns']) === 1)
                ->map(fn (array $fk) => ['column' => $fk['columns'][0], 'table' => $fk['foreign_table']])->values()->all();
        }

        // The tables owned by outages: outages, and whatever hangs off a table already owned.
        $owned = ['outages' => true];
        do {
            $added = false;
            foreach ($graph as $table => $fks) {
                if (isset($owned[$table]) || in_array($table, self::NOT_OWNED, true) || str_starts_with($table, 'ticket')) {
                    continue;
                }
                foreach ($fks as $fk) {
                    if (isset($owned[$fk['table']]) && $fk['table'] !== $table) {
                        $owned[$table] = $added = true;
                        break;
                    }
                }
            }
        } while ($added);

        // Keep the owned tables, with all their foreign keys (to owned and other tables).
        return array_intersect_key($graph, $owned);
    }

    /** Owned tables, parents before children. @return list<string> */
    private function order(array $graph): array
    {
        $order = [];
        $visit = function (string $table, array $path = []) use (&$visit, &$order, $graph): void {
            if (in_array($table, $order, true) || in_array($table, $path, true)) {
                return;
            }
            foreach ($graph[$table] as $fk) {
                if (isset($graph[$fk['table']]) && $fk['table'] !== $table) {
                    $visit($fk['table'], [...$path, $table]);
                }
            }
            $order[] = $table;
        };

        foreach (array_keys($graph) as $table) {
            $visit($table);
        }

        return $order;
    }

    /**
     * How to recognise each row the copied rows point at, in the tables not copied (users by e-mail,
     * OLT / FDT / slot / port by name and parent, the rest by code or name).
     */
    private function references(array $graph, array $rows): array
    {
        $wanted = [];
        foreach ($graph as $table => $fks) {
            foreach ($fks as $fk) {
                if (! isset($graph[$fk['table']])) {
                    foreach ($rows[$table] ?? [] as $row) {
                        if (($row[$fk['column']] ?? null) !== null) {
                            $wanted[$fk['table']][$row[$fk['column']]] = true;
                        }
                    }
                }
            }
        }

        // The network position needs its parents to be recognised: port → slot → FDT → OLT.
        foreach ([['ports', 'slot_id', 'slots'], ['slots', 'fdt_id', 'fdts'], ['fdts', 'olt_id', 'olts']] as [$child, $column, $parent]) {
            if (! empty($wanted[$child])) {
                foreach (DB::table($child)->whereIn('id', array_keys($wanted[$child]))->pluck($column) as $id) {
                    $wanted[$parent][$id] = true;
                }
            }
        }

        $refs = [];
        foreach ($wanted as $table => $ids) {
            $columns = array_values(array_intersect(['id', ...self::KEYS, 'aliases', 'olt_id', 'fdt_id', 'slot_id'], Schema::getColumnListing($table)));
            foreach (array_chunk(array_keys($ids), 1000) as $chunk) {
                foreach (DB::table($table)->whereIn('id', $chunk)->get($columns) as $row) {
                    $refs[$table][$row->id] = (array) $row;
                }
            }
        }

        return $refs;
    }

    /** @return callable(string, mixed): ?int the id here of a row the export points at */
    private function resolver(array $data): callable
    {
        $cache = [];
        $olts = null;

        $resolve = function (string $table, mixed $id) use (&$resolve, &$cache, &$olts, $data): ?int {
            if (array_key_exists($key = $table.'#'.$id, $cache)) {
                return $cache[$key];
            }

            $ref = $data['refs'][$table][$id] ?? null;
            $found = match (true) {
                $ref === null => null,
                $table === 'users' && filled($ref['email'] ?? null) => DB::table('users')->whereRaw('LOWER(email) = ?', [strtolower($ref['email'])])->value('id'),
                $table === 'olts' => ($olts ??= Olt::byMatchKey(Olt::query()->get()))[Olt::matchKey((string) $ref['name'])]?->id,
                $table === 'fdts' => ($olt = $resolve('olts', $ref['olt_id'])) ? $this->byName('fdts', 'olt_id', $olt, $ref['name']) : null,
                $table === 'slots' => ($fdt = $resolve('fdts', $ref['fdt_id'])) ? $this->byName('slots', 'fdt_id', $fdt, $ref['name']) : null,
                $table === 'ports' => ($slot = $resolve('slots', $ref['slot_id'])) ? $this->byName('ports', 'slot_id', $slot, $ref['name']) : null,
                default => $this->byKey($table, $ref),
            };

            return $cache[$key] = $found !== null ? (int) $found : null;
        };

        return $resolve;
    }

    private function byName(string $table, string $parent, int $parentId, mixed $name): ?int
    {
        $id = DB::table($table)->where($parent, $parentId)->whereRaw('LOWER(TRIM(name)) = ?', [strtolower(trim((string) $name))])->value('id');

        return $id !== null ? (int) $id : null;
    }

    /** By every identifying column it has (code, name…) together; when that is not one row, the same id if it is among them. */
    private function byKey(string $table, array $ref): ?int
    {
        $keys = array_filter(array_intersect_key($ref, array_flip(self::KEYS)), fn ($value) => $value !== null && $value !== '');

        if ($keys === []) {
            return DB::table($table)->where('id', $ref['id'])->exists() ? (int) $ref['id'] : null;
        }

        $ids = DB::table($table)->where($keys)->limit(50)->pluck('id')->map(fn ($id) => (int) $id);

        return match (true) {
            $ids->count() === 1 => $ids->first(),
            $ids->contains((int) $ref['id']) => (int) $ref['id'],
            default => null,
        };
    }

    /** An outage the rows point at that was not exported because it is already here (it came with the copy). */
    private function existingOutage(array $data, string $table, mixed $id): ?int
    {
        if ($table !== 'outages') {
            return null;
        }

        $outage = collect($data['rows']['outages'])->firstWhere('id', $id);
        $here = $outage ? DB::table('outages')->where('ticket_number', $outage['ticket_number'])->first(['id', 'created_at']) : null;

        return $here && Carbon::parse($here->created_at)->equalTo(Carbon::parse($outage['created_at'])) ? (int) $here->id : null;
    }

    /**
     * The rows to insert: the new outages and what hangs off them (a row of an outage that is already
     * here is left out), table by table, parents first.
     *
     * @return array<string, list<array<string, mixed>>>
     */
    private function rowsToImport(array $data, array $newOutageIds): array
    {
        $keep = ['outages' => $newOutageIds];
        $out = [];

        foreach ($this->order($data['graph']) as $table) {
            $rows = $table === 'outages'
                ? array_values(array_filter($data['rows']['outages'], fn (array $r) => isset($newOutageIds[$r['id']])))
                : array_values(array_filter($data['rows'][$table] ?? [], function (array $row) use ($data, $table, $keep): bool {
                    $owned = false;
                    foreach ($data['graph'][$table] as $fk) {
                        if (! isset($data['rows'][$fk['table']]) || ($row[$fk['column']] ?? null) === null) {
                            continue;
                        }
                        if (! isset($keep[$fk['table']][$row[$fk['column']]])) {
                            return false; // hangs off something that is not imported
                        }
                        $owned = true;
                    }

                    return $owned;
                }));

            $out[$table] = $rows;
            if ($table !== 'outages') {
                $keep[$table] = array_flip(array_filter(array_column($rows, 'id'), fn ($id) => $id !== null));
            }
        }

        return array_filter($out);
    }

    /** @return list<string> files on the private disk the rows refer to (path columns) — only those present there, when $onDisk */
    private function files(array $rows, bool $onDisk = true): array
    {
        $paths = [];
        foreach ($rows as $table => $list) {
            foreach ($list as $row) {
                foreach ($row as $column => $value) {
                    if (($column === 'path' || str_ends_with($column, '_path')) && is_string($value) && $value !== '' && ! str_contains($value, '..')
                        && (! $onDisk || Storage::disk('local')->exists($value))) {
                        $paths[$value] = true;
                    }
                }
            }
        }

        return array_keys($paths);
    }

    private function nullable(string $table, string $column): bool
    {
        static $columns = [];
        $columns[$table] ??= collect(Schema::getColumns($table))->keyBy('name');

        return (bool) ($columns[$table][$column]['nullable'] ?? true);
    }

    private function describe(array $data, string $table, mixed $id): string
    {
        $ref = $data['refs'][$table][$id] ?? [];

        return $table === 'users' ? (($ref['name'] ?? '?').' <'.($ref['email'] ?? '?').'>') : ($ref['name'] ?? $ref['code'] ?? "#{$id}");
    }

    /** The number is free here: keep it, and move this server's counter past it. */
    private function keepNumber(string $number): string
    {
        if (preg_match('/^(.*)-(\d+)$/', $number, $m)) {
            $prefix = strtoupper($m[1]);
            DB::table('ticket_sequences')->insertOrIgnore(['prefix' => $prefix, 'last_number' => 0, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('ticket_sequences')->where('prefix', $prefix)->where('last_number', '<', (int) $m[2])->update(['last_number' => (int) $m[2], 'updated_at' => now()]);
        }

        return $number;
    }

    /** The number is taken here by another ticket: the next one of the same series nobody uses. */
    private function nextNumber(string $number): string
    {
        if (! preg_match('/^(.*)-(\d+)$/', $number, $m)) {
            return $number.'-B';
        }

        do {
            $next = app(TicketNumberGenerator::class)->next($m[1], strlen($m[2]), keepCase: true);
        } while (DB::table('outages')->where('ticket_number', $next)->exists());

        return $next;
    }

    private function read(string $folder): array
    {
        $file = rtrim($folder, '/\\').'/data.json';

        if (! is_file($file)) {
            throw new RuntimeException("No export found at {$file}.");
        }

        return json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
    }
}
