<?php

namespace App\Services\Reports;

use App\Enums\SlaState;
use App\Models\EmailTemplate;
use App\Models\Outage;
use App\Models\Priority;
use App\Models\Vertical;
use App\Services\Tickets\FourGTicketReportData;
use App\Services\Tickets\TicketReportData;
use App\Services\Tickets\TicketTables;
use Carbon\CarbonImmutable;

/**
 * The live page for management (Customer Care dashboard → Live link for management): FTTH outages, 4G outages,
 * customer tickets & site visits and 4G tickets, as they are now and since midnight. The same figures as the
 * e-mails; the ticket parts are summaries only — never a customer's name or account.
 */
class ExecutiveLiveSummary
{
    public function __construct(
        private readonly OperationalSnapshot $snapshot,
        private readonly TicketReportData $tickets,
        private readonly FourGTicketReportData $fourGTickets,
    ) {}

    /** @return array<string, mixed> values for resources/views/live/tickets.blade.php */
    public function build(?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $today = $now->startOfDay();

        // Customer tickets: "Closed in the last N hours" is the period of the e-mail of the same name.
        $hours = max(1, (int) (EmailTemplate::query()->where('report_type', 'ticket_pending')->orderByDesc('is_active')->value('period_hours') ?: 3));
        // Customer tickets and site visits each on their own, in the same layout — FTTH: 4G tickets have their own section.
        $ftth = array_filter([Vertical::idFor(Vertical::FTTH)]);
        $part = fn (string $kind) => array_intersect_key($this->tickets->build('ticket_pending', $now, $hours, ['kind' => $kind, 'vertical_ids' => $ftth]), array_flip([
            'tk_sla_clock', 'tk_period_hours', 'tk_closed', 'tk_closed_sla_pct', 'tk_closed_today', 'tk_headline_table',
            'tk_sla_per_department_table', 'tk_sla_per_olt_table', 'tk_issue_per_olt_table', 'tk_sla_per_region_table',
        ]));

        $mobility = $this->snapshot->mobility($today, $now);
        $fourG = $this->fourGTickets->build(FourGTicketReportData::TODAY, $now);

        return [
            'report_time' => $now->format('H:i'),
            'report_date' => $now->format('d F Y'),
            'built_at' => $now->format('H:i:s'),
            'ftth' => $this->ftthOutages($today, $now),
            'mobility_tiles' => TicketTables::tiles([
                ['Active', $mobility['g4_active'], $mobility['g4_critical'].' critical', $mobility['g4_critical'] > 0 ? 'bad' : null],
                ['Sites down', $mobility['g4_sites_down'], $mobility['g4_cells_down'].' cells', $mobility['g4_sites_down'] > 0 ? 'warn' : null],
                ['With vendors', $mobility['g4_with_vendor'], null, $mobility['g4_with_vendor'] > 0 ? 'warn' : null],
                ['SLA at risk', $mobility['g4_sla_at_risk'], null, $mobility['g4_sla_at_risk'] > 0 ? 'warn' : null],
                ['SLA breached', $mobility['g4_sla_breached'], null, $mobility['g4_sla_breached'] > 0 ? 'bad' : null],
                ['New today', $mobility['g4_new']],
                ['Restored today', $mobility['g4_closed'], 'MTTR '.$mobility['g4_mttr'], 'ok'],
            ]),
            'mobility_table' => $mobility['g4_active_table'],
            'fourg_tiles' => $fourG['t4_headline_table'],
            'fourg_pending' => $fourG['t4_pending_table'],
            'ticket_parts' => [
                'tickets' => ['title' => 'Customer tickets (FTTH)'] + $part('ticket'),
                'visits' => ['title' => 'Site visits'] + $part('visit'),
            ],
        ];
    }

    /** @return array{tiles: string, table: string} FTTH, core and system outages open now, and since midnight */
    private function ftthOutages(CarbonImmutable $today, CarbonImmutable $now): array
    {
        $active = Outage::query()->standard()->active()->with(OutageQuery::RELATIONS)->orderByDesc('customers_affected')->orderBy('start_time')->get();
        $critical = $active->filter(fn (Outage $outage) => $outage->priority?->code === Priority::CRITICAL)->count();
        $state = fn (SlaState $s) => $active->where('sla_state', $s)->count();

        return [
            'tiles' => TicketTables::tiles([
                ['Active', $active->count(), $critical.' critical', $critical > 0 ? 'bad' : null],
                ['Customers affected', number_format($active->sum('customers_affected'))],
                ['Within SLA', $state(SlaState::Compliant), null, 'ok'],
                ['At risk', $state(SlaState::AtRisk), null, $state(SlaState::AtRisk) > 0 ? 'warn' : null],
                ['Breached', $state(SlaState::Breached), null, $state(SlaState::Breached) > 0 ? 'bad' : null],
                ['New today', Outage::query()->standard()->whereBetween('created_at', [$today, $now])->count()],
                ['Restored today', Outage::query()->standard()->where('is_closed', true)->whereBetween('resolved_at', [$today, $now])->count(), null, 'ok'],
            ]),
            'table' => $this->snapshot->table([
                'ticket' => 'Ticket', 'title' => 'Title', 'location' => 'Location / OLT', 'priority' => 'Priority', 'customers' => 'Clients',
                'start_time' => 'Start', 'status' => 'Status', 'sla_status' => 'SLA', 'owner' => 'With',
            ], $active->take(50)->map(fn (Outage $outage) => $this->snapshot->item($outage))->all()),
        ];
    }
}
