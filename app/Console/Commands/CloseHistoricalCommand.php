<?php

namespace App\Console\Commands;

use App\Models\FinalReason;
use App\Models\Outage;
use App\Models\User;
use App\Services\Notifications\NotificationDispatcher;
use App\Services\Outages\OutageWorkflow;
use App\Support\AuditContext;
use App\Support\InMemorySharedStrings;
use App\Support\Settings;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use OpenSpout\Reader\CSV\Reader as CsvReader;
use Throwable;

#[Signature('oms:close-historical
    {file : .xlsx / .csv with a "Ticket No" column (customer tickets and site visits)}
    {--by= : E-mail of the person the closures are recorded under (default: the first Super Admin)}
    {--note=Historical clean-up: closed from the list of old tickets; the customer\'s service is online. : Resolution note on each one}
    {--apply : Close them; without it only the plan is shown}')]
#[Description('Close old customer tickets and site visits from a list, silently (no SMS, e-mail or app notification); visits still get their 72-hour follow-up call')]
class CloseHistoricalCommand extends Command
{
    /** Final reason of these closures: kept out of the closing forms, visible in the reports. */
    public const REASON = 'Historical clean-up (service online)';

    public function handle(OutageWorkflow $workflow, Settings $settings): int
    {
        try {
            $numbers = $this->ticketNumbers((string) $this->argument('file'));
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $found = Outage::query()->whereIn('ticket_number', $numbers)->with('status:id,name')->get()->keyBy('ticket_number');
        $missing = array_values(array_diff($numbers, $found->keys()->all()));
        $notTickets = $found->reject(fn (Outage $outage) => $outage->isTicket());
        $closed = $found->filter(fn (Outage $outage) => $outage->isTicket() && $outage->is_closed);
        $open = $found->filter(fn (Outage $outage) => $outage->isTicket() && ! $outage->is_closed);
        $visits = $open->filter(fn (Outage $outage) => $outage->isSiteVisit());
        $tickets = $open->reject(fn (Outage $outage) => $outage->isSiteVisit());

        $this->table(['In the list', 'Count'], [
            ['Rows (ticket numbers)', count($numbers)],
            ['Site visits to close', $visits->count()],
            ['Customer tickets to close (most close with their visit)', $tickets->count()],
            ['Already closed — left as they are', $closed->count()],
            ['Not a customer ticket / site visit — left out', $notTickets->count()],
            ['Not found', count($missing)],
        ]);

        if ($missing !== []) {
            $this->warn('Not found: '.implode(', ', array_slice($missing, 0, 30)).(count($missing) > 30 ? ' …' : ''));
        }

        if ($notTickets->isNotEmpty()) {
            $this->warn('Left out (not tickets / visits): '.$notTickets->keys()->take(30)->implode(', '));
        }

        $this->line('Silently: no SMS to customers, no e-mail, no app notification. Final reason “'.self::REASON.'”.');
        $hours = $settings->int('ticketing.aftercare_hours');
        $this->line($settings->bool('ticketing.aftercare_enabled') && $hours > 0
            ? "Site visits: the {$hours}-hour follow-up call comes due {$hours} hours after the clean-up (CX Follow-up – Site Visit)."
            : 'The 72-hour follow-up call is switched off (Ticket Settings), so no call will follow.');

        if (! $this->option('apply')) {
            $this->warn('Preview only — nothing changed. Take a database backup, then run again with --apply.');

            return self::SUCCESS;
        }

        $by = $this->option('by')
            ? User::query()->where('email', $this->option('by'))->first()
            : User::role('Super Admin')->orderBy('id')->first();

        if (! $by) {
            $this->error('No user to record the closures under: give --by=<e-mail>.');

            return self::FAILURE;
        }

        $reason = FinalReason::query()->firstOrCreate(['name' => self::REASON], [
            'description' => 'Old tickets and visits closed in bulk (oms:close-historical).', 'applies_to' => null, 'is_active' => false, 'sort_order' => 999,
        ]);
        $note = trim((string) $this->option('note'));
        $done = ['visits' => 0, 'tickets' => 0, 'with_visit' => 0, 'failed' => []];

        AuditContext::as('system:close-historical', function () use ($workflow, $by, $reason, $note, $visits, $tickets, &$done) {
            return NotificationDispatcher::quietly(function () use ($workflow, $by, $reason, $note, $visits, $tickets, &$done): void {
                // Visits first: each one closes the customer ticket it was raised from.
                foreach ($visits as $number => $visit) {
                    try {
                        $workflow->closeHistorical($visit, $by, $reason, $note);
                        $done['visits']++;
                    } catch (Throwable $exception) {
                        $done['failed'][] = "{$number}: ".Str::limit($exception->getMessage(), 150);
                    }
                }

                foreach ($tickets as $number => $ticket) {
                    try {
                        if ($ticket->refresh()->is_closed) {
                            $done['with_visit']++;

                            continue;
                        }

                        $workflow->closeHistorical($ticket, $by, $reason, $note);
                        $done['tickets']++;
                    } catch (Throwable $exception) {
                        $done['failed'][] = "{$number}: ".Str::limit($exception->getMessage(), 150);
                    }
                }
            });
        });

        $this->info("Closed {$done['visits']} site visit(s), {$done['with_visit']} customer ticket(s) with their visit and {$done['tickets']} more customer ticket(s), recorded under {$by->name}.");

        foreach ($done['failed'] as $failure) {
            $this->error('Not closed — '.$failure);
        }

        return $done['failed'] === [] ? self::SUCCESS : self::FAILURE;
    }

    /** @return list<string> the ticket numbers of the "Ticket No" column (or the first column), without repeats */
    private function ticketNumbers(string $path): array
    {
        if (! is_file($path)) {
            throw new \InvalidArgumentException("File not found: {$path}");
        }

        $reader = match (Str::lower(pathinfo($path, PATHINFO_EXTENSION))) {
            'xlsx' => InMemorySharedStrings::reader(),
            'csv', 'txt' => new CsvReader,
            default => throw new \InvalidArgumentException('Use an .xlsx or .csv file.'),
        };

        $reader->open($path);
        $numbers = [];
        $column = null;

        try {
            foreach ($reader->getSheetIterator() as $sheet) {
                foreach ($sheet->getRowIterator() as $row) {
                    $cells = array_map(fn ($cell) => trim((string) (is_object($cell) && method_exists($cell, 'format') ? '' : $cell)), $row->toArray());

                    if ($column === null) {
                        $column = collect($cells)->search(fn (string $cell) => (bool) preg_match('/^ticket\s*(no|number|#)?\.?$/i', $cell));

                        if ($column !== false) {
                            continue;
                        }

                        $column = 0;
                    }

                    // As written on the ticket (WiE-12 is mixed case).
                    $value = $cells[$column] ?? '';

                    if (preg_match('/^[A-Za-z]{2,6}-\d+$/', $value)) {
                        $numbers[] = $value;
                    }
                }

                break;
            }
        } finally {
            $reader->close();
        }

        if ($numbers === []) {
            throw new \InvalidArgumentException('No ticket numbers (like TKT-123, SUP-45) found in the file.');
        }

        return array_values(array_unique($numbers));
    }
}
