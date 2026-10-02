<?php

namespace App\Services\Reports;

use App\Enums\SlaState;
use App\Models\Outage;
use App\Models\OutageType;
use App\Services\Mobility\MobilityMetrics;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * 4G NOC reports (spec §20 of the 4G requirements): outage lists per category, vendor and
 * engineer performance, SLA compliance, MTTR and root cause analysis. Same ReportResult as the
 * other reports, so the reports page and the CSV / Excel / PDF exports work unchanged.
 */
class MobilityReports
{
    public const REPORTS = [
        'mobility_outages' => '4G Outage Report',
        'mobility_power' => '4G Power Outage Report',
        'mobility_transmission' => '4G Transmission Report',
        'mobility_enodeb' => '4G eNodeB Report',
        'mobility_vendor_performance' => '4G Vendor Performance',
        'mobility_engineer_performance' => '4G Engineer Performance',
        'mobility_sla' => '4G SLA Compliance',
        'mobility_mttr' => '4G MTTR Report',
        'mobility_root_cause' => '4G Root Cause Analysis',
    ];

    public const RELATIONS = [
        'outageType:id,name,code', 'priority:id,name,code,severity', 'status:id,name,code', 'vendor:id,name', 'technician:id,name',
        'currentAssignee:id,name', 'slaPolicy:id,name', 'enodebs:id,site_id,name', 'transmissionNodes:id,code,name', 'services:id,name',
    ];

    public function __construct(private readonly MobilityMetrics $metrics) {}

    public function run(string $key, array $filters = []): ReportResult
    {
        return match ($key) {
            'mobility_outages' => $this->tickets(self::REPORTS[$key], $filters, null),
            'mobility_power' => $this->tickets(self::REPORTS[$key], $filters, OutageType::DOMAIN_POWER),
            'mobility_transmission' => $this->tickets(self::REPORTS[$key], $filters, OutageType::DOMAIN_TRANSMISSION),
            'mobility_enodeb' => $this->tickets(self::REPORTS[$key], $filters, OutageType::DOMAIN_ENODEB),
            'mobility_vendor_performance' => $this->vendorPerformance($filters),
            'mobility_engineer_performance' => $this->engineerPerformance($filters),
            'mobility_sla' => $this->slaCompliance($filters),
            'mobility_mttr' => $this->mttr($filters),
            'mobility_root_cause' => $this->rootCause($filters),
            default => throw new InvalidArgumentException("Unknown report [{$key}]."),
        };
    }

    // ------------------------------------------------------------------ ticket lists

    /** @return array<string, string> */
    public function columns(?string $domain = null): array
    {
        $columns = [
            'ticket' => 'Ticket', 'title' => 'Title', 'category' => 'Category', 'outage_type' => 'Outage Type', 'severity' => 'Severity',
            'sites' => 'Site(s)', 'nodes' => 'Transmission Node(s)', 'affected' => 'Affected', 'link_id' => 'Transmission Link ID',
            'link_type' => 'Transmission Type', 'vendor' => 'Vendor', 'engineer' => 'Engineer / Technician', 'location' => 'Location',
            'services' => 'Affected Services', 'start_time' => 'Outage Start', 'detected' => 'Detected', 'acknowledged' => 'Acknowledged',
            'restored' => 'Restored', 'closed' => 'Closed', 'duration' => 'Outage Duration (min)', 'to_acknowledge' => 'Time to Acknowledge (min)',
            'to_restore' => 'Time to Restore (min)', 'status' => 'Status', 'sla_state' => 'SLA', 'sla_due' => 'SLA Deadline',
            'vendor_updates' => 'Vendor Updates', 'reopened' => 'Reopened', 'root_cause' => 'Root Cause', 'resolution' => 'Resolution',
        ];

        // Each category report only carries the columns that mean something for it.
        $drop = match ($domain) {
            OutageType::DOMAIN_POWER => ['category', 'engineer', 'link_id', 'link_type'],
            OutageType::DOMAIN_TRANSMISSION => ['category', 'vendor', 'vendor_updates'],
            OutageType::DOMAIN_ENODEB => ['category', 'vendor', 'vendor_updates', 'nodes', 'link_id', 'link_type'],
            default => ['link_id', 'link_type'],
        };

        return array_diff_key($columns, array_flip($drop));
    }

    public function row(Outage $o): array
    {
        $end = $o->end_time ?? $o->resolved_at;
        $minutes = fn ($from, $to) => $from && $to ? max(0, (int) $from->diffInMinutes($to)) : null;

        return [
            'ticket' => $o->ticket_number,
            'title' => $o->title,
            'category' => $o->domainLabel(),
            'outage_type' => $o->outageType?->name,
            'severity' => $o->priority?->name,
            'sites' => $o->enodebs->map(fn ($site) => $site->site_id.' '.$site->name)->implode(', '),
            'nodes' => $o->transmissionNodes->map(fn ($node) => $node->label())->implode(', '),
            'affected' => $o->impactLabel(),
            'link_id' => $o->transmission_link_id,
            'link_type' => Outage::TRANSMISSION_TYPES[$o->transmission_type] ?? $o->transmission_type,
            'vendor' => $o->vendor?->name,
            'engineer' => $o->technician?->name,
            'location' => $o->location,
            'services' => $o->services->pluck('name')->implode(', '),
            'start_time' => $o->start_time?->format('Y-m-d H:i'),
            'detected' => $o->detected_at?->format('Y-m-d H:i'),
            'acknowledged' => $o->acknowledged_at?->format('Y-m-d H:i'),
            'restored' => $o->restored_at?->format('Y-m-d H:i'),
            'closed' => $o->is_closed ? ($o->resolved_at ?? $o->cancelled_at)?->format('Y-m-d H:i') : null,
            'duration' => $minutes($o->start_time, $end ?? now()),
            'to_acknowledge' => $minutes($o->created_at, $o->acknowledged_at),
            'to_restore' => $minutes($o->start_time, $o->restored_at),
            'status' => $o->status?->name,
            'sla_state' => $o->currentSlaState()?->getLabel(),
            'sla_due' => $o->sla_due_at?->format('Y-m-d H:i'),
            'vendor_updates' => $o->vendor_updates_count ?? null,
            'reopened' => $o->reopen_count ?: null,
            'root_cause' => $o->root_cause,
            'resolution' => $o->resolution,
        ];
    }

    public function query(array $filters, ?string $domain = null): Builder
    {
        return OutageQuery::apply(Outage::query()->mobility()->with(self::RELATIONS)->withCount('vendorUpdates'), $filters)
            ->when($domain, fn (Builder $q) => $q->ofDomain($domain))
            ->when($filters['vendor_ids'] ?? null, fn (Builder $q, $ids) => $q->whereIn('vendor_id', (array) $ids));
    }

    private function tickets(string $title, array $filters, ?string $domain): ReportResult
    {
        $columns = $this->columns($domain);
        $query = fn () => $this->query($filters, $domain);
        $closed = fn () => $query()->where('is_closed', true);

        return new ReportResult($title, $columns, function (?int $limit) use ($query, $columns) {
            $q = $query()->orderByDesc('start_time')->orderByDesc('id');

            foreach ($limit ? $q->limit($limit)->get() : $q->lazyById(300, 'outages.id') as $outage) {
                yield array_replace(array_fill_keys(array_keys($columns), null), array_intersect_key($this->row($outage), $columns));
            }
        }, [
            'Outages' => $query()->count(),
            'Open' => $query()->where('is_closed', false)->count(),
            'Closed' => $closed()->count(),
            'SLA missed' => $closed()->where('sla_state', SlaState::Missed->value)->count(),
            'Sites affected' => (int) $query()->sum('sites_down'),
        ]);
    }

    // ------------------------------------------------------------------ aggregates

    private function vendorPerformance(array $filters): ReportResult
    {
        [$from, $to] = $this->period($filters);
        $rows = $this->metrics->vendors($from, $to)->map(fn (array $row) => $row + [
            'avg_response_text' => MobilityMetrics::human($row['avg_response']),
            'avg_resolution_text' => MobilityMetrics::human($row['avg_resolution']),
            'compliance' => $row['sla_compliance'] !== null ? $row['sla_compliance'].'%' : null,
        ]);

        return $this->fromRows(self::REPORTS['mobility_vendor_performance'], [
            'vendor' => 'Vendor', 'tickets' => 'Tickets (period)', 'open' => 'Open Now', 'closed' => 'Closed', 'escalated' => 'Escalated (no update)',
            'avg_response' => 'Avg Response (min)', 'avg_response_text' => 'Avg Response', 'avg_resolution' => 'Avg Resolution (min)',
            'avg_resolution_text' => 'Avg Resolution', 'sla_met' => 'SLA Met', 'sla_missed' => 'SLA Missed', 'compliance' => 'SLA Compliance',
        ], $rows, 'Response = vendor notified → vendor acknowledged. Resolution = outage start → vendor reports restoration.', $from, $to);
    }

    private function engineerPerformance(array $filters): ReportResult
    {
        [$from, $to] = $this->period($filters);
        $rows = $this->metrics->engineers($from, $to)->map(fn (array $row) => $row + [
            'avg_response_text' => MobilityMetrics::human($row['avg_response']),
            'avg_resolution_text' => MobilityMetrics::human($row['avg_resolution']),
            'compliance' => $row['sla_compliance'] !== null ? $row['sla_compliance'].'%' : null,
        ]);

        return $this->fromRows(self::REPORTS['mobility_engineer_performance'], [
            'engineer' => 'Engineer / Technician', 'assigned' => 'Assigned (period)', 'open' => 'Open Now', 'resolved' => 'Resolved',
            'avg_response' => 'Avg Time to Start (min)', 'avg_response_text' => 'Avg Time to Start', 'avg_resolution' => 'Avg Resolution (min)',
            'avg_resolution_text' => 'Avg Resolution', 'sla_met' => 'SLA Met', 'sla_missed' => 'SLA Missed', 'compliance' => 'SLA Compliance',
        ], $rows, 'Time to start = assigned → investigation started. Resolution = outage start → marked resolved.', $from, $to);
    }

    private function slaCompliance(array $filters): ReportResult
    {
        [$from, $to] = $this->period($filters);
        $tickets = Outage::query()->mobility()->whereBetween('start_time', [$from, $to])
            ->with(['priority:id,name,severity', 'outageType:id,name', 'vendor:id,name'])
            ->get(['id', 'priority_id', 'outage_type_id', 'vendor_id', 'is_closed', 'sla_state', 'sla_due_at', 'sla_pre_breach_at', 'sla_paused_at']);

        $row = function (string $group, string $name, Collection $items): array {
            $met = $items->where('sla_state', SlaState::Met)->count();
            $missed = $items->where('sla_state', SlaState::Missed)->count();
            $open = $items->where('is_closed', false);
            $state = $open->countBy(fn (Outage $o) => $o->currentSlaState()?->value);

            return [
                'group' => $group, 'name' => $name, 'tickets' => $items->count(), 'met' => $met, 'missed' => $missed,
                'compliance' => $met + $missed > 0 ? round($met / ($met + $missed) * 100, 1).'%' : null,
                'open' => $open->count(),
                'open_at_risk' => (int) ($state[SlaState::AtRisk->value] ?? 0),
                'open_breached' => (int) ($state[SlaState::Breached->value] ?? 0),
            ];
        };

        $rows = collect([$row('All', 'All 4G outages', $tickets)])
            ->merge($tickets->groupBy(fn (Outage $o) => $o->priority?->name ?? 'No severity')->map(fn ($items, $name) => $row('Severity', $name, $items))->values())
            ->merge($tickets->groupBy(fn (Outage $o) => $o->outageType?->name ?? '—')->map(fn ($items, $name) => $row('Outage type', $name, $items))->values())
            ->merge($tickets->whereNotNull('vendor_id')->groupBy(fn (Outage $o) => $o->vendor?->name ?? '—')->map(fn ($items, $name) => $row('Vendor', $name, $items))->values());

        return $this->fromRows(self::REPORTS['mobility_sla'], [
            'group' => 'By', 'name' => 'Name', 'tickets' => 'Tickets', 'met' => 'SLA Met', 'missed' => 'SLA Missed', 'compliance' => 'Compliance',
            'open' => 'Still Open', 'open_at_risk' => 'Open – Approaching SLA', 'open_breached' => 'Open – SLA Breached',
        ], $rows, 'Compliance = closed within the SLA deadline ÷ all closed (cancelled tickets have no SLA outcome).', $from, $to);
    }

    private function mttr(array $filters): ReportResult
    {
        [$from, $to] = $this->period($filters);
        $rows = $this->metrics->mttr($from, $to)->map(fn (array $row) => $row + [
            'mttr_text' => MobilityMetrics::human($row['mttr']),
            'longest_text' => MobilityMetrics::human($row['longest']),
        ]);

        return $this->fromRows(self::REPORTS['mobility_mttr'], [
            'group' => 'Group', 'tickets' => 'Closed Tickets', 'mttr' => 'MTTR (min)', 'mttr_text' => 'MTTR', 'avg_acknowledge' => 'Avg Time to Acknowledge (min)',
            'avg_restoration' => 'Avg Restoration (min)', 'avg_resolution' => 'Avg Resolution (min)', 'longest' => 'Longest Outage (min)', 'longest_text' => 'Longest Outage',
        ], $rows, 'Tickets closed in the period. MTTR = outage start → outage end. Restoration = start → service restored. Resolution = ticket created → closed.', $from, $to);
    }

    private function rootCause(array $filters): ReportResult
    {
        [$from, $to] = $this->period($filters);
        $groups = [];

        $query = Outage::query()->mobility()->whereBetween('start_time', [$from, $to])->whereNotNull('root_cause')
            ->with(['enodebs:id,site_id,name', 'vendor:id,name'])
            ->select(['id', 'ticket_number', 'fault_domain', 'vendor_id', 'root_cause', 'start_time', 'end_time', 'resolved_at', 'sites_down']);

        foreach ($query->lazyById(300) as $outage) {
            // Same cause typed slightly differently still counts as one.
            $key = $outage->fault_domain.'|'.Str::of($outage->root_cause)->lower()->squish()->rtrim('.')->limit(120, '');
            $end = $outage->end_time ?? $outage->resolved_at;

            $groups[$key] ??= ['category' => OutageType::FAULT_DOMAINS[$outage->fault_domain] ?? 'Other', 'root_cause' => Str::limit(Str::squish($outage->root_cause), 200),
                'tickets' => 0, 'sites' => [], 'vendors' => [], 'minutes' => [], 'last' => null, 'example' => $outage->ticket_number];
            $group = &$groups[$key];
            $group['tickets']++;
            $group['sites'] = array_unique(array_merge($group['sites'], $outage->enodebs->map(fn ($site) => $site->site_id.' '.$site->name)->all()));
            $group['vendors'][$outage->vendor?->name ?? ''] = true;
            $group['last'] = max($group['last'] ?? '', $outage->start_time->format('Y-m-d H:i'));

            if ($end) {
                $group['minutes'][] = max(0, (int) $outage->start_time->diffInMinutes($end));
            }
            unset($group);
        }

        $rows = collect($groups)->map(fn (array $g) => [
            'category' => $g['category'], 'root_cause' => $g['root_cause'], 'tickets' => $g['tickets'],
            'sites' => implode(', ', array_slice($g['sites'], 0, 8)).(count($g['sites']) > 8 ? ' +'.(count($g['sites']) - 8).' more' : ''),
            'vendors' => implode(', ', array_filter(array_keys($g['vendors']))),
            'avg_duration' => $g['minutes'] ? (int) round(array_sum($g['minutes']) / count($g['minutes'])) : null,
            'total_downtime' => $g['minutes'] ? array_sum($g['minutes']) : null,
            'last' => $g['last'], 'example' => $g['example'],
        ])->sortByDesc('tickets')->values();

        return $this->fromRows(self::REPORTS['mobility_root_cause'], [
            'category' => 'Category', 'root_cause' => 'Root Cause', 'tickets' => 'Occurrences', 'sites' => 'Sites', 'vendors' => 'Vendor(s)',
            'avg_duration' => 'Avg Outage (min)', 'total_downtime' => 'Total Downtime (min)', 'last' => 'Last Occurrence', 'example' => 'Example Ticket',
        ], $rows, 'Tickets with a recorded root cause, grouped by category and cause, most frequent first.', $from, $to);
    }

    // ------------------------------------------------------------------ helpers

    /** Defaults to the last 30 days when the report is run without dates. */
    private function period(array $filters): array
    {
        [$from, $to] = OutageQuery::period($filters);

        return [$from ?? now()->subDays(29)->startOfDay(), $to ?? now()->endOfDay()];
    }

    private function fromRows(string $title, array $columns, Collection $rows, string $description, Carbon $from, Carbon $to): ReportResult
    {
        $rows = $rows->map(fn (array $row) => array_replace(array_fill_keys(array_keys($columns), null), array_intersect_key($row, $columns)))->values();

        return new ReportResult(
            $title,
            $columns,
            fn (?int $limit) => $limit ? $rows->take($limit)->all() : $rows->all(),
            ['Rows' => $rows->count()],
            $from->format('d M Y').' – '.$to->format('d M Y').'. '.$description,
        );
    }
}
