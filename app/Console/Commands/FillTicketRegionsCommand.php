<?php

namespace App\Console\Commands;

use App\Models\Customer;
use App\Models\Olt;
use App\Models\Ticket;
use App\Support\AuditContext;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

#[Signature('oms:fill-ticket-regions
    {--apply : Make the changes; without it only the plan is shown}')]
#[Description('Give tickets (and customers) without a region the region of their OLT — for regions set on the OLTs after the tickets were raised')]
class FillTicketRegionsCommand extends Command
{
    public function handle(): int
    {
        $tickets = $this->missing(Ticket::query());
        $customers = $this->missing(Customer::query());

        if ($tickets->isEmpty() && $customers->isEmpty()) {
            $this->info('Every ticket and customer on an OLT with a region already has a region: nothing to fill.');
            $this->leftOver();

            return self::SUCCESS;
        }

        $olts = Olt::query()->with('region:id,name')->whereIn('id', $tickets->keys()->merge($customers->keys())->unique())
            ->orderBy('name')->get(['id', 'name', 'region_id']);

        $this->table(['OLT', 'Region it gets', 'Tickets', 'Customers'], $olts->map(fn (Olt $olt): array => [
            $olt->name, $olt->region?->name, (int) ($tickets[$olt->id] ?? 0), (int) ($customers[$olt->id] ?? 0),
        ])->all());
        $this->line("Total: {$tickets->sum()} ticket(s), {$customers->sum()} customer(s). Tickets that already have a region keep it.");
        $this->leftOver();

        if (! $this->option('apply')) {
            $this->warn('Preview only — nothing changed. Take a database backup, then run again with --apply.');

            return self::SUCCESS;
        }

        [$filledTickets, $filledCustomers] = AuditContext::as('system:fill-ticket-regions', fn () => DB::transaction(fn (): array => [
            $this->fill(Ticket::query(), 'tickets'),
            $this->fill(Customer::query(), 'customers'),
        ]));

        $this->info("{$filledTickets} ticket(s) and {$filledCustomers} customer(s) now have their OLT's region.");

        return self::SUCCESS;
    }

    /**
     * Rows without a region on an OLT that has one, counted per OLT.
     *
     * @return Collection<int, int>
     */
    private function missing(Builder $query): Collection
    {
        return $this->fillable($query)->toBase()->selectRaw('olt_id, count(*) as total')->groupBy('olt_id')->pluck('total', 'olt_id');
    }

    private function fillable(Builder $query): Builder
    {
        return $query->whereNull('region_id')->whereNotNull('olt_id')
            ->whereHas('olt', fn (Builder $olt) => $olt->whereNotNull('region_id'));
    }

    private function fill(Builder $query, string $table): int
    {
        return $this->fillable($query)->update([
            'region_id' => DB::raw("(select olts.region_id from olts where olts.id = {$table}.olt_id)"),
        ]);
    }

    /** What this cannot fill: tickets with no OLT, and OLTs that have no region themselves. */
    private function leftOver(): void
    {
        $noOlt = Ticket::query()->whereNull('region_id')->whereNull('olt_id')->count();
        $oltsWithout = Ticket::query()->whereNull('tickets.region_id')
            ->join('olts', 'olts.id', '=', 'tickets.olt_id')->whereNull('olts.region_id')
            ->toBase()->selectRaw('olts.name, count(*) as total')->groupBy('olts.name')->orderByDesc('total')->pluck('total', 'name');

        if ($noOlt > 0) {
            $this->line("Left without a region: {$noOlt} ticket(s) with no OLT.");
        }

        if ($oltsWithout->isNotEmpty()) {
            $this->line("Left without a region: {$oltsWithout->sum()} ticket(s) on OLTs that have no region yet — set it on the OLT (Topology → OLTs) and run this again:");
            $this->table(['OLT without a region', 'Tickets'], $oltsWithout->map(fn ($total, $name) => [$name, $total])->values()->all());
        }
    }
}
