<?php

namespace App\Console\Commands;

use App\Models\Outage;
use App\Services\Outages\OutageWorkflow;
use App\Support\AuditContext;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Throwable;

#[Signature('oms:fix-visit-types {--apply : Correct them; without it only the list is shown}')]
#[Description('Open site visits booked with another kind than their issue’s (Inhouse Shifting booked as Shifting…): take the issue’s kind and go to the right desk / technicians')]
class FixVisitTypesCommand extends Command
{
    public function handle(OutageWorkflow $workflow): int
    {
        $visits = Outage::query()->siteVisits()->where('is_closed', false)
            ->whereHas('ticket', fn (Builder $ticket) => $ticket->whereHas('subcategory', fn (Builder $issue) => $issue->whereNotNull('visit_type_id')
                ->whereColumn('ticket_subcategories.visit_type_id', '!=', 'tickets.visit_type_id')))
            ->with(['ticket.subcategory.visitType', 'ticket.visitType', 'currentAssignee:id,name'])
            ->orderBy('id')->get();

        if ($visits->isEmpty()) {
            $this->info('Every open site visit has the kind of visit of its issue: nothing to correct.');

            return self::SUCCESS;
        }

        $this->table(['Visit', 'Issue', 'Booked as', 'Should be', 'Desk', 'With'], $visits->map(fn (Outage $visit) => [
            $visit->ticket_number, $visit->ticket->subcategory?->name, $visit->ticket->visitType?->name, $visit->ticket->subcategory?->visitType?->name,
            $visit->current_queue?->getLabel() ?? '—', $visit->currentAssignee?->name ?? '—',
        ])->all());

        if (! $this->option('apply')) {
            $this->warn('Preview only — nothing changed. Run again with --apply. (A visit keeps its number, e.g. SHI-12.)');

            return self::SUCCESS;
        }

        $failed = 0;

        foreach ($visits as $visit) {
            try {
                $done = AuditContext::as('system:fix-visit-types', fn () => $workflow->correctVisitType($visit, null));
                $this->line("{$visit->ticket_number}: {$done}");
            } catch (Throwable $exception) {
                $failed++;
                $this->error("{$visit->ticket_number}: not corrected — {$exception->getMessage()}");
            }
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
