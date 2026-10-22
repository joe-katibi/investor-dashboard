<?php

namespace App\Services\Customers;

use App\Models\Customer;
use App\Models\Olt;
use App\Models\OnuSyncRun;
use App\Models\Vertical;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

/**
 * Keeps the customer database up to date from the ONU exports of the network management system
 * (config oms.onu_exports, oms:sync-onu-export). A dump is CS-Reports_HH_MM_SS_YYYYMMDD_HHMMSS_pN.csv, one
 * row per ONU, every cell written as ="value":
 *
 *   NE Name (the OLT) | Slot Address | Card Port No. | ONU Name (the account) | Alias Name (the customer)
 *   ONU Type | ONU Physical Address | ONU Creation Time
 *
 * For every account found in the dump, the customer's OLT, slot, port, ONU type and physical address are compared
 * with it; only what differs is written, each change kept in customer_onu_changes. The OLT is found by its name or
 * an alias ("Kabowa-OLT" → KABOWA FH, whose name stays); OLTs are never created, an unknown name is reported. An
 * OMS slot and port hang on an FDT, which the dump does not give: they follow the dump when the customer has an FDT
 * on that OLT; a customer moved to another OLT loses the FDT of the old one. Accounts not yet on file are added.
 *
 * Guards: the newest complete dump only, each dump once; a dump much smaller than the last one, or one that would
 * move many customers to another OLT, is refused (--force to accept it); nothing is ever deleted.
 */
class OnuExportSync
{
    /** column key => header in the dump */
    public const COLUMNS = [
        'olt' => 'NE Name', 'slot' => 'Slot Address', 'port' => 'Card Port No.', 'account' => 'ONU Name', 'name' => 'Alias Name',
        'onu_type' => 'ONU Type', 'onu_physical_address' => 'ONU Physical Address', 'onu_created_at' => 'ONU Creation Time',
    ];

    /** CS-Reports_12_00_00_20261021_120000_p1.csv: the date and time of the dump, and the part. */
    public const FILE_PATTERN = '/^CS-Reports_\d{2}_\d{2}_\d{2}_(\d{8})_(\d{6})_p(\d+)\.csv$/i';

    /** A dump written less than this long ago may still be being written: it waits for the next run. */
    public const SETTLE_SECONDS = 120;

    /** A dump with fewer rows than this share of the last one read is refused (a cut-off file). */
    public const MIN_ROWS_SHARE = 0.8;

    /** More customers than this (and than this share of those matched) moving to another OLT is refused. */
    public const MAX_OLT_MOVES = 200;

    public const MAX_OLT_MOVES_SHARE = 0.02;

    private const LIMITS = ['name' => 190, 'onu_type' => 60, 'onu_physical_address' => 60, 'olt' => 120, 'slot' => 20, 'port' => 20];

    /**
     * The newest complete dump in a folder: all its parts (p1, p2…), oldest part first.
     *
     * @return array{files: list<string>, dumped_at: CarbonImmutable, name: string}|null
     */
    public function latestDump(string $directory): ?array
    {
        // Nothing received yet.
        if (! is_dir($directory)) {
            return null;
        }

        if (! is_readable($directory)) {
            throw new InvalidArgumentException("The ONU export folder cannot be read: {$directory}");
        }

        $dumps = [];

        foreach (scandir($directory) ?: [] as $name) {
            $path = $directory.DIRECTORY_SEPARATOR.$name;

            if (! preg_match(self::FILE_PATTERN, $name, $m) || ! is_file($path) || filemtime($path) > time() - self::SETTLE_SECONDS) {
                continue;
            }

            $dumps[$m[1].$m[2]][(int) $m[3]] = $path;
        }

        if ($dumps === []) {
            return null;
        }

        krsort($dumps);
        $stamp = (string) array_key_first($dumps);
        $parts = $dumps[$stamp];
        ksort($parts);

        return [
            'files' => array_values($parts),
            'dumped_at' => CarbonImmutable::createFromFormat('YmdHis', $stamp),
            'name' => basename((string) reset($parts)),
        ];
    }

    /**
     * Read a dump and bring the customers up to date. Without $apply nothing is written: the report says what would change.
     *
     * @param  list<string>  $files  the parts of one dump
     * @return array<string, mixed>
     */
    public function sync(string $source, array $files, ?CarbonImmutable $dumpedAt, bool $apply, bool $force = false): array
    {
        $config = config("oms.onu_exports.{$source}") ?? throw new InvalidArgumentException("Unknown ONU export source: {$source}");
        $name = basename($files[0] ?? '');

        if ($apply && ! $force && $dumpedAt && OnuSyncRun::query()->where('source', $source)->where('dumped_at', $dumpedAt)->where('status', OnuSyncRun::DONE)->exists()) {
            return ['status' => 'skipped', 'message' => "The dump {$name} was already read."];
        }

        $lock = Cache::lock("oms:onu-export-sync:{$source}", 3600);

        if (! $lock->get()) {
            return ['status' => 'skipped', 'message' => 'Another sync of this source is running.'];
        }

        $run = $apply ? OnuSyncRun::query()->create([
            'source' => $source, 'file' => Str::limit($name.(count($files) > 1 ? ' (+'.(count($files) - 1).' parts)' : ''), 250),
            'dumped_at' => $dumpedAt, 'status' => OnuSyncRun::RUNNING, 'started_at' => now(),
        ]) : null;

        try {
            $this->makeRoom();
            $read = $this->read($files);
            $report = $this->compare($read, (array) ($config['only_olts'] ?? []), $run, $apply, $force, $source);

            $run?->update([
                'status' => $report['status'] === 'refused' ? OnuSyncRun::FAILED : OnuSyncRun::DONE,
                'rows' => $report['rows'], 'customers_matched' => $report['matched'], 'customers_created' => $report['created'],
                'customers_updated' => $report['updated'], 'customers_unchanged' => $report['unchanged'], 'changes' => array_sum($report['changes']),
                'summary' => array_diff_key($report, array_flip(['samples', 'status', 'message'])),
                'error' => $report['status'] === 'refused' ? $report['message'] : null, 'finished_at' => now(),
            ]);

            return $report + ['run_id' => $run?->id, 'file' => $name];
        } catch (Throwable $exception) {
            $run?->update(['status' => OnuSyncRun::FAILED, 'error' => Str::limit($exception->getMessage(), 2000), 'finished_at' => now()]);

            throw $exception;
        } finally {
            $lock->release();
        }
    }

    /** The moment of a dump from its file name; null when the name does not follow the pattern. */
    public static function stamp(string $name): ?CarbonImmutable
    {
        return preg_match(self::FILE_PATTERN, $name, $m) ? CarbonImmutable::createFromFormat('YmdHis', $m[1].$m[2]) : null;
    }

    /** Received dumps older than $days are removed from the folder (the customers keep their history). */
    public function prune(string $directory, int $days): int
    {
        $removed = 0;

        foreach (is_dir($directory) ? (scandir($directory) ?: []) : [] as $name) {
            $path = $directory.DIRECTORY_SEPARATOR.$name;

            if (preg_match(self::FILE_PATTERN, $name) && is_file($path) && filemtime($path) < time() - max(1, $days) * 86400 && @unlink($path)) {
                $removed++;
            }
        }

        return $removed;
    }

    /** The account number in an ONU Name: the whole cell (SF2659301), or one inside words ("SF2659301 John"). */
    public static function account(string $onuName): ?string
    {
        $plain = Str::upper(trim($onuName));
        $compact = (string) preg_replace('/\s+/', '', $plain);

        if (preg_match('/^[A-Z]{2,4}\d{4,12}$/', $compact)) {
            return $compact;
        }

        return preg_match('/(?<![A-Z0-9])(SF[A-Z]?\d{5,12})(?!\d)/', $plain, $m) ? $m[1] : null;
    }

    /** ="Nsambya-OLT" → Nsambya-OLT */
    public static function cell(mixed $value): string
    {
        $value = trim((string) $value, " \t\r\n\0\x0B\xEF\xBB\xBF");

        if (str_starts_with($value, '="') && str_ends_with($value, '"') && strlen($value) >= 3) {
            $value = substr($value, 2, -1);
        } elseif (str_starts_with($value, '=')) {
            $value = substr($value, 1);
        }

        return trim((string) preg_replace('/\s+/u', ' ', $value));
    }

    /** @return array{rows: array<string, array<string, string>>, total: int, no_account: int, duplicates: int} one row per account */
    private function read(array $files): array
    {
        $rows = [];
        $total = $noAccount = $duplicates = 0;

        foreach ($files as $file) {
            $handle = is_file($file) ? fopen($file, 'rb') : false;

            if (! $handle) {
                throw new InvalidArgumentException("The ONU export cannot be opened: {$file}");
            }

            try {
                $index = $this->columns(array_map(self::cell(...), fgetcsv($handle, null, ',', '"', '') ?: []), basename($file));

                while (($cells = fgetcsv($handle, null, ',', '"', '')) !== false) {
                    if ($cells === [null]) {
                        continue;
                    }

                    $total++;
                    $account = self::account(self::cell($cells[$index['account']] ?? ''));

                    if ($account === null) {
                        $noAccount++;

                        continue;
                    }

                    $row = [];
                    foreach ($index as $key => $i) {
                        $row[$key] = self::cell($cells[$i] ?? '');
                    }

                    foreach (self::LIMITS as $key => $limit) {
                        $row[$key] = rtrim(mb_substr($row[$key], 0, $limit));
                    }

                    $row['slot'] = ctype_digit($row['slot']) ? (string) (int) $row['slot'] : $row['slot'];
                    $row['port'] = ctype_digit($row['port']) ? (string) (int) $row['port'] : $row['port'];
                    $row['onu_physical_address'] = Str::upper($row['onu_physical_address']);
                    $row['onu_created_at'] = $this->moment($row['onu_created_at']);

                    // An account on two ONUs: the one created last.
                    if (isset($rows[$account])) {
                        $duplicates++;

                        if ($row['onu_created_at'] <= $rows[$account]['onu_created_at']) {
                            continue;
                        }
                    }

                    $rows[$account] = $row;
                }
            } finally {
                fclose($handle);
            }
        }

        return ['rows' => $rows, 'total' => $total, 'no_account' => $noAccount, 'duplicates' => $duplicates];
    }

    /** @return array<string, mixed> */
    private function compare(array $read, array $onlyOlts, ?OnuSyncRun $run, bool $apply, bool $force, string $source): array
    {
        $now = now();
        $olts = Olt::query()->get(['id', 'name', 'aliases', 'region_id']);
        $byKey = Olt::byMatchKey($olts);
        $oltNames = $olts->pluck('name', 'id')->all();
        $oltRegions = $olts->pluck('region_id', 'id')->all();
        $only = [];

        foreach ($onlyOlts as $oltName) {
            $only[] = ($byKey[Olt::matchKey($oltName)] ?? throw new InvalidArgumentException("OLT “{$oltName}” of the source's OLT list is not in OMS."))->id;
        }

        $regions = DB::table('regions')->pluck('name', 'id')->all();
        $fdtNames = DB::table('fdts')->pluck('name', 'id')->all();
        $slotNames = DB::table('slots')->pluck('name', 'id')->all();
        $portNames = DB::table('ports')->pluck('name', 'id')->all();
        $slotIds = DB::table('slots')->get(['id', 'fdt_id', 'name'])->mapWithKeys(fn ($s) => [$s->fdt_id.'|'.Str::lower($s->name) => (int) $s->id])->all();
        $portIds = DB::table('ports')->get(['id', 'slot_id', 'name'])->mapWithKeys(fn ($p) => [$p->slot_id.'|'.Str::lower($p->name) => (int) $p->id])->all();
        $customers = DB::table('customers')->get([
            'id', 'account_number', 'name', 'region_id', 'olt_id', 'fdt_id', 'slot_id', 'port_id',
            'onu_type', 'onu_physical_address', 'onu_created_at', 'onu_olt_name', 'onu_slot', 'onu_port',
        ])->keyBy('account_number');

        $unknownOlts = [];
        $outside = $matched = $unchanged = $oltMoves = $newSlots = $newPorts = $filled = 0;
        $updates = $creates = $history = $samples = [];
        $changes = array_fill_keys(['olt', 'fdt', 'region', 'slot', 'port', 'onu_type', 'onu_physical_address', 'onu_created_at', 'name'], 0);
        $log = function (int|string $customer, string $account, string $field, mixed $old, mixed $new) use (&$history, &$changes, &$samples, $now): void {
            $changes[$field] = ($changes[$field] ?? 0) + 1;
            $history[] = ['customer_id' => $customer, 'account_number' => $account, 'field' => $field,
                'old_value' => $old === null || $old === '' ? null : Str::limit((string) $old, 250), 'new_value' => $new === null || $new === '' ? null : Str::limit((string) $new, 250), 'created_at' => $now];

            if (count($samples) < 20) {
                $samples[] = "{$account}: ".str_replace('_', ' ', $field).' '.($old === null || $old === '' ? '—' : $old).' → '.($new === null || $new === '' ? '—' : $new);
            }
        };

        foreach ($read['rows'] as $account => $row) {
            $olt = $row['olt'] !== '' ? ($byKey[Olt::matchKey($row['olt'])] ?? null) : null;

            if ($row['olt'] !== '' && ! $olt) {
                $unknownOlts[$row['olt']] = ($unknownOlts[$row['olt']] ?? 0) + 1;
            }

            // A source limited to some OLTs never touches customers of others.
            if ($only !== [] && (! $olt || ! in_array($olt->id, $only, true))) {
                $outside++;

                continue;
            }

            $current = $customers[$account] ?? null;

            if (! $current) {
                $creates[$account] = [
                    'account_number' => $account, 'name' => $row['name'] !== '' ? $row['name'] : null,
                    'olt_id' => $olt?->id, 'region_id' => $olt ? ($oltRegions[$olt->id] ?? null) : null, 'fdt_id' => null, 'slot_id' => null, 'port_id' => null,
                    'onu_type' => $row['onu_type'] ?: null, 'onu_physical_address' => $row['onu_physical_address'] ?: null, 'onu_created_at' => $row['onu_created_at'] ?: null,
                    'onu_olt_name' => $row['olt'] ?: null, 'onu_slot' => $row['slot'] ?: null, 'onu_port' => $row['port'] ?: null,
                    'source' => Customer::SOURCE_ONU_EXPORT, 'verified_at' => $now, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
                ];

                continue;
            }

            $matched++;
            $set = [];
            $id = (int) $current->id;
            $fdtId = $current->fdt_id ? (int) $current->fdt_id : null;

            // The OLT: the FDT, slot and port of the old one do not exist on the new one.
            if ($olt && (int) $current->olt_id !== $olt->id) {
                $oltMoves++;
                $log($id, $account, 'olt', $oltNames[$current->olt_id] ?? null, $olt->name);
                $set += ['olt_id' => $olt->id, 'fdt_id' => null, 'slot_id' => null, 'port_id' => null];

                if ($fdtId) {
                    $log($id, $account, 'fdt', $fdtNames[$fdtId] ?? null, null);
                }

                $region = $oltRegions[$olt->id] ?? null;

                if ($region && (int) $current->region_id !== (int) $region) {
                    $set['region_id'] = $region;
                    $log($id, $account, 'region', $regions[$current->region_id] ?? null, $regions[$region] ?? null);
                }

                $fdtId = null;
            }

            // Slot and port as OMS has them (on the FDT), else as the last dump gave them.
            $slotNow = (string) ($current->slot_id ? ($slotNames[$current->slot_id] ?? '') : ($current->onu_slot ?? ''));
            $portNow = (string) ($current->port_id ? ($portNames[$current->port_id] ?? '') : ($current->onu_port ?? ''));
            $slotChanged = $row['slot'] !== '' && $slotNow !== $row['slot'];
            $portChanged = $row['port'] !== '' && $portNow !== $row['port'];

            if ($slotChanged) {
                $log($id, $account, 'slot', $slotNow, $row['slot']);
            }

            if ($portChanged) {
                $log($id, $account, 'port', $portNow, $row['port']);
            }

            // On the FDT the customer hangs on: the slot and port of the dump (made when that FDT does not have them yet).
            if ($fdtId && $row['slot'] !== '' && ($slotChanged || $portChanged || ! $current->slot_id || ($row['port'] !== '' && ! $current->port_id))) {
                $slotKey = $fdtId.'|'.Str::lower($row['slot']);

                if (! isset($slotIds[$slotKey])) {
                    $newSlots++;
                    $slotIds[$slotKey] = $apply ? (int) DB::table('slots')->insertGetId(['fdt_id' => $fdtId, 'name' => $row['slot'], 'is_active' => true, 'created_at' => $now, 'updated_at' => $now]) : -$newSlots;
                }

                $slotId = $slotIds[$slotKey];
                $portId = null;

                if ($row['port'] !== '') {
                    $portKey = $slotId.'|'.Str::lower($row['port']);

                    if (! isset($portIds[$portKey])) {
                        $newPorts++;
                        $portIds[$portKey] = $apply ? (int) DB::table('ports')->insertGetId(['slot_id' => $slotId, 'name' => $row['port'], 'is_active' => true, 'created_at' => $now, 'updated_at' => $now]) : -$newPorts;
                    }

                    $portId = $portIds[$portKey];
                }

                if ($slotId !== (int) $current->slot_id) {
                    $set['slot_id'] = $slotId;
                }

                if ($portId !== null && $portId !== (int) $current->port_id) {
                    $set['port_id'] = $portId;
                }
            }

            // As the dump gives them, for the next comparison.
            foreach (['onu_olt_name' => $row['olt'], 'onu_slot' => $row['slot'], 'onu_port' => $row['port']] as $column => $value) {
                if ($value !== '' && (string) $current->{$column} !== $value) {
                    $set[$column] = $value;
                }
            }

            // The ONU itself: a first value is filled in quietly, a different one is a change.
            foreach (['onu_type', 'onu_physical_address', 'onu_created_at'] as $column) {
                $old = $column === 'onu_created_at' && $current->{$column} ? CarbonImmutable::parse($current->{$column})->format('Y-m-d H:i:s') : (string) $current->{$column};

                if ($row[$column] !== '' && $old !== $row[$column]) {
                    $set[$column] = $row[$column];

                    if ($old === '') {
                        $filled++;
                    } else {
                        $log($id, $account, $column, $old, $row[$column]);
                    }
                }
            }

            // The name is the customer care team's: only filled in when there is none.
            if ($row['name'] !== '' && blank($current->name)) {
                $set['name'] = $row['name'];
                $log($id, $account, 'name', null, $row['name']);
            }

            if ($set === []) {
                $unchanged++;
            } else {
                $updates[$id] = $set;
            }
        }

        $report = [
            'status' => $apply ? 'done' : 'preview', 'message' => '', 'source' => $source,
            'rows' => $read['total'], 'accounts' => count($read['rows']), 'no_account' => $read['no_account'], 'duplicates' => $read['duplicates'],
            'unknown_olts' => $unknownOlts, 'outside_olts' => $outside, 'matched' => $matched, 'created' => count($creates), 'updated' => count($updates),
            'unchanged' => $unchanged, 'filled' => $filled, 'olt_moves' => $oltMoves, 'new_slots' => $newSlots, 'new_ports' => $newPorts,
            'changes' => array_filter($changes), 'samples' => $samples,
        ];

        // Guards: a cut-off dump, or a mapping gone wrong, must not rewrite the customer database.
        $last = OnuSyncRun::query()->where('source', $source)->where('status', OnuSyncRun::DONE)->when($run, fn ($q) => $q->whereKeyNot($run->id))->latest('id')->value('rows');
        $refusal = match (true) {
            $read['total'] === 0 => 'The dump has no rows.',
            ! $force && $last && $read['total'] < $last * self::MIN_ROWS_SHARE => "The dump has {$read['total']} rows, far fewer than the {$last} of the last one: it may be cut off.",
            ! $force && $oltMoves > max(self::MAX_OLT_MOVES, $matched * self::MAX_OLT_MOVES_SHARE) => "{$oltMoves} customers would move to another OLT — more than expected. Check the OLT names and aliases.",
            default => null,
        };

        if ($refusal) {
            return ['status' => 'refused', 'message' => $refusal.($force ? '' : ' Nothing was changed; run with --force to accept this dump.')] + $report;
        }

        if ($apply) {
            $this->write($updates, $creates, $history, $run, $now);
        }

        return $report;
    }

    private function write(array $updates, array $creates, array $history, ?OnuSyncRun $run, mixed $now): void
    {
        foreach (array_chunk($updates, 500, true) as $chunk) {
            DB::transaction(function () use ($chunk, $now): void {
                foreach ($chunk as $id => $set) {
                    DB::table('customers')->where('id', $id)->update($set + ['updated_at' => $now]);
                }
            });
        }

        $ftth = Vertical::idFor(Vertical::FTTH);

        foreach (array_chunk($creates, 500, true) as $chunk) {
            DB::transaction(function () use ($chunk, $ftth, $run, $now): void {
                DB::table('customers')->insert(array_map(fn (array $row) => $row + ($ftth ? ['vertical_id' => $ftth] : []), array_values($chunk)));
                $ids = DB::table('customers')->whereIn('account_number', array_keys($chunk))->pluck('id', 'account_number');
                DB::table('customer_onu_changes')->insert(array_map(fn (string $account) => [
                    'onu_sync_run_id' => $run?->id, 'customer_id' => $ids[$account], 'account_number' => $account, 'field' => 'created',
                    'old_value' => null, 'new_value' => $chunk[$account]['onu_olt_name'] ?? null, 'created_at' => $now,
                ], array_keys($chunk)));
            });
        }

        foreach (array_chunk($history, 1000) as $chunk) {
            DB::table('customer_onu_changes')->insert(array_map(fn (array $row) => $row + ['onu_sync_run_id' => $run?->id], $chunk));
        }
    }

    /** @return array<string, int> column key => index */
    private function columns(array $header, string $file): array
    {
        $plain = fn (string $text) => Str::lower((string) preg_replace('/[^a-z0-9]/i', '', $text));
        $normalised = array_map($plain, $header);
        $index = [];

        foreach (self::COLUMNS as $key => $title) {
            $found = array_search($plain($title), $normalised, true);

            if ($found === false) {
                throw new InvalidArgumentException("{$file}: the column “{$title}” is missing — not an ONU export?");
            }

            $index[$key] = $found;
        }

        return $index;
    }

    /** "2026-10-21 9:05:03" → "2026-10-21 09:05:03"; anything else → "" */
    private function moment(string $value): string
    {
        if ($value === '') {
            return '';
        }

        try {
            return CarbonImmutable::parse($value)->format('Y-m-d H:i:s');
        } catch (Throwable) {
            return '';
        }
    }

    private function makeRoom(): void
    {
        $limit = ini_parse_quantity((string) ini_get('memory_limit'));

        if ($limit > 0 && $limit < 1024 * 1024 * 1024) {
            @ini_set('memory_limit', '1024M');
        }

        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }
    }
}
