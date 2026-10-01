<?php

namespace App\Services\Topology;

use App\Models\AuditLog;
use App\Models\Fdt;
use App\Models\Olt;
use App\Models\Port;
use App\Models\Slot;
use App\Models\Vertical;
use App\Support\AuditContext;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use OpenSpout\Reader\CSV\Reader as CsvReader;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;

/**
 * Imports the OLT → FDT → Slot → Port mapping from a spreadsheet (XLSX or CSV) with the columns
 * OLT | FDT | SLOT | PORT (header row, any order, case-insensitive).
 *
 * Idempotent: existing items are matched case-insensitively and reused, only missing ones are
 * created, so the same file can be imported again after changes. Names are tidied (trimmed,
 * repeated spaces collapsed, FDT names upper-cased so "Ntd FDT 1" and "NTD FDT 1" are one FDT).
 * The same FDT name under different OLTs stays separate.
 *
 * Items NOT in the file ($missing):
 *  - keep       (default) leave them as they are;
 *  - deactivate hide them from selection lists;
 *  - remove     delete them — except those still referenced by a ticket (or with children that
 *               are), which are deactivated so ticket history keeps its OLT / FDT / slot / port.
 */
class TopologyImporter
{
    public const COLUMNS = ['olt', 'fdt', 'slot', 'port'];

    public const KEEP = 'keep';

    public const DEACTIVATE = 'deactivate';

    public const REMOVE = 'remove';

    /**
     * @return array{rows: int, created: array<string, int>, reactivated: int, deactivated: array<string, int>, removed: array<string, int>, errors: list<string>, dry_run: bool}
     */
    public function import(string $path, bool $dryRun = false, string $missing = self::KEEP): array
    {
        if (! in_array($missing, [self::KEEP, self::DEACTIVATE, self::REMOVE], true)) {
            throw new InvalidArgumentException("Unknown option for missing items: {$missing}");
        }

        [$rows, $errors] = $this->read($path);

        if ($errors !== []) {
            return $this->report(count($rows) + count($errors), [], 0, [], $errors, $dryRun);
        }

        if ($rows === []) {
            throw new InvalidArgumentException('The file has no data rows.');
        }

        $report = null;

        try {
            DB::transaction(function () use ($rows, $dryRun, $missing, &$report): void {
                $report = AuditContext::as('topology-import', fn () => $this->apply($rows, $missing, $dryRun));

                if ($dryRun) {
                    throw new DryRunRollback;
                }
            });
        } catch (DryRunRollback) {
            // Preview only: everything above was rolled back.
        }

        return $report;
    }

    /**
     * @return array{0: list<array{olt: string, fdt: string, slot: string, port: string, line: int}>, 1: list<string>}
     */
    public function read(string $path): array
    {
        if (! is_file($path)) {
            throw new InvalidArgumentException("File not found: {$path}");
        }

        $extension = Str::lower(pathinfo($path, PATHINFO_EXTENSION));
        $reader = match ($extension) {
            'xlsx' => new XlsxReader,
            'csv', 'txt' => new CsvReader,
            default => throw new InvalidArgumentException('Use an .xlsx or .csv file.'),
        };

        $reader->open($path);
        $rows = [];
        $errors = [];
        $columns = null;

        try {
            foreach ($reader->getSheetIterator() as $sheet) {
                foreach ($sheet->getRowIterator() as $line => $row) {
                    $cells = array_map(fn ($cell) => $this->clean((string) $cell), $row->toArray());

                    if ($columns === null) {
                        $columns = $this->columns($cells);

                        continue;
                    }

                    if (implode('', $cells) === '') {
                        continue; // blank line
                    }

                    $record = ['line' => $line];
                    foreach ($columns as $key => $index) {
                        $record[$key] = $cells[$index] ?? '';
                    }

                    $problem = match (true) {
                        $record['olt'] === '' => 'OLT is empty',
                        $record['fdt'] === '' => 'FDT is empty',
                        ! ctype_digit($record['slot']) => "SLOT '{$record['slot']}' is not a number",
                        ! ctype_digit($record['port']) => "PORT '{$record['port']}' is not a number",
                        default => null,
                    };

                    if ($problem) {
                        $errors[] = "Row {$line}: {$problem}";

                        continue;
                    }

                    $record['fdt'] = Str::upper($record['fdt']);
                    $record['slot'] = (string) (int) $record['slot'];
                    $record['port'] = (string) (int) $record['port'];
                    $rows[] = $record;
                }

                break; // first sheet only
            }
        } finally {
            $reader->close();
        }

        return [$rows, $errors];
    }

    /** @param list<array{olt: string, fdt: string, slot: string, port: string, line: int}> $rows */
    private function apply(array $rows, string $missing, bool $dryRun): array
    {
        $created = ['olts' => 0, 'fdts' => 0, 'slots' => 0, 'ports' => 0];
        $reactivated = 0;
        $key = fn (string ...$parts) => Str::lower(implode('|', $parts));

        // Everything that already exists, matched case-insensitively.
        $olts = Olt::query()->get(['id', 'name', 'is_active'])->keyBy(fn (Olt $o) => $key($o->name));
        $fdts = Fdt::query()->get(['id', 'olt_id', 'name', 'is_active'])->keyBy(fn (Fdt $f) => $key((string) $f->olt_id, $f->name));
        $slots = Slot::query()->get(['id', 'fdt_id', 'name', 'is_active'])->keyBy(fn (Slot $s) => $key((string) $s->fdt_id, $s->name));
        $ports = Port::query()->get(['id', 'slot_id', 'name', 'is_active'])->keyBy(fn (Port $p) => $key((string) $p->slot_id, $p->name));
        $ftth = Vertical::query()->where('code', Vertical::FTTH)->value('id');
        $seen = ['olts' => [], 'fdts' => [], 'slots' => [], 'ports' => []];
        $newPorts = [];
        $now = now();

        $activate = function ($model) use (&$reactivated): void {
            if (! $model->is_active) {
                $model->forceFill(['is_active' => true])->save();
                $reactivated++;
            }
        };

        foreach ($rows as $row) {
            $olt = $olts[$key($row['olt'])] ??= tap(Olt::query()->create(['name' => $row['olt'], 'vertical_id' => $ftth, 'is_active' => true]), function () use (&$created) {
                $created['olts']++;
            });
            $activate($olt);
            $seen['olts'][$olt->id] = true;

            $fdt = $fdts[$key((string) $olt->id, $row['fdt'])] ??= tap(Fdt::query()->create(['olt_id' => $olt->id, 'name' => $row['fdt'], 'is_active' => true]), function () use (&$created) {
                $created['fdts']++;
            });
            $activate($fdt);
            $seen['fdts'][$fdt->id] = true;

            $slot = $slots[$key((string) $fdt->id, $row['slot'])] ??= tap(Slot::query()->create(['fdt_id' => $fdt->id, 'name' => $row['slot'], 'is_active' => true]), function () use (&$created) {
                $created['slots']++;
            });
            $activate($slot);
            $seen['slots'][$slot->id] = true;

            $portKey = $key((string) $slot->id, $row['port']);
            if ($port = $ports[$portKey] ?? null) {
                $activate($port);
                $seen['ports'][$port->id] = true;
            } elseif (! isset($newPorts[$portKey])) {
                // Ports are the bulk of the file: inserted in batches (one summary audit entry below).
                $newPorts[$portKey] = [
                    'slot_id' => $slot->id, 'name' => $row['port'], 'is_active' => true,
                    'created_by' => Auth::id(), 'updated_by' => Auth::id(), 'created_at' => $now, 'updated_at' => $now,
                ];
            }
        }

        foreach (array_chunk(array_values($newPorts), 500) as $chunk) {
            Port::query()->insert($chunk);
        }
        $created['ports'] = count($newPorts);

        $deactivated = ['olts' => 0, 'fdts' => 0, 'slots' => 0, 'ports' => 0];
        $removed = $deactivated;

        if ($missing !== self::KEEP) {
            // Items in the file: seen existing ones + the ports just inserted.
            $keep = array_map(fn (array $ids) => array_keys($ids), $seen);
            $keep['ports'] = array_merge($keep['ports'], Port::query()->whereIn('slot_id', array_unique(array_column($newPorts, 'slot_id')) ?: [0])
                ->where('created_at', $now)->pluck('id')->all());

            // Bottom-up, so a parent is only removed once nothing is left under it.
            foreach ([
                'ports' => [Port::class, 'outage_port', 'port_id', null],
                'slots' => [Slot::class, 'outage_slot', 'slot_id', [Port::class, 'slot_id']],
                'fdts' => [Fdt::class, 'outage_fdt', 'fdt_id', [Slot::class, 'fdt_id']],
                'olts' => [Olt::class, 'outage_olt', 'olt_id', [Fdt::class, 'olt_id']],
            ] as $level => [$model, $pivot, $column, $child]) {
                $stale = $model::query()->whereNotIn('id', $keep[$level] ?: [0])->pluck('id');

                if ($stale->isEmpty()) {
                    continue;
                }

                // Still on a ticket (history must keep it) or still has children → only deactivate.
                $inUse = DB::table($pivot)->whereIn($column, $stale)->distinct()->pluck($column)
                    ->merge($child ? $child[0]::query()->whereIn($child[1], $stale)->distinct()->pluck($child[1]) : [])
                    ->unique();
                $deletable = $missing === self::REMOVE ? $stale->diff($inUse) : collect();

                foreach ($deletable->chunk(1000) as $ids) {
                    $removed[$level] += $model::query()->whereIn('id', $ids->all())->delete();
                }

                $deactivated[$level] = $model::query()->whereIn('id', $stale->diff($deletable)->all() ?: [0])
                    ->where('is_active', true)->update(['is_active' => false, 'updated_at' => $now]);
            }
        }

        $report = $this->report(count($rows), $created, $reactivated, $deactivated, [], $dryRun, $removed);

        if (! $dryRun) {
            AuditLog::query()->create([
                'auditable_type' => (new Olt)->getMorphClass(),
                'auditable_id' => 0,
                'event' => 'topology_imported',
                'new_values' => ['created' => $created, 'reactivated' => $reactivated, 'deactivated' => $deactivated, 'removed' => $removed, 'rows' => count($rows)],
                'user_id' => Auth::id(),
                'trigger' => AuditContext::trigger(),
                'created_at' => $now,
            ]);
        }

        return $report;
    }

    /** @return array<string, int> column key => index */
    private function columns(array $header): array
    {
        $normalised = array_map(fn (string $cell) => Str::lower(preg_replace('/[^a-z]/i', '', $cell)), $header);
        $columns = [];

        foreach (self::COLUMNS as $column) {
            $index = array_search($column, $normalised, true);

            if ($index === false) {
                throw new InvalidArgumentException('The first row must be the header OLT | FDT | SLOT | PORT (missing '.Str::upper($column).').');
            }

            $columns[$column] = $index;
        }

        return $columns;
    }

    private function clean(string $value): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $value));
    }

    private function report(int $rows, array $created, int $reactivated, array $deactivated, array $errors, bool $dryRun, array $removed = []): array
    {
        return [
            'rows' => $rows,
            'created' => $created + ['olts' => 0, 'fdts' => 0, 'slots' => 0, 'ports' => 0],
            'reactivated' => $reactivated,
            'deactivated' => $deactivated + ['olts' => 0, 'fdts' => 0, 'slots' => 0, 'ports' => 0],
            'removed' => $removed + ['olts' => 0, 'fdts' => 0, 'slots' => 0, 'ports' => 0],
            'errors' => $errors,
            'dry_run' => $dryRun,
        ];
    }
}
