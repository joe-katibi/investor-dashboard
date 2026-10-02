<?php

namespace App\Services\Reports;

use App\Enums\SlaState;
use App\Models\MajorOutageType;
use App\Models\Outage;
use App\Models\OutageType;
use App\Models\Priority;
use App\Models\Vertical;
use App\Services\Mobility\MobilityMetrics;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Point-in-time operational picture used by the two-hour update, executive summary and
 * summary report emails (spec §14–§17). Keys map 1:1 to template placeholders.
 */
class OperationalSnapshot
{
    public function __construct(private readonly ReportRegistry $reports) {}

    public function build(CarbonInterface $periodStart, ?CarbonInterface $now = null): array
    {
        $now ??= now();

        $active = Outage::query()->active()->with(OutageQuery::RELATIONS)->orderByDesc('customers_affected')->orderBy('start_time')->get();

        $byMajor = fn (string $code) => $active->filter(fn (Outage $o) => $o->majorOutageType?->code === $code)->values();
        $network = $byMajor(MajorOutageType::NETWORK);
        $core = $byMajor(MajorOutageType::CORE);
        $system = $byMajor(MajorOutageType::SYSTEM);
        $mobility = $active->filter(fn (Outage $o) => $o->vertical?->code === Vertical::MOBILITY_4G)->values();
        $ftth = $network->filter(fn (Outage $o) => $o->vertical?->code === Vertical::FTTH)->values();

        $critical = $active->filter(fn (Outage $o) => $o->priority?->code === Priority::CRITICAL);
        $major = $active->filter(fn (Outage $o) => in_array($o->priority?->code, [Priority::CRITICAL, Priority::HIGH], true))->values();
        $inPeriod = fn (Builder $q) => $q->where('created_at', '>=', $periodStart)->where('created_at', '<=', $now);

        $closedInPeriod = Outage::query()->with(OutageQuery::RELATIONS)
            ->where('is_closed', true)
            ->where('resolved_at', '>=', $periodStart)
            ->where('resolved_at', '<=', $now)
            ->orderByDesc('resolved_at')
            ->limit(100)
            ->get();

        $oldest = $active->sortBy('start_time')->first();

        return [
            'report_time' => $now->format('H:i'),
            'report_date' => $now->format('d F Y'),
            'period_start' => $periodStart->format('Y-m-d H:i'),
            'period_end' => $now->format('Y-m-d H:i'),

            // Executive snapshot
            'active_outages' => $active->count(),
            'new_outages' => $inPeriod(Outage::query())->count(),
            'resolved_outages' => $closedInPeriod->count(),
            'critical_outages' => $critical->count(),
            'customers_affected' => number_format($active->sum('customers_affected')),
            'sla_compliant' => $active->where('sla_state', SlaState::Compliant)->count(),
            'sla_at_risk' => $active->where('sla_state', SlaState::AtRisk)->count(),
            'sla_breached' => $active->where('sla_state', SlaState::Breached)->count(),
            'sms_sent' => $active->where('sms_sent', true)->count(),
            'sms_pending' => $active->where('sms_sent', false)->count(),
            'credits_shared' => $active->where('credit_shared', true)->count(),
            'credits_pending' => $active->where('credit_shared', false)->count(),
            'oldest_active' => $oldest ? "{$oldest->ticket_number} ({$oldest->elapsedForHumans()})" : 'None',

            // 4G mobility
            'sites_down' => $mobility->sum('sites_down'),
            'cells_down' => $mobility->sum('cells_down'),
            'power_faults' => $mobility->sum('power_faults'),
            'other_4g' => $mobility->isEmpty() ? 'None' : $mobility->map(fn (Outage $o) => "{$o->ticket_number} {$o->title}")->implode('; '),

            // Lists for {{#each}} loops
            'network_outages' => $network->map(fn (Outage $o) => $this->item($o))->all(),
            'ftth_outages' => $ftth->map(fn (Outage $o) => $this->item($o))->all(),
            'mobility_outages' => $mobility->map(fn (Outage $o) => $this->item($o))->all(),
            'core_outages' => $core->map(fn (Outage $o) => $this->item($o))->all(),
            'system_outages' => $system->map(fn (Outage $o) => $this->item($o))->all(),
            'major_incidents' => $major->map(fn (Outage $o) => $this->item($o))->all(),
            'closed_outages' => $closedInPeriod->map(fn (Outage $o) => $this->item($o))->all(),

            'core_summary_or_no_core_outages' => $this->summaryText($core, 'No core outages.'),
            'system_summary_or_no_system_outages' => $this->summaryText($system, 'No system outages.'),
            'major_incidents_text' => $this->summaryText($major, 'No critical or high incidents requiring escalation.'),
            'closed_summary' => $closedInPeriod->count().' incident(s) restored/closed during the reporting period.',

            // HTML tables (raw placeholders ending in _table)
            'summary_table' => $this->table($this->reports->summaryColumns(), $this->reports->run('summary_report', [
                'from' => $periodStart->toDateTimeString(), 'to' => $now->toDateTimeString(),
            ])->rowsArray(500)),
            'trend_table' => $this->table(['day' => 'Day', 'outages' => 'Outages', 'customers' => 'Customers', 'resolved' => 'Resolved'], $this->trend($now)),
            'top_olts_table' => $this->table(['olt' => 'OLT', 'fdt' => 'FDT', 'outages' => 'Outages', 'customers' => 'Customers'], $this->reports->run('olt_fdt_impact', [
                'from' => $now->copy()->subDays(7)->toDateTimeString(),
            ])->rowsArray(5)),
            'top_services_table' => $this->table(['service' => 'Service', 'outages' => 'Outages', 'customers' => 'Customers'], $this->reports->run('service_impact', [
                'from' => $now->copy()->subDays(7)->toDateTimeString(),
            ])->rowsArray(5)),
            'active_table' => $this->table([
                'ticket' => 'Ticket', 'title' => 'Title', 'priority' => 'Priority', 'customers' => 'Clients', 'start_time' => 'Start',
                'status' => 'Status', 'sla_status' => 'SLA', 'owner' => 'Owner',
            ], $active->take(50)->map(fn (Outage $o) => $this->item($o))->all()),
        ] + $this->mobility($periodStart, $now);
    }

    /**
     * 4G NOC tickets for the hourly 4G update: counts (g4_*) and the loops
     * {{#for each active 4G outage}}, {{#for each closed 4G outage}}, {{#for each 4G power outage}}…
     */
    public function mobility(CarbonInterface $periodStart, CarbonInterface $now): array
    {
        $open = Outage::query()->mobility()->active()->with(MobilityReports::RELATIONS)
            ->get()->sortBy(fn (Outage $o) => [$o->priority?->severity ? -$o->priority->severity : 0, $o->start_time?->getTimestamp()])->values();
        $closed = Outage::query()->mobility()->where('is_closed', true)->whereBetween('resolved_at', [$periodStart, $now])
            ->with(MobilityReports::RELATIONS)->orderByDesc('resolved_at')->limit(100)->get();

        $k = app(MobilityMetrics::class)->overview($periodStart, $now);
        $items = fn (Collection $tickets) => $tickets->map(fn (Outage $o) => $this->mobilityItem($o))->values()->all();
        $domain = fn (string $code) => $open->where('fault_domain', $code)->values();

        return [
            'g4_active' => $k['open'],
            'g4_critical' => $k['critical'],
            'g4_major' => $k['major'],
            'g4_minor' => $k['minor'],
            'g4_enodeb' => $k['enodeb'],
            'g4_transmission' => $k['transmission'],
            'g4_power' => $k['power'],
            'g4_with_vendor' => $k['with_vendor'],
            'g4_pending_noc' => $k['pending_noc'],
            'g4_sites_down' => $k['sites_down'],
            'g4_cells_down' => $k['cells_down'],
            'g4_sla_at_risk' => $k['sla_at_risk'],
            'g4_sla_breached' => $k['sla_breached'],
            'g4_new' => Outage::query()->mobility()->whereBetween('created_at', [$periodStart, $now])->count(),
            'g4_closed' => $closed->count(),
            'g4_mttr' => MobilityMetrics::human($k['mttr']),

            'g4_outages' => $items($open),
            'g4_enodeb_outages' => $items($domain(OutageType::DOMAIN_ENODEB)),
            'g4_transmission_outages' => $items($domain(OutageType::DOMAIN_TRANSMISSION)),
            'g4_power_outages' => $items($domain(OutageType::DOMAIN_POWER)),
            'g4_vendor_outages' => $items($open->where('workflow', OutageType::WORKFLOW_MOBILITY_VENDOR)->values()),
            'g4_closed_outages' => $items($closed),

            'g4_active_table' => $this->table([
                'ticket' => 'Ticket', 'category' => 'Category', 'severity' => 'Severity', 'site' => 'Site(s)', 'start_time' => 'Start',
                'elapsed' => 'Open for', 'status' => 'Status', 'owner' => 'With', 'sla_status' => 'SLA',
            ], $items($open->take(50))),
        ];
    }

    public function mobilityItem(Outage $o): array
    {
        $end = $o->end_time ?? $o->resolved_at;

        return [
            'ticket' => $o->ticket_number,
            'title' => $o->title,
            'category' => $o->domainLabel() ?? '—',
            'outage_type' => $o->outageType?->name,
            'severity' => $o->priority?->name ?? '—',
            'site' => $o->sitesLabel(4) ?: ($o->nodesLabel(4) ?: ($o->location ?: '—')),
            'nodes' => $o->nodesLabel(4) ?: '—',
            'impact' => $o->impactLabel() ?: '—',
            'location' => $o->location ?: '—',
            'start_time' => $o->start_time?->format('H:i d/m'),
            'elapsed' => $o->elapsedForHumans(),
            'status' => $o->status?->name,
            'owner' => $o->isVendorTicket() && ! $o->current_queue
                ? 'Vendor '.($o->vendor?->name ?? '—')
                : ($o->currentAssignee?->name ?? $o->technician?->name ?? '4G NOC'),
            'vendor' => $o->vendor?->name ?? '—',
            'engineer' => $o->technician?->name ?? '—',
            'vendor_eta' => $o->vendor_eta_at?->format('H:i d/m') ?? '—',
            'sla_status' => $o->currentSlaState()?->getLabel() ?? '—',
            'sla_due' => $o->sla_due_at?->format('H:i d/m') ?? '—',
            'latest_action' => $o->latest_update ?: 'Awaiting update.',
            'end_time' => $end?->format('H:i d/m') ?? '—',
            'duration' => $end && $o->start_time ? MobilityMetrics::human((int) $o->start_time->diffInMinutes($end)) : '—',
            'root_cause' => $o->root_cause ?: '—',
            'resolution' => $o->resolution ?: '—',
        ];
    }

    /** 7-day trend computed in PHP so it is identical on MySQL and PostgreSQL. */
    public function trend(CarbonInterface $now, int $days = 7): array
    {
        $start = $now->copy()->subDays($days - 1)->startOfDay();

        $created = Outage::query()->where('start_time', '>=', $start)->get(['start_time', 'customers_affected'])
            ->groupBy(fn (Outage $o) => $o->start_time->format('Y-m-d'));
        $resolved = Outage::query()->where('resolved_at', '>=', $start)->get(['resolved_at'])
            ->groupBy(fn (Outage $o) => $o->resolved_at->format('Y-m-d'));

        $rows = [];
        for ($i = 0; $i < $days; $i++) {
            $day = $start->copy()->addDays($i)->format('Y-m-d');
            $rows[] = [
                'day' => $start->copy()->addDays($i)->format('D d M'),
                'outages' => $created->get($day, collect())->count(),
                'customers' => (int) $created->get($day, collect())->sum('customers_affected'),
                'resolved' => $resolved->get($day, collect())->count(),
            ];
        }

        return $rows;
    }

    public function item(Outage $o): array
    {
        return [
            'ticket' => $o->ticket_number,
            'title' => $o->title,
            'location' => $o->location ?: ($o->olts->pluck('name')->implode(', ') ?: '—'),
            'olt_fdt' => $o->topologyPath() ?: '—',
            'start_time' => $o->start_time?->format('H:i d/m'),
            'end_time' => ($o->end_time ?? $o->resolved_at)?->format('H:i d/m'),
            'customers' => $o->customers_affected,
            'priority' => $o->priority?->name ?? '—',
            'noc_time' => $o->noc_escalated_at?->format('H:i') ?? 'N/A',
            'maintenance_time' => $o->infra_escalated_at?->format('H:i') ?? 'N/A',
            'sms_status' => $o->sms_sent ? 'Sent' : 'Not sent',
            'credit_status' => $o->credit_shared ? 'Shared' : 'Not shared',
            'sla_status' => $o->sla_state?->getLabel() ?? '—',
            'status' => $o->status?->name,
            'owner' => $o->currentAssignee?->name ?? $o->technician?->name ?? ($o->team?->name ?? 'Unassigned'),
            'latest_action' => $o->latest_update ?: 'Awaiting update.',
            'final_reason' => $o->final_reason ?: '—',
            'sites_down' => $o->sites_down,
            'cells_down' => $o->cells_down,
            'power_faults' => $o->power_faults,
            'services' => $o->services->pluck('name')->implode(', '),
        ];
    }

    private function summaryText(Collection $outages, string $empty): string
    {
        if ($outages->isEmpty()) {
            return $empty;
        }

        return $outages->map(fn (Outage $o) => "• {$o->ticket_number} | {$o->title} | Start {$o->start_time?->format('H:i d/m')} | Impact {$o->customers_affected} | {$o->status?->name} | SLA {$o->sla_state?->getLabel()}")
            ->implode("\n");
    }

    /** Compact inline-styled HTML table for email clients (no newlines: templates use nl2br). */
    public function table(array $columns, array $rows): string
    {
        if ($rows === []) {
            return '<p style="color:#6b7280;margin:4px 0;">No records.</p>';
        }

        $th = 'style="background:#b45309;color:#fff;text-align:left;padding:6px;font-size:12px;"';
        $td = 'style="border-bottom:1px solid #e5e7eb;padding:6px;font-size:12px;vertical-align:top;"';

        $html = '<table cellspacing="0" cellpadding="0" style="border-collapse:collapse;width:100%;margin:6px 0;"><thead><tr>';
        foreach ($columns as $heading) {
            $html .= "<th {$th}>".e($heading).'</th>';
        }
        $html .= '</tr></thead><tbody>';
        foreach ($rows as $row) {
            $html .= '<tr>';
            foreach (array_keys($columns) as $key) {
                $html .= "<td {$td}>".e((string) ($row[$key] ?? '')).'</td>';
            }
            $html .= '</tr>';
        }

        return $html.'</tbody></table>';
    }
}
