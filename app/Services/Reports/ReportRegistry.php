<?php

namespace App\Services\Reports;

use App\Enums\AssignmentStatus;
use App\Enums\QueueType;
use App\Enums\SlaState;
use App\Models\AuditLog;
use App\Models\ChangeRequest;
use App\Models\MajorOutageType;
use App\Models\Outage;
use App\Models\OutageAssignment;
use App\Models\OutageAssignmentHistory;
use App\Models\Priority;
use App\Models\Team;
use App\Models\User;
use App\Models\Vertical;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * Report catalogue (spec §20). Each report returns a ReportResult that the reports page,
 * scheduled emails and the CSV / Excel / PDF exporters all consume.
 */
class ReportRegistry
{
    public const REPORTS = [
        'outage_register' => 'Outage Register',
        'summary_report' => 'Summary Report',
        'sla_compliance' => 'SLA Compliance Report',
        'critical_major' => 'Critical / Major Incident Report',
        'customer_impact' => 'Customer Impact Report',
        'olt_fdt_impact' => 'OLT / FDT Impact Report',
        'service_impact' => 'Service Impact Report',
        'team_performance' => 'Team Performance Report',
        'technician_performance' => 'Technician Performance Report',
        'sms_credit' => 'SMS & Credit Report',
        'core_outage' => 'Core Outage Report',
        'mobility_4g' => '4G Site / Cell Outage Report',
        'system_outage' => 'System Outage Report',
        'recurring' => 'Recurring Outage Report',
        'planned_maintenance' => 'Planned Maintenance Report',
        'assignment_history' => 'FIFO Assignment History Report',
        'audit_trail' => 'Audit Trail Report',
    ] + [ProductivityReport::KEY => 'Staff Productivity (all modules)', AvailabilityTimeReport::KEY => AvailabilityTimeReport::TITLE] + DeskSlaReports::REPORTS + InsightReports::REPORTS + MobilityReports::REPORTS + TicketReports::REPORTS;

    /** Reports that cover every ticket, whatever its vertical. */
    public const SHARED = ['assignment_history', 'audit_trail'];

    /**
     * Reports a user may run: the 4G reports need "View 4G outages", the FTTH / core / system
     * ones "View outages" — the same split as the tickets themselves.
     *
     * @return array<string, string>
     */
    public static function availableTo(?User $user): array
    {
        return array_filter(self::REPORTS, fn (string $key) => match (true) {
            $user === null => false,
            in_array($key, self::SHARED, true) => true,
            // Whoever reads reports sees everyone's availability time (each person sees their own under Operations → Availability Time).
            $key === AvailabilityTimeReport::KEY => true,
            // Across modules: each person sees the modules they may view.
            $key === ProductivityReport::KEY, isset(DeskSlaReports::REPORTS[$key]) => $user->canAny(['outage.view', 'mobility.view', 'ticket.view']),
            isset(InsightReports::REPORTS[$key]) => InsightReports::allows($key, $user),
            $key === 'mobility_audit' => $user->can('mobility.view') && $user->can('audit.view'),
            $key === 'ticket_audit' => $user->can('ticket.view') && $user->can('audit.view'),
            isset(TicketReports::REPORTS[$key]) => $user->can('ticket.view'),
            $key === 'mobility_4g' || isset(MobilityReports::REPORTS[$key]) => $user->can('mobility.view'),
            default => $user->can('outage.view'),
        }, ARRAY_FILTER_USE_KEY);
    }

    public function run(string $key, array $filters = []): ReportResult
    {
        if (isset(MobilityReports::REPORTS[$key])) {
            return app(MobilityReports::class)->run($key, $filters);
        }

        if (isset(TicketReports::REPORTS[$key])) {
            return app(TicketReports::class)->run($key, $filters);
        }

        if ($key === AvailabilityTimeReport::KEY) {
            return app(AvailabilityTimeReport::class)->run($filters);
        }

        if ($key === ProductivityReport::KEY) {
            return app(ProductivityReport::class)->run($filters);
        }

        if (isset(DeskSlaReports::REPORTS[$key])) {
            return app(DeskSlaReports::class)->run($key, $filters);
        }

        if (isset(InsightReports::REPORTS[$key])) {
            return app(InsightReports::class)->run($key, $filters);
        }

        return match ($key) {
            'outage_register' => $this->outageList(self::REPORTS[$key], $filters, $this->registerColumns()),
            'summary_report' => $this->outageList(self::REPORTS[$key], $filters, $this->summaryColumns()),
            'sla_compliance' => $this->slaCompliance($filters),
            'critical_major' => $this->criticalMajor($filters),
            'customer_impact' => $this->customerImpact($filters),
            'olt_fdt_impact' => $this->oltFdtImpact($filters),
            'service_impact' => $this->serviceImpact($filters),
            'team_performance' => $this->teamPerformance($filters),
            'technician_performance' => $this->technicianPerformance($filters),
            'sms_credit' => $this->smsCredit($filters),
            'core_outage' => $this->outageList(self::REPORTS[$key], $filters + ['_major' => MajorOutageType::CORE], $this->registerColumns()),
            'mobility_4g' => $this->mobility($filters),
            'system_outage' => $this->outageList(self::REPORTS[$key], $filters + ['_major' => MajorOutageType::SYSTEM], $this->registerColumns()),
            'recurring' => $this->recurring($filters),
            'planned_maintenance' => $this->plannedMaintenance($filters),
            'assignment_history' => $this->assignmentHistory($filters),
            'audit_trail' => $this->auditTrail($filters),
            default => throw new InvalidArgumentException("Unknown report [{$key}]."),
        };
    }

    // ------------------------------------------------------------------ outage row reports

    public function registerColumns(): array
    {
        return [
            'date_reported' => 'Date Reported', 'ticket' => 'Ticket', 'title' => 'Title', 'major_type' => 'Major Type',
            'outage_type' => 'Outage Type', 'vertical' => 'Vertical', 'priority' => 'Priority', 'topology' => 'OLT/FDT/Slot/Port',
            'services' => 'Affected Services', 'customers' => 'Affected Clients', 'credit' => 'Credit Sent', 'sms' => 'SMS Sent',
            'start_time' => 'Start Time', 'noc_escalated' => 'NOC Escalated', 'noc_time' => 'NOC Escalation Time',
            'infra_escalated' => 'Maintenance/Infrastructure Escalated', 'infra_time' => 'Escalation Time',
            'end_time' => 'End Time', 'duration' => 'Duration (min)', 'team' => 'Team', 'technician' => 'Technician',
            'sla_state' => 'SLA', 'final_reason' => 'RFO / Final Reason', 'status' => 'Status',
        ];
    }

    /** Exact column structure of spec §17. */
    public function summaryColumns(): array
    {
        return [
            'date_reported' => 'Date Reported', 'ticket' => 'Ticket', 'major_type' => 'Major Type', 'outage_type' => 'Outage Type',
            'vertical' => 'Vertical', 'topology' => 'OLT/FDT/Slot/Port', 'customers' => 'Affected Clients', 'credit' => 'Credit Sent',
            'sms' => 'SMS Sent', 'start_time' => 'Start Time', 'noc_escalated' => 'NOC Escalated', 'noc_time' => 'NOC Escalation Time',
            'infra_escalated' => 'Maintenance/Infrastructure Escalated', 'infra_time' => 'Escalation Time', 'end_time' => 'End Time',
            'final_reason' => 'RFO / Final Reason', 'status' => 'Status',
        ];
    }

    public function outageRow(Outage $o): array
    {
        $end = $o->end_time ?? $o->resolved_at;

        return [
            'date_reported' => $o->created_at?->format('Y-m-d H:i'),
            'ticket' => $o->ticket_number,
            'title' => $o->title,
            'major_type' => $o->majorOutageType?->name,
            'outage_type' => $o->outageType?->name,
            'vertical' => $o->vertical?->name,
            'priority' => $o->priority?->name,
            'topology' => $o->topologyPath(),
            'services' => $o->services->pluck('name')->implode(', '),
            'customers' => $o->customers_affected,
            'credit' => $o->credit_shared ? 'YES' : 'NO',
            'sms' => $o->sms_sent ? 'YES' : 'NO',
            'start_time' => $o->start_time?->format('Y-m-d H:i'),
            'noc_escalated' => $o->noc_escalated_at ? 'YES' : 'NO',
            'noc_time' => $o->noc_escalated_at?->format('Y-m-d H:i'),
            'infra_escalated' => $o->infra_escalated_at ? 'YES' : 'NO',
            'infra_time' => $o->infra_escalated_at?->format('Y-m-d H:i'),
            'end_time' => $end?->format('Y-m-d H:i'),
            'duration' => $end ? (int) $o->start_time->diffInMinutes($end) : null,
            'team' => collect([$o->team?->name, $o->subTeam?->name])->filter()->implode(' / '),
            'technician' => $o->technician?->name,
            'sla_state' => $o->sla_state?->getLabel(),
            'final_reason' => $o->final_reason,
            'status' => $o->status?->name,
        ];
    }

    private function outageList(string $title, array $filters, array $columns): ReportResult
    {
        $query = fn () => $this->scoped($filters);
        $total = (clone $query())->count();
        $customers = (int) (clone $query())->sum('customers_affected');

        return new ReportResult(
            $title,
            $columns,
            function (?int $limit) use ($query, $columns) {
                $q = $query()->orderByDesc('start_time')->orderByDesc('id');
                $source = $limit ? $q->limit($limit)->get() : $q->lazyById(500, 'outages.id');

                foreach ($source as $outage) {
                    yield array_intersect_key($this->outageRow($outage), $columns);
                }
            },
            ['Outages' => $total, 'Customers affected' => number_format($customers)],
        );
    }

    private function slaCompliance(array $filters): ReportResult
    {
        $base = fn () => $this->scoped($filters);
        $counts = (clone $base())->toBase()->selectRaw('sla_state, count(*) as aggregate')->groupBy('sla_state')->pluck('aggregate', 'sla_state');
        $closed = (int) (($counts[SlaState::Met->value] ?? 0) + ($counts[SlaState::Missed->value] ?? 0));

        return new ReportResult(
            self::REPORTS['sla_compliance'],
            [
                'ticket' => 'Ticket', 'priority' => 'Priority', 'policy' => 'SLA Policy', 'start_time' => 'Start',
                'due' => 'SLA Due', 'end_time' => 'End', 'duration' => 'Duration (min)', 'state' => 'SLA State',
                'variance' => 'Breach (+) / Margin (-) min',
            ],
            function (?int $limit) use ($base) {
                $q = $base()->orderByDesc('start_time');
                foreach ($limit ? $q->limit($limit)->get() : $q->lazyById(500, 'outages.id') as $o) {
                    $end = $o->end_time ?? $o->resolved_at ?? now();

                    yield [
                        'ticket' => $o->ticket_number,
                        'priority' => $o->priority?->name,
                        'policy' => $o->slaPolicy?->name ?? 'Default',
                        'start_time' => $o->start_time?->format('Y-m-d H:i'),
                        'due' => $o->sla_due_at?->format('Y-m-d H:i'),
                        'end_time' => ($o->end_time ?? $o->resolved_at)?->format('Y-m-d H:i'),
                        'duration' => (int) $o->start_time->diffInMinutes($end),
                        'state' => $o->sla_state?->getLabel(),
                        'variance' => $o->sla_due_at ? (int) $o->sla_due_at->diffInMinutes($end, false) : null,
                    ];
                }
            },
            [
                'Compliant (live)' => (int) ($counts[SlaState::Compliant->value] ?? 0),
                'Approaching breach' => (int) ($counts[SlaState::AtRisk->value] ?? 0),
                'Breached (live)' => (int) ($counts[SlaState::Breached->value] ?? 0),
                'Resolved within SLA' => (int) ($counts[SlaState::Met->value] ?? 0),
                'Resolved outside SLA' => (int) ($counts[SlaState::Missed->value] ?? 0),
                'Compliance %' => $closed > 0 ? round(($counts[SlaState::Met->value] ?? 0) / $closed * 100, 1).'%' : 'n/a',
            ],
        );
    }

    private function criticalMajor(array $filters): ReportResult
    {
        $priorityIds = Priority::query()->whereIn('code', [Priority::CRITICAL, Priority::HIGH])->pluck('id')->all();

        return $this->simpleOutageReport(self::REPORTS['critical_major'], $filters, fn (Builder $q) => $q->whereIn('priority_id', $priorityIds ?: [0]), [
            'ticket' => 'Ticket', 'title' => 'Title', 'priority' => 'Priority', 'start_time' => 'Start', 'customers' => 'Impact',
            'owner' => 'Owner', 'noc_time' => 'NOC Escalation', 'infra_time' => 'Infra Escalation', 'status' => 'Status',
            'sla_state' => 'SLA', 'latest' => 'Latest Action',
        ], fn (Outage $o) => [
            'owner' => $o->currentAssignee?->name ?? $o->technician?->name ?? collect([$o->team?->name, $o->subTeam?->name])->filter()->implode(' / '),
            'latest' => $o->latest_update,
        ]);
    }

    private function customerImpact(array $filters): ReportResult
    {
        $base = $this->scoped($filters);
        $summary = [
            'Outages' => (clone $base)->count(),
            'Customers affected' => number_format((int) (clone $base)->sum('customers_affected')),
            'SMS sent' => (clone $base)->where('sms_sent', true)->count(),
            'Credits shared' => (clone $base)->where('credit_shared', true)->count(),
        ];

        return $this->simpleOutageReport(self::REPORTS['customer_impact'], $filters, null, [
            'ticket' => 'Ticket', 'vertical' => 'Vertical', 'topology' => 'OLT/FDT', 'services' => 'Services',
            'customers' => 'Customers', 'sms' => 'SMS Sent', 'credit' => 'Credit Shared', 'start_time' => 'Start',
            'end_time' => 'End', 'status' => 'Status',
        ], summary: $summary);
    }

    private function smsCredit(array $filters): ReportResult
    {
        return $this->simpleOutageReport(self::REPORTS['sms_credit'], $filters, null, [
            'ticket' => 'Ticket', 'customers' => 'Customers', 'sms' => 'SMS Sent', 'sms_by' => 'SMS Updated By',
            'sms_at' => 'SMS Updated At', 'credit' => 'Credit Shared', 'credit_by' => 'Credit Updated By',
            'credit_at' => 'Credit Updated At', 'status' => 'Status',
        ], fn (Outage $o) => [
            'sms_by' => $o->smsUpdatedBy?->name,
            'sms_at' => $o->sms_updated_at?->format('Y-m-d H:i'),
            'credit_by' => $o->creditUpdatedBy?->name,
            'credit_at' => $o->credit_updated_at?->format('Y-m-d H:i'),
        ]);
    }

    /** Planned maintenance with its CRF: window, reschedules, sign-off, outcome, customer notification and SLA. */
    private function plannedMaintenance(array $filters): ReportResult
    {
        $scope = fn (Builder $q) => $q->whereHas('outageType', fn (Builder $t) => $t->where('is_planned', true))
            ->with(['changeRequest.signatures', 'changeRequest.customersNotifiedBy:id,name']);
        $base = $scope($this->scoped($filters));

        return $this->simpleOutageReport(self::REPORTS['planned_maintenance'], $filters, $scope, [
            'ticket' => 'Ticket', 'title' => 'Title', 'scheduled_start' => 'Scheduled Start', 'scheduled_end' => 'Scheduled End',
            'downtime' => 'Expected Downtime', 'reschedules' => 'Reschedules', 'risk' => 'Risk', 'in_charge' => 'In-Charge / Lead',
            'topology' => 'OLT/FDT/Slot/Port', 'customers' => 'Affected Clients', 'crf_status' => 'CRF Status',
            'signed' => 'Signed', 'signatories' => 'Signatories', 'approved_at' => 'CRF Approved', 'team' => 'Team',
            'technician' => 'Technician', 'completed_at' => 'Work Completed', 'final_status' => 'Final Status',
            'outcome' => 'Outcome Details', 'customers_notified' => 'Customers Notified', 'notified_by' => 'Notified By',
            'sla' => 'SLA', 'status' => 'Status',
        ], function (Outage $o): array {
            $cr = $o->changeRequest;

            return [
                'scheduled_start' => $cr?->scheduled_start?->format('Y-m-d H:i'),
                'scheduled_end' => $cr?->scheduled_end?->format('Y-m-d H:i'),
                'downtime' => $cr?->expectedDowntime(),
                'reschedules' => $cr?->reschedule_count,
                'risk' => $cr?->riskLabel(),
                'in_charge' => $cr?->in_charge,
                'crf_status' => $cr?->statusLabel(),
                'signed' => $cr ? $cr->signedCount().' / '.$cr->signatures->count() : null,
                'signatories' => $cr?->signatures->map(fn ($s) => $s->role_label.': '.($s->signed_name ?: $s->displayName()).' ('.$s->statusLabel().')')->implode('; '),
                'approved_at' => $cr?->approved_at?->format('Y-m-d H:i'),
                'team' => collect([$o->team?->name, $o->subTeam?->name])->filter()->implode(' / '),
                'technician' => $o->technician?->name,
                'completed_at' => $o->restored_at?->format('Y-m-d H:i'),
                'final_status' => ChangeRequest::FINAL_STATUSES[$cr?->final_status] ?? null,
                'outcome' => $cr?->outcome_details,
                'customers_notified' => $cr?->customers_notified_at?->format('Y-m-d H:i'),
                'notified_by' => $cr?->customersNotifiedBy?->name,
                'sla' => $o->sla_state?->getLabel(),
            ];
        }, summary: [
            'Planned maintenance' => (clone $base)->count(),
            'Awaiting sign-off' => (clone $base)->whereHas('changeRequest', fn (Builder $c) => $c->where('status', ChangeRequest::PENDING_SIGNOFF))->count(),
            'Completed' => (clone $base)->whereHas('changeRequest', fn (Builder $c) => $c->whereNotNull('customers_notified_at'))->count(),
            'Rescheduled' => (clone $base)->whereHas('changeRequest', fn (Builder $c) => $c->where('reschedule_count', '>', 0))->count(),
        ]);
    }

    private function mobility(array $filters): ReportResult
    {
        // Every ticket of the 4G vertical: 4G NOC tickets and any older ones raised on the standard form.
        $filters += ['_scope' => 'all'];
        $verticalId = Vertical::query()->where('code', Vertical::MOBILITY_4G)->value('id');
        $base = $this->scoped($filters)->where('vertical_id', $verticalId ?? 0);

        return $this->simpleOutageReport(self::REPORTS['mobility_4g'], $filters, fn (Builder $q) => $q->where('vertical_id', $verticalId ?? 0), [
            'ticket' => 'Ticket', 'title' => 'Title', 'location' => 'Site / Area', 'sites_down' => 'Sites Down',
            'cells_down' => 'Cells Down', 'power_faults' => 'Power Faults', 'customers' => 'Customers',
            'start_time' => 'Start', 'end_time' => 'End', 'status' => 'Status',
        ], fn (Outage $o) => [
            'location' => $o->location, 'sites_down' => $o->sites_down, 'cells_down' => $o->cells_down, 'power_faults' => $o->power_faults,
        ], summary: [
            'Sites down' => (int) (clone $base)->sum('sites_down'),
            'Cells down' => (int) (clone $base)->sum('cells_down'),
            'Power faults' => (int) (clone $base)->sum('power_faults'),
        ]);
    }

    // ------------------------------------------------------------------ aggregate reports

    private function oltFdtImpact(array $filters): ReportResult
    {
        $groups = [];

        foreach ($this->scoped($filters)->lazyById(500, 'outages.id') as $o) {
            $olts = $o->olts->keyBy('id');
            $pairs = $o->fdts->isNotEmpty()
                ? $o->fdts->map(fn ($fdt) => [$olts[$fdt->olt_id]->name ?? '—', $fdt->name])
                : $o->olts->map(fn ($olt) => [$olt->name, '—']);

            foreach ($pairs as [$olt, $fdt]) {
                $key = $olt.'|'.$fdt;
                $groups[$key] ??= ['olt' => $olt, 'fdt' => $fdt, 'outages' => 0, 'customers' => 0, 'minutes' => [], 'last' => null];
                $groups[$key]['outages']++;
                $groups[$key]['customers'] += $o->customers_affected;
                if ($end = $o->end_time ?? $o->resolved_at) {
                    $groups[$key]['minutes'][] = $o->start_time->diffInMinutes($end);
                }
                $groups[$key]['last'] = max($groups[$key]['last'], $o->start_time?->format('Y-m-d H:i'));
            }
        }

        $rows = collect($groups)->sortByDesc(fn ($g) => [$g['customers'], $g['outages']])->map(fn ($g) => [
            'olt' => $g['olt'], 'fdt' => $g['fdt'], 'outages' => $g['outages'], 'customers' => $g['customers'],
            'avg_restore' => $g['minutes'] ? (int) round(array_sum($g['minutes']) / count($g['minutes'])) : null,
            'last' => $g['last'],
        ])->values();

        return $this->fromRows(self::REPORTS['olt_fdt_impact'], [
            'olt' => 'OLT', 'fdt' => 'FDT', 'outages' => 'Outages', 'customers' => 'Customers Affected',
            'avg_restore' => 'Avg Restore (min)', 'last' => 'Last Outage',
        ], $rows);
    }

    private function serviceImpact(array $filters): ReportResult
    {
        $groups = [];

        foreach ($this->scoped($filters)->lazyById(500, 'outages.id') as $o) {
            foreach ($o->services as $service) {
                $groups[$service->id] ??= ['service' => $service->name, 'outages' => 0, 'customers' => 0, 'active' => 0];
                $groups[$service->id]['outages']++;
                $groups[$service->id]['customers'] += $o->customers_affected;
                $groups[$service->id]['active'] += $o->is_closed ? 0 : 1;
            }
        }

        return $this->fromRows(self::REPORTS['service_impact'], [
            'service' => 'Service', 'outages' => 'Outages', 'customers' => 'Customers Affected', 'active' => 'Currently Active',
        ], collect($groups)->sortByDesc('customers')->values());
    }

    private function teamPerformance(array $filters): ReportResult
    {
        $rows = $this->performance($filters, 'team_id', Team::query()->pluck('name', 'id'));

        return $this->fromRows(self::REPORTS['team_performance'], $this->performanceColumns('Team'), $rows);
    }

    private function technicianPerformance(array $filters): ReportResult
    {
        $rows = $this->performance($filters + ['_queue' => QueueType::Technician->value], 'user_id', User::query()->pluck('name', 'id'));

        return $this->fromRows(self::REPORTS['technician_performance'], $this->performanceColumns('Technician'), $rows);
    }

    private function performanceColumns(string $who): array
    {
        return [
            'name' => $who, 'assigned' => 'Assigned', 'accepted' => 'Acknowledged', 'rejected' => 'Rejected',
            'timed_out' => 'Timed Out', 'completed' => 'Completed / Restored', 'breached' => 'SLA Breached',
            'avg_ack' => 'Avg Ack (min)', 'avg_restore' => 'Avg Restoration (min)',
        ];
    }

    private function performance(array $filters, string $groupColumn, Collection $names): Collection
    {
        [$from, $to] = OutageQuery::period($filters);
        $groups = [];

        $query = OutageAssignment::query()
            ->with('outage:id,start_time,end_time,resolved_at,restored_at,sla_state')
            ->when($from, fn ($q) => $q->where('assigned_at', '>=', $from))
            ->when($to, fn ($q) => $q->where('assigned_at', '<=', $to))
            ->when($filters['_queue'] ?? null, fn ($q, $v) => $q->where('queue_type', $v))
            ->when($filters['team_ids'] ?? null, fn ($q, $v) => $q->whereIn('team_id', (array) $v))
            ->when($filters['technician_ids'] ?? null, fn ($q, $v) => $q->whereIn('user_id', (array) $v))
            ->whereNotNull($groupColumn);

        foreach ($query->lazyById(500) as $a) {
            $key = $a->{$groupColumn};
            $groups[$key] ??= ['name' => $names[$key] ?? "#{$key}", 'assigned' => 0, 'accepted' => 0, 'rejected' => 0, 'timed_out' => 0, 'completed' => 0, 'breached' => [], 'ack' => [], 'restore' => []];
            $g = &$groups[$key];
            $g['assigned']++;
            if ($a->accepted_at) {
                $g['accepted']++;
                $g['ack'][] = $a->assigned_at->diffInMinutes($a->accepted_at);
            }
            $g['rejected'] += $a->status === AssignmentStatus::Rejected ? 1 : 0;
            $g['timed_out'] += $a->status === AssignmentStatus::TimedOut ? 1 : 0;
            if ($a->status === AssignmentStatus::Completed) {
                $g['completed']++;
                $end = $a->outage?->restored_at ?? $a->outage?->end_time;
                if ($end && $a->outage?->start_time) {
                    $g['restore'][$a->outage_id] = $a->outage->start_time->diffInMinutes($end);
                }
            }
            if (in_array($a->outage?->sla_state, [SlaState::Breached, SlaState::Missed], true)) {
                $g['breached'][$a->outage_id] = true;
            }
            unset($g);
        }

        $avg = fn (array $v) => $v ? (int) round(array_sum($v) / count($v)) : null;

        return collect($groups)->map(fn ($g) => [
            'name' => $g['name'], 'assigned' => $g['assigned'], 'accepted' => $g['accepted'], 'rejected' => $g['rejected'],
            'timed_out' => $g['timed_out'], 'completed' => $g['completed'], 'breached' => count($g['breached']),
            'avg_ack' => $avg($g['ack']), 'avg_restore' => $avg(array_values($g['restore'])),
        ])->sortByDesc('assigned')->values();
    }

    private function recurring(array $filters): ReportResult
    {
        $groups = [];

        foreach ($this->scoped($filters)->lazyById(500, 'outages.id') as $o) {
            $locations = $o->fdts->isNotEmpty()
                ? $o->fdts->map(fn ($f) => ($o->olts->firstWhere('id', $f->olt_id)?->name ?? '?').' › '.$f->name)
                : ($o->olts->isNotEmpty() ? $o->olts->pluck('name') : collect([$o->location ?: null]));

            foreach ($locations->filter() as $location) {
                $groups[$location] ??= ['location' => $location, 'count' => 0, 'customers' => 0, 'first' => null, 'last' => null, 'tickets' => []];
                $g = &$groups[$location];
                $g['count']++;
                $g['customers'] += $o->customers_affected;
                $start = $o->start_time?->format('Y-m-d H:i');
                $g['first'] = $g['first'] ? min($g['first'], $start) : $start;
                $g['last'] = max($g['last'], $start);
                $g['tickets'][] = $o->ticket_number;
                unset($g);
            }
        }

        $rows = collect($groups)->filter(fn ($g) => $g['count'] >= 2)->sortByDesc('count')->map(fn ($g) => [
            'location' => $g['location'], 'count' => $g['count'], 'customers' => $g['customers'],
            'first' => $g['first'], 'last' => $g['last'], 'tickets' => implode(', ', array_slice($g['tickets'], 0, 10)),
        ])->values();

        return $this->fromRows(self::REPORTS['recurring'], [
            'location' => 'Location (OLT › FDT)', 'count' => 'Occurrences', 'customers' => 'Total Customers',
            'first' => 'First', 'last' => 'Most Recent', 'tickets' => 'Tickets',
        ], $rows, description: 'Locations with two or more outages in the selected period.');
    }

    private function assignmentHistory(array $filters): ReportResult
    {
        [$from, $to] = OutageQuery::period($filters);

        $query = fn () => OutageAssignmentHistory::query()
            ->with(['outage:id,ticket_number', 'previousUser:id,name', 'newUser:id,name', 'performer:id,name'])
            ->when($from, fn ($q) => $q->where('performed_at', '>=', $from))
            ->when($to, fn ($q) => $q->where('performed_at', '<=', $to));

        return new ReportResult(self::REPORTS['assignment_history'], [
            'time' => 'Time', 'ticket' => 'Ticket', 'queue' => 'Queue', 'action' => 'Action', 'from' => 'From',
            'to' => 'To', 'reason' => 'Reason', 'by' => 'Performed By', 'trigger' => 'Trigger',
        ], function (?int $limit) use ($query) {
            $q = $query()->orderByDesc('id');
            foreach ($limit ? $q->limit($limit)->get() : $q->lazyByIdDesc(500) as $h) {
                yield [
                    'time' => $h->performed_at?->format('Y-m-d H:i:s'),
                    'ticket' => $h->outage?->ticket_number,
                    'queue' => $h->queue_type?->getLabel(),
                    'action' => str($h->action)->replace('_', ' ')->title()->toString(),
                    'from' => $h->previousUser?->name,
                    'to' => $h->newUser?->name,
                    'reason' => $h->reason,
                    'by' => $h->performer?->name ?? 'System',
                    'trigger' => $h->trigger,
                ];
            }
        }, ['Events' => $query()->count()]);
    }

    private function auditTrail(array $filters): ReportResult
    {
        [$from, $to] = OutageQuery::period($filters);

        $query = fn () => AuditLog::query()
            ->with('user:id,name')
            ->when($from, fn ($q) => $q->where('created_at', '>=', $from))
            ->when($to, fn ($q) => $q->where('created_at', '<=', $to));

        return new ReportResult(self::REPORTS['audit_trail'], [
            'time' => 'Time', 'user' => 'User', 'entity' => 'Record', 'event' => 'Event', 'trigger' => 'Trigger',
            'changes' => 'Changes', 'ip' => 'IP Address',
        ], function (?int $limit) use ($query) {
            $q = $query()->orderByDesc('id');
            foreach ($limit ? $q->limit($limit)->get() : $q->lazyByIdDesc(1000) as $log) {
                yield [
                    'time' => $log->created_at?->format('Y-m-d H:i:s'),
                    'user' => $log->user?->name ?? 'System',
                    'entity' => $log->subjectLabel(),
                    'event' => $log->event,
                    'trigger' => $log->trigger,
                    'changes' => collect($log->new_values ?? $log->old_values ?? [])
                        ->map(fn ($v, $k) => $k.': '.(is_scalar($v) || $v === null ? (string) $v : json_encode($v)))
                        ->take(12)->implode('; '),
                    'ip' => $log->ip_address,
                ];
            }
        }, ['Events' => $query()->count()]);
    }

    // ------------------------------------------------------------------ helpers

    private function scoped(array $filters): Builder
    {
        return OutageQuery::make($filters)
            // 4G NOC tickets have their own reports; '_scope' => 'all' lifts this for cross-vertical ones.
            // Customer tickets and site visits are never network outages: they stay out either way.
            ->when(($filters['_scope'] ?? null) !== 'all', fn (Builder $q) => $q->standard(), fn (Builder $q) => $q->network())
            ->when($filters['_major'] ?? null, fn (Builder $q, $code) => $q->ofMajorType($code))
            ->when($filters['_planned'] ?? null, fn (Builder $q) => $q->whereHas('outageType', fn (Builder $t) => $t->where('is_planned', true)));
    }

    /**
     * @param  (callable(Builder): mixed)|null  $constrain
     * @param  (callable(Outage): array)|null  $extra
     */
    private function simpleOutageReport(string $title, array $filters, ?callable $constrain, array $columns, ?callable $extra = null, array $summary = []): ReportResult
    {
        $query = function () use ($filters, $constrain): Builder {
            $q = $this->scoped($filters);
            if ($constrain) {
                $constrain($q);
            }

            return $q;
        };

        return new ReportResult($title, $columns, function (?int $limit) use ($query, $columns, $extra) {
            $q = $query()->orderByDesc('start_time')->orderByDesc('id');
            foreach ($limit ? $q->limit($limit)->get() : $q->lazyById(500, 'outages.id') as $o) {
                $row = array_merge($this->outageRow($o), $extra ? $extra($o) : []);

                yield array_replace(array_fill_keys(array_keys($columns), null), array_intersect_key($row, $columns));
            }
        }, $summary ?: ['Outages' => $query()->count()]);
    }

    private function fromRows(string $title, array $columns, Collection $rows, ?string $description = null): ReportResult
    {
        return new ReportResult(
            $title,
            $columns,
            fn (?int $limit) => $limit ? $rows->take($limit)->all() : $rows->all(),
            ['Rows' => $rows->count()],
            $description,
        );
    }
}
