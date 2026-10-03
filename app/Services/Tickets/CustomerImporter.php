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
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use OpenSpout\Reader\CSV\Reader as CsvReader;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;

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

    /**
     * @return array{rows: int, created: int, updated: int, unchanged: int, topology: array<string, int>, regions: int, tickets_linked: int, errors: list<string>, dry_run: bool}
     */
    public function import(string $path, bool $dryRun = false): array
    {
        [$rows, $errors] = $this->read($path);

        if ($errors !== []) {
            return $this->report(count($rows) + count($errors), errors: $errors, dryRun: $dryRun);
        }

        if ($rows === []) {
            throw new InvalidArgumentException('The file has no data rows.');
        }

        $report = null;

        try {
            DB::transaction(function () use ($rows, $dryRun, &$report): void {
                $report = AuditContext::as('customer-import', fn () => $this->apply($rows, $dryRun));

                if ($dryRun) {
                    throw new DryRunRollback;
                }
            });
        } catch (DryRunRollback) {
            // Preview only: everything above was rolled back.
        }

        return $report;
    }

    /** @return array{0: list<array<string, mixed>>, 1: list<string>} */
    public function read(string $path): array
    {
        if (! is_file($path)) {
            throw new InvalidArgumentException("File not found: {$path}");
        }

        $reader = match (Str::lower(pathinfo($path, PATHINFO_EXTENSION))) {
            'xlsx' => new XlsxReader,
            'csv', 'txt' => new CsvReader,
            default => throw new InvalidArgumentException('Use an .xlsx or .csv file.'),
        };

        $reader->open($path);
        $rows = [];
        $errors = [];
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
                    $slot = $record['slot'] ?? '';
                    $port = $record['port'] ?? '';

                    $problem = match (true) {
                        $record['account'] === '' => 'the account number (ONU Name) is empty',
                        mb_strlen($record['account']) > 60 => 'the account number is longer than 60 characters',
                        isset($seen[$record['account']]) => "account {$record['account']} is also on row {$seen[$record['account']]}",
                        $slot !== '' && ! ctype_digit($slot) => "SLOT '{$slot}' is not a number",
                        $port !== '' && ! ctype_digit($port) => "PORT '{$port}' is not a number",
                        ($record['fdt'] ?? '') !== '' && ($record['olt'] ?? '') === '' => 'FDT given without an OLT',
                        $slot !== '' && ($record['fdt'] ?? '') === '' => 'SLOT given without an FDT',
                        $port !== '' && $slot === '' => 'PORT given without a SLOT',
                        default => null,
                    };

                    if ($problem) {
                        if (count($errors) < 200) {
                            $errors[] = "Row {$line}: {$problem}";
                        }

                        continue;
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

        return [$rows, $errors];
    }

    /** @param list<array<string, mixed>> $rows */
    private function apply(array $rows, bool $dryRun): array
    {
        $key = fn (string ...$parts) => Str::lower(implode('|', $parts));
        $now = now();
        $userId = Auth::id();
        $created = ['olts' => 0, 'fdts' => 0, 'slots' => 0, 'ports' => 0];
        $newRegions = 0;

        // Existing topology and regions, matched without case.
        $olts = Olt::query()->get(['id', 'name', 'region_id'])->keyBy(fn (Olt $o) => $key($o->name));
        $fdts = Fdt::query()->get(['id', 'olt_id', 'name'])->keyBy(fn (Fdt $f) => $key((string) $f->olt_id, $f->name));
        $slots = Slot::query()->get(['id', 'fdt_id', 'name'])->keyBy(fn (Slot $s) => $key((string) $s->fdt_id, $s->name));
        $ports = Port::query()->toBase()->get(['id', 'slot_id', 'name'])->mapWithKeys(fn ($p) => [$key((string) $p->slot_id, (string) $p->name) => (int) $p->id])->all();
        $regions = Region::query()->get(['id', 'name'])->keyBy(fn (Region $r) => $key($r->name));
        $ftth = Vertical::query()->where('code', Vertical::FTTH)->value('id');

        $existing = Customer::query()->toBase()
            ->get(['id', 'account_number', 'name', 'primary_phone', 'alt_phone_1', 'alt_phone_2', 'email', 'address', 'landmark', 'region_id', 'olt_id', 'fdt_id', 'slot_id', 'port_id', 'bandwidth', 'source', 'verified_at'])
            ->keyBy('account_number');

        $inserts = [];
        $updated = 0;
        $unchanged = 0;

        foreach ($rows as $row) {
            $oltId = $fdtId = $slotId = $portId = $regionId = null;

            if (($row['region'] ?? '') !== '') {
                $region = $regions[$key($row['region'])] ??= tap(Region::query()->create(['name' => $row['region'], 'is_active' => true]), function () use (&$newRegions) {
                    $newRegions++;
                });
                $regionId = $region->id;
            }

            if (($row['olt'] ?? '') !== '') {
                $olt = $olts[$key($row['olt'])] ??= tap(Olt::query()->create(['name' => $row['olt'], 'vertical_id' => $ftth, 'region_id' => $regionId, 'is_active' => true]), function () use (&$created) {
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

        foreach (array_chunk($inserts, 500) as $chunk) {
            DB::table('customers')->insert($chunk);
        }

        $linked = $this->linkTickets(array_column($rows, 'account'));

        $report = $this->report(count($rows), count($inserts), $updated, $unchanged, $created, $newRegions, $linked, [], $dryRun);

        if (! $dryRun) {
            AuditLog::query()->create([
                'auditable_type' => (new Customer)->getMorphClass(),
                'auditable_id' => 0,
                'event' => 'customers_imported',
                'new_values' => array_diff_key($report, ['errors' => 1, 'dry_run' => 1]),
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

    private function report(int $rows, int $created = 0, int $updated = 0, int $unchanged = 0, array $topology = [], int $regions = 0, int $linked = 0, array $errors = [], bool $dryRun = false): array
    {
        return [
            'rows' => $rows,
            'created' => $created,
            'updated' => $updated,
            'unchanged' => $unchanged,
            'topology' => $topology + ['olts' => 0, 'fdts' => 0, 'slots' => 0, 'ports' => 0],
            'regions' => $regions,
            'tickets_linked' => $linked,
            'errors' => $errors,
            'dry_run' => $dryRun,
        ];
    }
}
