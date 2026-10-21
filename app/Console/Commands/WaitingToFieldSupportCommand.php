<?php

namespace App\Console\Commands;

use App\Enums\StatusCode;
use App\Models\Outage;
use App\Models\OutageStatus;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Notifications\NotificationDispatcher;
use App\Services\Outages\OutageWorkflow;
use App\Services\Tickets\BusinessClock;
use App\Support\AuditContext;
use App\Support\InMemorySharedStrings;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use OpenSpout\Reader\CSV\Reader as CsvReader;
use Throwable;

#[Signature('oms:waiting-to-field-support
    {--hours=10 : SLA hours open (as "SLA hrs Open" on the Pending / SLA export) above which a ticket is moved}
    {--file= : Only the tickets of this .xlsx / .csv ("Ticket No" column); without it every waiting ticket}
    {--by= : E-mail of the person the moves are recorded under (default: the first Super Admin)}
    {--note=Waiting for the site visit for over :hours SLA hours: take it up with the customer. : Note on each move}
    {--apply : Move them; without it only the plan is shown}')]
#[Description('Customer tickets waiting for their site visit (Unassigned, "Site Visit Raised") past N SLA hours go to CX Field Support; they still close with the visit')]
class WaitingToFieldSupportCommand extends Command
{
    public function handle(OutageWorkflow $workflow, BusinessClock $clock): int
    {
        $hours = (float) $this->option('hours');

        try {
            $numbers = $this->option('file') ? $this->ticketNumbers((string) $this->option('file')) : null;
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $now = CarbonImmutable::now();
        $waiting = Outage::query()
            ->where('is_closed', false)
            ->where('status_id', OutageStatus::query()->where('code', StatusCode::TicketSiteVisit->value)->value('id'))
            ->whereHas('ticket', fn ($query) => $query->whereNull('desk'))
            ->when($numbers !== null, fn ($query) => $query->whereIn('ticket_number', $numbers))
            ->orderBy('start_time')
            ->get()
            ->filter(fn (Outage $outage) => $outage->isCxTicket());

        $open = fn (Outage $outage) => round($clock->minutesBetween(CarbonImmutable::parse($outage->start_time), $now) / 60, 1);
        $due = $waiting->filter(fn (Outage $outage) => $open($outage) > $hours);

        // Where each one's site visit is now: a visit already closed (or none) is worth a look before moving.
        $visits = Ticket::query()->whereIn('parent_outage_id', $due->pluck('id'))->with('outage.status')->get()->groupBy('parent_outage_id');
        $visitState = function (Outage $outage) use ($visits): string {
            $visit = $visits->get($outage->id)?->sortByDesc('id')->first()?->outage;

            return match (true) {
                ! $visit => 'no site visit found',
                $visit->is_closed => 'visit closed',
                default => 'visit at '.($visit->current_queue?->getLabel() ?? 'no desk'),
            };
        };
        $states = $due->map($visitState);

        $this->table(['Customer tickets waiting for the site visit', 'Count'], array_merge([
            [$numbers !== null ? 'In the file, still waiting' : 'Waiting now', $waiting->count()],
            ["Over {$hours} SLA hours — to CX Field Support", $due->count()],
            ["{$hours} SLA hours or less — left waiting", $waiting->count() - $due->count()],
        ], $states->countBy()->sortDesc()->map(fn (int $count, string $state) => ["   of which: {$state}", $count])->values()->all()));

        if ($numbers !== null && ($gone = count($numbers) - $waiting->count()) > 0) {
            $this->line("{$gone} ticket(s) of the file are no longer waiting (closed, or moved since): left as they are.");
        }

        if ($this->getOutput()->isVerbose()) {
            $this->table(['Ticket', 'SLA hrs open', 'Its site visit'], $due->map(fn (Outage $outage) => [$outage->ticket_number, $open($outage), $states[$outage->getKey()]])->values()->all());
        }

        $this->line('They join the CX Field Support queue (FIFO) and still close when their site visit is completed. No SMS or e-mail is sent.');

        if (! $this->option('apply')) {
            $this->warn('Preview only — nothing changed. Add -v to list the tickets; run again with --apply to move them.');

            return self::SUCCESS;
        }

        $by = $this->option('by')
            ? User::query()->where('email', $this->option('by'))->first()
            : User::role('Super Admin')->orderBy('id')->first();

        if (! $by) {
            $this->error('No user to record the moves under: give --by=<e-mail>.');

            return self::FAILURE;
        }

        $note = str_replace(':hours', (string) $hours, trim((string) $this->option('note')));
        $done = ['moved' => 0, 'skipped' => 0, 'failed' => []];

        AuditContext::as('system:waiting-to-field-support', function () use ($workflow, $by, $note, $due, &$done) {
            return NotificationDispatcher::quietly(function () use ($workflow, $by, $note, $due, &$done): void {
                foreach ($due as $outage) {
                    try {
                        $workflow->sendWaitingToFieldSupport($outage, $by, $note) ? $done['moved']++ : $done['skipped']++;
                    } catch (Throwable $exception) {
                        $done['failed'][] = "{$outage->ticket_number}: ".Str::limit($exception->getMessage(), 150);
                    }
                }
            });
        });

        $this->info("Moved {$done['moved']} customer ticket(s) to CX Field Support, recorded under {$by->name}."
            .($done['skipped'] ? " {$done['skipped']} were no longer waiting." : ''));

        foreach ($done['failed'] as $failure) {
            $this->error('Not moved — '.$failure);
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
            throw new \InvalidArgumentException('No ticket numbers (like TKT-123) found in the file.');
        }

        return array_values(array_unique($numbers));
    }
}
