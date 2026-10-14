<?php

namespace App\Services\Tickets;

use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\Fdt;
use App\Models\Olt;
use App\Models\Port;
use App\Models\Region;
use App\Models\Slot;
use App\Models\Ticket;
use App\Models\Vertical;
use App\Services\Topology\DryRunRollback;
use App\Support\AuditContext;
use App\Support\InMemorySharedStrings;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use OpenSpout\Reader\CSV\Reader as CsvReader;

/**
 * Imports the customer file (XLSX or CSV, header row, columns in any order):
 *
 *   Alias Name (customer name) | ONU Name (account number) | OLT | FDT | Slot | Port | Bandwidth
 *   optional: Phone | Alt Phone | Alt Phone 2 | Address | Landmark | Region | Email
 *
 * Customers are matched on the account number: existing ones are updated (also the ones an agent
 * typed in by hand while raising a ticket), new ones created. The topology is reused — an OLT,
 * FDT, slot or port is only created when it does not exist yet. Tickets raised for an account
 * before it was on file are linked to the customer and get its OLT / FDT / slot / port.
 * The same file can be imported again after changes; nothing is ever removed.
 */
class CustomerImporter
{
    /** column key => accepted header spellings (letters and digits only, lower case) */
    public const HEADERS = [
        'account' => ['onuname', 'accountnumber', 'account', 'accountno', 'acc', 'accno'],
        'name' => ['aliasname', 'customername', 'name', 'customer', 'alias'],
        'olt' => ['olt', 'oltname'],
        'fdt' => ['fdt', 'fdtname'],
        'slot' => ['slot'],
        'port' => ['port', 'plot', 'pon', 'ponport'],
        'bandwidth' => ['bandwidth', 'package', 'speed', 'plan'],
        'phone' => ['phone', 'primaryphone', 'primarynumber', 'contact', 'mobile', 'telephone', 'tel', 'phonenumber'],
        'alt_phone_1' => ['altphone', 'altphone1', 'alternativecontact', 'alternativephone', 'alternativenumber', 'secondaryphone'],
        'alt_phone_2' => ['altphone2', 'alternativecontact2', 'alternativephone2', 'alternativenumber2'],
        'address' => ['address', 'location', 'customeraddress'],
        'landmark' => ['landmark'],
        'region' => ['region', 'area', 'zone'],
        'email' => ['email', 'emailaddress'],
    ];

    /** Only this many problem rows are listed; all of them are counted ("error_rows"). */
    public const ERRORS_LISTED = 200;

    /**
     * What each descriptive column can hold. A longer value (notes typed into the name, as a rule)
     * is cut to fit and counted ("shortened") rather than stopping a whole customer list.
     */
    public const LIMITS = [
        'name' => 190, 'phone' => 40, 'alt_phone_1' => 40, 'alt_phone_2' => 40,
        'email' => 255, 'address' => 255, 'landmark' => 255, 'bandwidth' => 60,
    ];

    /**
     * @param  bool  $keepIncomplete  a row whose network position is incomplete (a slot without an FDT, a port
     *                                without a slot, an FDT without an OLT) is imported with the part that is
     *                                complete instead of stopping the import; "incomplete" counts those rows
     * @return array{rows: int, created: int, updated: int, unchanged: int, topology: array<string, int>, regions: int, tickets_linked: int, incomplete: int, shortened: int, errors: list<string>, error_rows: int, dry_run: bool}
     */
    public function import(string $path, bool $dryRun = false, bool $keepIncomplete = false): array
    {
        $this->makeRoom();
        [$rows, $errors, $errorRows, $incomplete, $shortened] = $this->read($path, $keepIncomplete);

        if ($errors !== []) {
            return $this->report(count($rows) + $errorRows, errors: $errors, dryRun: $dryRun, errorRows: $errorRows, incomplete: $incomplete, shortened: $shortened);
        }

        if ($rows === []) {
            throw new InvalidArgumentException('The file has no data rows.');
        }

        $report = null;

        try {
            DB::transaction(function () use ($rows, $dryRun, $incomplete, $shortened, &$report): void {
                $report = AuditContext::as('customer-import', fn () => $this->apply($rows, $dryRun, $incomplete, $shortened));

                if ($dryRun) {
                    throw new DryRunRollback;
                }
            });
        } catch (DryRunRollback) {
            // Preview only: everything above was rolled back.
        }

        return $report;
    }

    /** @return array{0: list<array<string, mixed>>, 1: list<string>, 2: int, 3: int, 4: int} rows, problems listed, rows with a problem, rows with an incomplete position kept, values cut to fit */
    public function read(string $path, bool $keepIncomplete = false): array
    {
        if (! is_file($path)) {
            throw new InvalidArgumentException("File not found: {$path}");
        }

        $reader = match (Str::lower(pathinfo($path, PATHINFO_EXTENSION))) {
            'xlsx' => InMemorySharedStrings::reader(),
            'csv', 'txt' => new CsvReader,
            default => throw new InvalidArgumentException('Use an .xlsx or .csv file.'),
        };

        $reader->open($path);
        $rows = [];
        $errors = [];
        $errorRows = 0;
        $incomplete = 0;
        $shortened = 0;
        $columns = null;
        $seen = [];

        try {
            foreach ($reader->getSheetIterator() as $sheet) {
                foreach ($sheet->getRowIterator() as $line => $row) {
                    $cells = array_map(fn ($cell) => $this->clean($cell), $row->toArray());

                    if ($columns === null) {
                        $columns = $this->columns($cells);

                        continue;
                    }

                    if (implode('', $cells) === '') {
                        continue;
                    }

                    $record = ['line' => $line];
                    foreach ($columns as $key => $index) {
                        $record[$key] = $cells[$index] ?? '';
                    }

                    $record['account'] = Customer::normaliseAccount($record['account']);

                    // A position is OLT › FDT › slot › port: each level hangs on the one before it.
                    $gap = match (true) {
                        ($record['fdt'] ?? '') !== '' && ($record['olt'] ?? '') === '' => 'FDT given without an OLT',
                        ($record['slot'] ?? '') !== '' && ($record['fdt'] ?? '') === '' => 'SLOT given without an FDT',
                        ($record['port'] ?? '') !== '' && ($record['slot'] ?? '') === '' => 'PORT given without a SLOT',
                        default => null,
                    };

                    if ($gap && $keepIncomplete) {
                        // Keep the customer and the part of the position that is complete.
                        $record['fdt'] = ($record['olt'] ?? '') === '' ? '' : ($record['fdt'] ?? '');
                        $record['slot'] = $record['fdt'] === '' ? '' : ($record['slot'] ?? '');
                        $record['port'] = $record['slot'] === '' ? '' : ($record['port'] ?? '');
                        $incomplete++;
                        $gap = null;
                    }

                    $slot = $record['slot'] ?? '';
                    $port = $record['port'] ?? '';

                    $problem = match (true) {
                        $record['account'] === '' => 'the account number (ONU Name) is empty',
                        mb_strlen($record['account']) > 60 => 'the account number is longer than 60 characters',
                        isset($seen[$record['account']]) => "account {$record['account']} is also on row {$seen[$record['account']]}",
                        mb_strlen($record['olt'] ?? '') > 120 => 'the OLT name is longer than 120 characters',
                        mb_strlen($record['fdt'] ?? '') > 120 => 'the FDT name is longer than 120 characters',
                        $slot !== '' && ! ctype_digit($slot) => "SLOT '{$slot}' is not a number",
                        $port !== '' && ! ctype_digit($port) => "PORT '{$port}' is not a number",
                        default => $gap,
                    };

                    if ($problem) {
                        $errorRows++;

                        if (count($errors) < self::ERRORS_LISTED) {
                            $errors[] = "Row {$line}: {$problem}";
                        }

                        continue;
                    }

                    foreach (self::LIMITS as $key => $limit) {
                        if (mb_strlen($record[$key] ?? '') > $limit) {
                            $record[$key] = rtrim(mb_substr($record[$key], 0, $limit));
                            $shortened++;
                        }
                    }

                    $seen[$record['account']] = $line;
                    $record['fdt'] = Str::upper($record['fdt'] ?? '');
                    $record['slot'] = $slot === '' ? '' : (string) (int) $slot;
                    $record['port'] = $port === '' ? '' : (string) (int) $port;
                    $rows[] = $record;
                }

                break; // first sheet only
            }
        } finally {
            $reader->close();
        }

        return [$rows, $errors, $errorRows, $incomplete, $shortened];
    }

    /** @param list<array<string, mixed>> $rows */
    private function apply(array $rows, bool $dryRun, int $incomplete = 0, int $shortened = 0): array
    {
        $key = fn (string ...$parts) => Str::lower(implode('|', $parts));
        $now = now();
        $userId = Auth::id();
        $created = ['olts' => 0, 'fdts' => 0, 'slots' => 0, 'ports' => 0];
        $newRegions = 0;

        // Existing topology and regions, matched without case.
        // An OLT is found by its name or any alias, compared loosely: "Seguku-OLT" in the file is SEGUKU.
        $olts = Olt::byMatchKey(Olt::query()->get(['id', 'name', 'aliases', 'region_id']));
        $fdts = Fdt::query()->get(['id', 'olt_id', 'name'])->keyBy(fn (Fdt $f) => $key((string) $f->olt_id, $f->name));
        $slots = Slot::query()->get(['id', 'fdt_id', 'name'])->keyBy(fn (Slot $s) => $key((string) $s->fdt_id, $s->name));
        $ports = Port::query()->toBase()->get(['id', 'slot_id', 'name'])->mapWithKeys(fn ($p) => [$key((string) $p->slot_id, (string) $p->name) => (int) $p->id])->all();
        $regions = Region::query()->get(['id', 'name'])->keyBy(fn (Region $r) => $key($r->name));
        $ftth = Vertical::query()->where('code', Vertical::FTTH)->value('id');

        $existing = Customer::query()->toBase()
            ->get(['id', 'account_number', 'name', 'primary_phone', 'alt_phone_1', 'alt_phone_2', 'email', 'address', 'landmark', 'region_id', 'olt_id', 'fdt_id', 'slot_id', 'port_id', 'bandwidth', 'source', 'verified_at'])
            ->keyBy('account_number');

        $inserts = [];
        $inserted = 0;
        $updated = 0;
        $unchanged = 0;
        // New customers are written 500 at a time as the file is walked, so a large file does not
        // have to be held in memory twice.
        $flush = function () use (&$inserts, &$inserted): void {
            if ($inserts !== []) {
                DB::table('customers')->insert($inserts);
                $inserted += count($inserts);
                $inserts = [];
            }
        };

        foreach ($rows as $row) {
            $oltId = $fdtId = $slotId = $portId = $regionId = null;

            if (($row['region'] ?? '') !== '') {
                $region = $regions[$key($row['region'])] ??= tap(Region::query()->create(['name' => $row['region'], 'is_active' => true]), function () use (&$newRegions) {
                    $newRegions++;
                });
                $regionId = $region->id;
            }

            if (($row['olt'] ?? '') !== '') {
                $olt = $olts[Olt::matchKey($row['olt'])] ??= tap(Olt::query()->create(['name' => $row['olt'], 'vertical_id' => $ftth, 'region_id' => $regionId, 'is_active' => true]), function () use (&$created) {
                    $created['olts']++;
                });
                $oltId = $olt->id;
                $regionId ??= $olt->region_id;

                if ($row['fdt'] !== '') {
                    $fdt = $fdts[$key((string) $oltId, $row['fdt'])] ??= tap(Fdt::query()->create(['olt_id' => $oltId, 'name' => $row['fdt'], 'is_active' => true]), function () use (&$created) {
                        $created['fdts']++;
                    });
                    $fdtId = $fdt->id;

                    if ($row['slot'] !== '') {
                        $slot = $slots[$key((string) $fdtId, $row['slot'])] ??= tap(Slot::query()->create(['fdt_id' => $fdtId, 'name' => $row['slot'], 'is_active' => true]), function () use (&$created) {
                            $created['slots']++;
                        });
                        $slotId = $slot->id;

                        if ($row['port'] !== '') {
                            $portKey = $key((string) $slotId, $row['port']);

                            if (! isset($ports[$portKey])) {
                                // Query builder: one summary audit entry below instead of one per port.
                                $ports[$portKey] = (int) DB::table('ports')->insertGetId([
                                    'slot_id' => $slotId, 'name' => $row['port'], 'is_active' => true,
                                    'created_by' => $userId, 'updated_by' => $userId, 'created_at' => $now, 'updated_at' => $now,
                                ]);
                                $created['ports']++;
                            }

                            $portId = $ports[$portKey];
                        }
                    }
                }
            }

            $values = array_filter([
                'name' => $row['name'] ?? '',
                'primary_phone' => $row['phone'] ?? '',
                'alt_phone_1' => $row['alt_phone_1'] ?? '',
                'alt_phone_2' => $row['alt_phone_2'] ?? '',
                'email' => $row['email'] ?? '',
                'address' => $row['address'] ?? '',
                'landmark' => $row['landmark'] ?? '',
                'bandwidth' => $row['bandwidth'] ?? '',
            ], fn ($value) => $value !== '');

            // The file is the truth for where the account hangs on the network (when it says so).
            if ($oltId) {
                $values += ['olt_id' => $oltId, 'fdt_id' => $fdtId, 'slot_id' => $slotId, 'port_id' => $portId];
            }

            if ($regionId) {
                $values['region_id'] = $regionId;
            }

            $current = $existing[$row['account']] ?? null;

            if (! $current) {
                $inserts[] = array_merge([
                    'name' => null, 'primary_phone' => null, 'alt_phone_1' => null, 'alt_phone_2' => null, 'email' => null, 'address' => null,
                    'landmark' => null, 'bandwidth' => null, 'olt_id' => null, 'fdt_id' => null, 'slot_id' => null, 'port_id' => null, 'region_id' => null,
                ], $values, [
                    'account_number' => $row['account'], 'source' => Customer::SOURCE_IMPORT, 'verified_at' => $now, 'is_active' => true,
                    'created_by' => $userId, 'updated_by' => $userId, 'created_at' => $now, 'updated_at' => $now,
                ]);

                if (count($inserts) >= 500) {
                    $flush();
                }

                continue;
            }

            $changes = [];
            foreach ($values as $column => $value) {
                if ((string) ($current->{$column} ?? '') !== (string) ($value ?? '')) {
                    $changes[$column] = $value;
                }
            }

            if ($current->source !== Customer::SOURCE_IMPORT || $current->verified_at === null) {
                $changes += ['source' => Customer::SOURCE_IMPORT, 'verified_at' => $now];
            }

            if ($changes === []) {
                $unchanged++;

                continue;
            }

            DB::table('customers')->where('id', $current->id)->update($changes + ['updated_by' => $userId, 'updated_at' => $now]);
            $updated++;
        }

        $flush();

        $linked = $this->linkTickets(array_column($rows, 'account'));

        $report = $this->report(count($rows), $inserted, $updated, $unchanged, $created, $newRegions, $linked, [], $dryRun, incomplete: $incomplete, shortened: $shortened);

        if (! $dryRun) {
            AuditLog::query()->create([
                'auditable_type' => (new Customer)->getMorphClass(),
                'auditable_id' => 0,
                'event' => 'customers_imported',
                'new_values' => array_diff_key($report, ['errors' => 1, 'error_rows' => 1, 'dry_run' => 1]),
                'user_id' => $userId,
                'trigger' => AuditContext::trigger(),
                'created_at' => $now,
            ]);
        }

        return $report;
    }

    /**
     * Tickets raised for these accounts: link the customer record, and give open ones (and any
     * without a position yet) the customer's OLT / FDT / slot / port and region.
     *
     * @param  list<string>  $accounts
     */
    private function linkTickets(array $accounts): int
    {
        $linked = 0;

        foreach (array_chunk($accounts, 500) as $chunk) {
            $customers = Customer::query()->toBase()->whereIn('account_number', $chunk)
                ->get(['id', 'account_number', 'name', 'region_id', 'olt_id', 'fdt_id', 'slot_id', 'port_id', 'bandwidth'])->keyBy('account_number');

            $tickets = Ticket::query()->toBase()->whereIn('account_number', $chunk)
                ->where(fn ($q) => $q->whereNull('customer_id')->orWhereNull('olt_id'))
                ->get(['id', 'account_number', 'customer_id', 'customer_name', 'olt_id', 'region_id', 'bandwidth']);

            foreach ($tickets as $ticket) {
                $customer = $customers[$ticket->account_number] ?? null;

                if (! $customer) {
                    continue;
                }

                $changes = array_filter([
                    'customer_id' => $ticket->customer_id ? null : $customer->id,
                    'customer_name' => filled($ticket->customer_name) ? null : $customer->name,
                    'region_id' => $ticket->region_id ? null : $customer->region_id,
                    'bandwidth' => filled($ticket->bandwidth) ? null : $customer->bandwidth,
                ], fn ($value) => $value !== null);

                if (! $ticket->olt_id && $customer->olt_id) {
                    $changes += ['olt_id' => $customer->olt_id, 'fdt_id' => $customer->fdt_id, 'slot_id' => $customer->slot_id, 'port_id' => $customer->port_id];
                }

                if ($changes !== []) {
                    DB::table('tickets')->where('id', $ticket->id)->update($changes + ['updated_at' => now()]);
                    $linked++;
                }
            }
        }

        return $linked;
    }

    /** @return array<string, int> column key => index */
    private function columns(array $header): array
    {
        $normalised = array_map(fn (string $cell) => Str::lower((string) preg_replace('/[^a-z0-9]/i', '', $cell)), $header);
        $columns = [];

        foreach (self::HEADERS as $column => $spellings) {
            foreach ($spellings as $spelling) {
                $index = array_search($spelling, $normalised, true);

                if ($index !== false) {
                    $columns[$column] = $index;

                    break;
                }
            }
        }

        if (! isset($columns['account'])) {
            throw new InvalidArgumentException('The first row must be the header. The account number column (“ONU Name” or “Account Number”) is missing.');
        }

        return $columns;
    }

    private function clean(mixed $value): string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d H:i');
        }

        // Phone numbers typed as numbers arrive as floats: 752026684.0 → "752026684".
        if (is_float($value) && floor($value) === $value && abs($value) < 1e15) {
            $value = (string) (int) $value;
        }

        return trim((string) preg_replace('/\s+/u', ' ', is_scalar($value) ? (string) $value : ''));
    }

    private function report(int $rows, int $created = 0, int $updated = 0, int $unchanged = 0, array $topology = [], int $regions = 0, int $linked = 0, array $errors = [], bool $dryRun = false, int $errorRows = 0, int $incomplete = 0, int $shortened = 0): array
    {
        return [
            'rows' => $rows,
            'created' => $created,
            'updated' => $updated,
            'unchanged' => $unchanged,
            'topology' => $topology + ['olts' => 0, 'fdts' => 0, 'slots' => 0, 'ports' => 0],
            'regions' => $regions,
            'tickets_linked' => $linked,
            'incomplete' => $incomplete,
            'shortened' => $shortened,
            'errors' => $errors,
            'error_rows' => max($errorRows, count($errors)),
            'dry_run' => $dryRun,
        ];
    }

    /**
     * A customer list of a hundred thousand rows needs a few hundred megabytes while it is checked
     * and written, and more than the usual half minute. Neither limit is ever lowered.
     */
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
