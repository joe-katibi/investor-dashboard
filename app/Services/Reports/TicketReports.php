<?php

namespace App\Services\Reports;

use App\Enums\AssignmentStatus;
use App\Enums\QueueType;
use App\Models\AuditLog;
use App\Models\OutageAssignment;
use App\Models\OutageType;
use App\Models\Ticket;
use App\Models\TicketDeskMove;
use App\Models\TicketFollowUp;
use App\Services\Tickets\BusinessClock;
use App\Services\Tickets\TicketMetrics;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Customer ticket and site visit reports: registers, pending list, TAT / SLA brackets, escalation
 * turnaround, people, reasons for delay, follow-ups, credit list, reopened work and assignment
 * fairness. Same ReportResult as the other reports, so the reports page and the CSV / Excel / PDF
 * exports work unchanged. All times are SLA hours on the ticket clock.
 */
class TicketReports
{
    public const REPORTS = [
        'ticket_register' => 'Tickets: Customer Ticket Register',
        'visit_register' => 'Tickets: Site Visit Register',
        'ticket_pending' => 'Tickets: Pending Tickets & SLA',
        'ticket_tat' => 'Tickets: TAT / SLA Brackets',
        'ticket_escalations' => 'Tickets: Escalations by Department',
        'ticket_people' => 'Tickets: Agent / Technician Performance',
        'ticket_delay_reasons' => 'Tickets: Reasons for Delay',
        'ticket_follow_ups' => 'Tickets: Customer Follow-ups',
        'ticket_credit' => 'Tickets: Ready for Credit',
        'ticket_reopened' => 'Tickets: Reopened & Repeat Work',
        'ticket_fairness' => 'Tickets: Assignment Fairness',
        'ticket_audit' => 'Tickets: Audit Trail',
    ];

    public function __construct(
        private readonly TicketMetrics $metrics,
        private readonly BusinessClock $clock,
        private readonly Settings $settings,
    ) {}

    public function run(string $key, array $filters = []): ReportResult
    {
        return match ($key) {
            'ticket_register' => $this->register(self::REPORTS[$key], $filters, OutageType::WORKFLOW_CX_TICKET),
            'visit_register' => $this->register(self::REPORTS[$key], $filters, OutageType::WORKFLOW_SITE_VISIT),
            'ticket_pending' => $this->pending($filters),
            'ticket_tat' => $this->tat($filters),
            'ticket_escalations' => $this->escalations($filters),
            'ticket_people' => $this->people($filters),
            'ticket_delay_reasons' => $this->delayReasons($filters),
            'ticket_follow_ups' => $this->followUps($filters),
            'ticket_credit' => $this->credit($filters),
            'ticket_reopened' => $this->reopened($filters),
            'ticket_fairness' => $this->fairness($filters),
            'ticket_audit' => $this->auditTrail($filters),
            default => throw new InvalidArgumentException("Unknown report [{$key}]."),
        };
    }

    // ------------------------------------------------------------------ registers

    /** Columns of the ticket tracker in use, plus what the system adds. */
    public function registerColumns(bool $visits): array
    {
        $columns = [
            'ticket' => 'Ticket No', 'account' => 'Account Number', 'customer' => 'Customer', 'phone' => 'Contact', 'source' => 'Source', 'region' => 'Region',
            'olt' => 'OLT', 'topology' => 'FDT / Slot / Port', 'category' => 'Category', 'issue' => 'Issue Reported', 'visit_type' => 'Visit Type',
            'ticket_type' => 'Ticket Type', 'priority' => 'Priority', 'department' => 'Department (desk)', 'team' => 'Support Team', 'technician' => 'Technician',
            'assignee' => 'With', 'status' => 'Status', 'reported' => 'Reported at', 'scheduled' => 'Scheduled for', 'assigned' => 'Assigned at',
            'arrived' => 'Arrived at Premise', 'restored' => 'Completed at', 'closed' => 'Closed at', 'closed_by' => 'Closed by', 'escalated_to' => 'Escalated To',
            'escalated_at' => 'Escalated at', 'returned_at' => 'Returned at', 'escalations' => 'Escalations', 'delay_reason' => 'Reason for Delay',
            'final_reason' => 'Final Reason', 'fat' => 'FAT Number', 'follow_up' => 'Follow-up Done', 'follow_up_agent' => 'Follow-up Agent',
            'follow_up_outcome' => 'Follow-up Outcome', 'sla_hours' => 'SLA hrs', 'sla_target' => 'SLA Target hrs', 'sla_status' => 'SLA Status',
            'hours_over' => 'Hrs beyond SLA', 'tier' => 'SLA Tier', 'dispatch_minutes' => 'Minutes to Assign', 'on_premise_minutes' => 'Minutes on Premise',
            'intervention' => 'Team Leader Intervention', 'reschedules' => 'Reschedules', 'reopened' => 'Reopened', 'linked_outage' => 'Linked Outage',
            'reference' => 'Billing / External Ref', 'parent' => 'Raised from Ticket',
        ];

        return $visits
            ? $columns
            : array_diff_key($columns, array_flip(['visit_type', 'team', 'technician', 'scheduled', 'arrived', 'restored', 'fat', 'on_premise_minutes', 'reschedules', 'parent']));
    }

    private function register(string $title, array $filters, string $workflow): ReportResult
    {
        $columns = $this->registerColumns($workflow === OutageType::WORKFLOW_SITE_VISIT);
        $query = fn () => $this->query($filters)->where('o.workflow', $workflow);

        return new ReportResult($title, $columns, function (?int $limit) use ($query, $columns) {
            $q = $query()->orderByDesc('o.start_time')->orderByDesc('o.id');

            foreach ($limit ? $q->limit($limit)->get() : $q->lazy(500) as $row) {
                yield array_replace(array_fill_keys(array_keys($columns), null), array_intersect_key($this->row($row), $columns));
            }
        }, [
            'Tickets' => $query()->count(),
            'Open' => $query()->where('o.is_closed', false)->count(),
            'Closed' => $query()->where('o.is_closed', true)->count(),
            'SLA missed' => $query()->where('o.sla_state', 'missed')->count(),
        ]);
    }

    private function pending(array $filters): ReportResult
    {
        $rows = $this->metrics->pending(now(), $filters)->sortByDesc('sla_hours')->values()->map(fn (array $row) => [
            'ticket' => $row['ticket'], 'kind' => $row['kind'] === 'visit' ? 'Site visit' : 'Customer ticket', 'account' => $row['account'], 'customer' => $row['customer'],
            'issue' => $row['issue'], 'region' => $row['region'], 'olt' => $row['olt'], 'department' => $row['department'], 'assignee' => $row['assignee'] ?? 'Unassigned',
            'status' => $row['status'], 'reported' => $this->time($row['reported']), 'scheduled' => $this->time($row['scheduled']), 'sla_due' => $this->time($row['sla_due']),
            'sla_hours' => $row['sla_hours'], 'tier' => $row['tier'], 'state' => TicketMetrics::STATES[$row['state']], 'delay_reason' => $row['delay_reason'],
            'follow_up' => $row['in_follow_up'] ? ($row['followed_up'] ? 'Called' : 'Due') : null,
        ]);

        return $this->fromRows(self::REPORTS['ticket_pending'], [
            'ticket' => 'Ticket No', 'kind' => 'Kind', 'account' => 'Account Number', 'customer' => 'Customer', 'issue' => 'Issue Reported', 'region' => 'Region',
            'olt' => 'OLT', 'department' => 'Department (desk)', 'assignee' => 'With', 'status' => 'Status', 'reported' => 'Reported at', 'scheduled' => 'Scheduled for',
            'sla_due' => 'SLA Deadline', 'sla_hours' => 'SLA hrs Open', 'tier' => 'SLA Tier', 'state' => 'SLA State', 'delay_reason' => 'Reason for Delay',
            'follow_up' => 'Follow-up',
        ], $rows, 'Every open ticket, longest open first. SLA clock: '.$this->clock->describe().'.', [
            'Pending' => $rows->count(), 'Breached' => $rows->where('state', 'Breached')->count(), 'At risk' => $rows->where('state', 'At risk')->count(),
        ]);
    }

    // ------------------------------------------------------------------ TAT / SLA

    private function tat(array $filters): ReportResult
    {
        [$from, $to] = $this->period($filters);
        $closed = $this->metrics->closed($from, $to, $filters);
        $tiers = $this->metrics->tierLabels();

        $line = function (string $group, string $name, Collection $rows) use ($tiers): array {
            $tat = $this->metrics->tat($rows);

            return [
                'group' => $group, 'name' => $name, 'tickets' => $tat['tickets'], 'average' => $tat['average'], 'median' => $tat['median'], 'p90' => $tat['p90'],
                'p95' => $tat['p95'], 'longest' => $tat['longest'], 'met' => $tat['met'], 'breached' => $tat['breached'],
                'met_pct' => $this->metrics->pct($tat['met_pct']), 'breach_pct' => $tat['met_pct'] === null ? '—' : $this->metrics->pct(round(100 - $tat['met_pct'], 1)),
                'hours_over' => $tat['hours_over'],
            ] + array_combine(array_map(fn (int $i) => "tier_{$i}", array_keys($tiers)), array_values($tat['tiers']));
        };

        $by = fn (string $group, string $key) => $closed->groupBy(fn (array $row) => (string) ($row[$key] ?? '—'))
            ->map(fn (Collection $rows, $name) => $line($group, (string) $name, $rows))->sortByDesc('tickets')->values();

        $rows = collect([$line('All', 'All closed tickets', $closed)])
            ->merge($by('Kind', 'kind')->map(fn (array $row) => ['name' => $row['name'] === 'visit' ? 'Site visits' : 'Customer tickets'] + $row))
            ->merge($by('Visit type', 'visit_type')->reject(fn (array $row) => $row['name'] === '—'))
            ->merge($by('Issue reported', 'issue'))
            ->merge($by('Department', 'department'))
            ->merge($by('Region', 'region'))
            ->merge($by('OLT', 'olt'))
            ->merge($by('Technician', 'technician')->reject(fn (array $row) => $row['name'] === '—'))
            ->merge($by('Ticket type', 'ticket_type'));

        return $this->fromRows(self::REPORTS['ticket_tat'], [
            'group' => 'By', 'name' => 'Name', 'tickets' => 'Closed', 'average' => 'Avg TAT (SLA hrs)', 'median' => 'Median', 'p90' => 'P90', 'p95' => 'P95',
            'longest' => 'Longest', 'met' => 'SLA Met', 'breached' => 'SLA Breached', 'met_pct' => 'SLA Met %', 'breach_pct' => 'Breach %', 'hours_over' => 'Hrs lost beyond SLA',
        ] + array_combine(array_map(fn (int $i) => "tier_{$i}", array_keys($tiers)), $tiers), $rows,
            $from->format('d M Y').' – '.$to->format('d M Y').'. Tickets closed in the period (duplicates and cancelled left out). TAT = reported → completed / closed, in SLA hours ('.$this->clock->describe().'). Brackets count the tickets closed within each limit.');
    }

    /** Escalated → returned, per department the ticket was handed to. */
    private function escalations(array $filters): ReportResult
    {
        [$from, $to] = $this->period($filters);
        $target = $this->settings->int('ticketing.escalation_target_minutes');

        $rows = TicketDeskMove::query()->whereIn('kind', ['route', 'assign'])->whereNotNull('from_desk')->whereBetween('moved_at', [$from, $to])
            ->get(['from_desk', 'to_desk', 'moved_at', 'returned_at'])
            ->groupBy(fn (TicketDeskMove $move) => $move->to_desk->value)
            ->map(function (Collection $moves, string $desk) use ($target): array {
                $minutes = $moves->whereNotNull('returned_at')->map(fn (TicketDeskMove $move) => $this->clock->minutesBetween($move->moved_at, $move->returned_at));

                return [
                    'department' => QueueType::from($desk)->getLabel(),
                    'escalated' => $moves->count(),
                    'returned' => $minutes->count(),
                    'still_out' => $moves->whereNull('returned_at')->count(),
                    'avg_hours' => $minutes->isEmpty() ? null : round($minutes->avg() / 60, 1),
                    'longest_hours' => $minutes->isEmpty() ? null : round($minutes->max() / 60, 1),
                    'within_target' => $minutes->isEmpty() ? '—' : $this->metrics->pct(round($minutes->filter(fn (int $m) => $m <= $target)->count() / $minutes->count() * 100, 1)),
                    'from' => $moves->countBy(fn (TicketDeskMove $move) => $move->from_desk->getLabel())->sortDesc()->map(fn (int $n, string $name) => "{$name} ({$n})")->implode(', '),
                ];
            })->sortByDesc('escalated')->values();

        return $this->fromRows(self::REPORTS['ticket_escalations'], [
            'department' => 'Escalated To', 'escalated' => 'Hand-offs', 'returned' => 'Returned', 'still_out' => 'Not Returned Yet', 'avg_hours' => 'Avg SLA hrs to Return',
            'longest_hours' => 'Longest (SLA hrs)', 'within_target' => 'Within Target %', 'from' => 'Received From',
        ], $rows, $from->format('d M Y').' – '.$to->format('d M Y').'. Turnaround = handed over → returned, in SLA hours. Target: '.round($target / 60, 1).' hours.');
    }

    /** Per person: tickets assigned, time to accept, closed, breached, follow-up calls. */
    private function people(array $filters): ReportResult
    {
        [$from, $to] = $this->period($filters);

        $assignments = OutageAssignment::query()
            ->whereIn('queue_type', array_map(fn (QueueType $desk) => $desk->value, QueueType::ticketing()))
            ->whereBetween('assigned_at', [$from, $to])
            ->with('user:id,name')
            ->get(['id', 'user_id', 'queue_type', 'status', 'assigned_at', 'accepted_at', 'completed_at']);

        $closed = $this->metrics->closed($from, $to, $filters);
        $closedBy = DB::table('outages')->whereIn('id', $closed->pluck('id')->all() ?: [0])->pluck('resolved_by', 'id');
        $calls = TicketFollowUp::query()->whereBetween('called_at', [$from, $to])->selectRaw('user_id, count(*) as aggregate')->groupBy('user_id')->pluck('aggregate', 'user_id');

        $rows = $assignments->groupBy('user_id')->map(function (Collection $mine, int $userId) use ($closed, $closedBy, $calls): array {
            $accept = $mine->whereNotNull('accepted_at')->map(fn (OutageAssignment $a) => max(0, (int) $a->assigned_at->diffInMinutes($a->accepted_at)));
            $handle = $mine->where('status', AssignmentStatus::Completed)->whereNotNull('accepted_at')->whereNotNull('completed_at')
                ->map(fn (OutageAssignment $a) => $this->clock->minutesBetween($a->accepted_at, $a->completed_at));
            $mineClosed = $closed->filter(fn (array $row) => (int) ($closedBy[$row['id']] ?? 0) === $userId);

            return [
                'person' => $mine->first()->user?->name ?? "#{$userId}",
                'desks' => $mine->pluck('queue_type')->unique()->map(fn (QueueType $desk) => $desk->getLabel())->implode(', '),
                'assigned' => $mine->count(),
                'accepted' => $mine->whereNotNull('accepted_at')->count(),
                'rejected' => $mine->where('status', AssignmentStatus::Rejected)->count(),
                'timed_out' => $mine->where('status', AssignmentStatus::TimedOut)->count(),
                'completed' => $mine->where('status', AssignmentStatus::Completed)->count(),
                'avg_accept' => $accept->isEmpty() ? null : (int) round($accept->avg()),
                'avg_handle' => $handle->isEmpty() ? null : round($handle->avg() / 60, 1),
                'closed' => $mineClosed->count(),
                'closed_breached' => $mineClosed->where('result', 'breached')->count(),
                'follow_ups' => (int) ($calls[$userId] ?? 0),
            ];
        })->sortByDesc('assigned')->values();

        return $this->fromRows(self::REPORTS['ticket_people'], [
            'person' => 'Person', 'desks' => 'Desk(s)', 'assigned' => 'Assigned', 'accepted' => 'Accepted', 'rejected' => 'Rejected', 'timed_out' => 'Not Accepted in Time',
            'completed' => 'Steps Completed', 'avg_accept' => 'Avg Minutes to Accept', 'avg_handle' => 'Avg Handling (SLA hrs)', 'closed' => 'Tickets Closed',
            'closed_breached' => 'Closed past SLA', 'follow_ups' => 'Follow-up Calls',
        ], $rows, $from->format('d M Y').' – '.$to->format('d M Y').'. Handling = accepted → step completed. “Tickets closed” counts who actually closed the ticket.');
    }

    private function delayReasons(array $filters): ReportResult
    {
        [$from, $to] = $this->period($filters);
        $closed = $this->metrics->closed($from, $to, $filters);
        $pending = $this->metrics->pending(now(), $filters);
        $control = DB::table('delay_reasons')->pluck('within_control', 'name');
        $reasons = $closed->pluck('delay_reason')->merge($pending->pluck('delay_reason'))->map(fn ($reason) => $reason ?? 'No reason recorded')->unique();

        $rows = $reasons->map(function (string $reason) use ($closed, $pending, $control): array {
            $match = fn (array $row) => ($row['delay_reason'] ?? 'No reason recorded') === $reason;
            $breached = $closed->filter($match)->where('result', 'breached');

            return [
                'reason' => $reason,
                'control' => $reason === 'No reason recorded' ? '—' : (($control[$reason] ?? false) ? 'Yes' : 'No'),
                'closed' => $closed->filter($match)->count(),
                'breached' => $breached->count(),
                'hours_over' => round($breached->sum('hours_over'), 1),
                'pending' => $pending->filter($match)->count(),
                'pending_breached' => $pending->filter($match)->where('state', 'breached')->count(),
            ];
        })->sortByDesc('hours_over')->values();

        return $this->fromRows(self::REPORTS['ticket_delay_reasons'], [
            'reason' => 'Reason for Delay', 'control' => 'Within Our Control?', 'closed' => 'Closed (period)', 'breached' => 'Closed past SLA', 'hours_over' => 'Hrs lost beyond SLA',
            'pending' => 'Pending Now', 'pending_breached' => 'Pending – Breached',
        ], $rows, $from->format('d M Y').' – '.$to->format('d M Y').'. “Within our control = Yes” are the preventable hours. A breached ticket with no reason needs one.');
    }

    private function followUps(array $filters): ReportResult
    {
        [$from, $to] = $this->period($filters);
        $query = fn () => TicketFollowUp::query()->whereBetween('called_at', [$from, $to]);

        return new ReportResult(self::REPORTS['ticket_follow_ups'], [
            'called_at' => 'Called at', 'ticket' => 'Ticket No', 'account' => 'Account Number', 'customer' => 'Customer', 'kind' => 'Call', 'outcome' => 'Outcome',
            'notes' => 'Notes', 'agent' => 'Agent', 'status' => 'Ticket Status',
        ], function (?int $limit) use ($query) {
            $q = $query()->with(['user:id,name', 'outage:id,ticket_number,status_id', 'outage.status:id,name', 'outage.ticket:id,outage_id,account_number,customer_name'])->orderByDesc('called_at')->orderByDesc('id');

            foreach ($limit ? $q->limit($limit)->get() : $q->lazyByIdDesc(500) as $call) {
                yield [
                    'called_at' => $call->called_at?->format('Y-m-d H:i'), 'ticket' => $call->outage?->ticket_number, 'account' => $call->outage?->ticket?->account_number,
                    'customer' => $call->outage?->ticket?->customer_name, 'kind' => TicketFollowUp::KINDS[$call->kind] ?? $call->kind, 'outcome' => $call->outcomeLabel(),
                    'notes' => $call->notes, 'agent' => $call->user?->name, 'status' => $call->outage?->status?->name,
                ];
            }
        }, [
            'Calls' => $query()->count(),
            'Customer reached' => $query()->whereNotIn('outcome', TicketFollowUp::NOT_REACHED)->count(),
            'Not reached' => $query()->whereIn('outcome', TicketFollowUp::NOT_REACHED)->count(),
        ], $from->format('d M Y').' – '.$to->format('d M Y').'.');
    }

    /** Tickets pending past the follow-up time: the credit list. */
    private function credit(array $filters): ReportResult
    {
        [$from, $to] = $this->period($filters);
        // By when the ticket became ready for credit, whenever it was reported.
        $rows = $this->creditRows($this->query(array_diff_key($filters, ['from' => 1, 'to' => 1]))->whereBetween('t.credit_ready_at', [$from, $to])->orderByDesc('t.credit_ready_at')->get());

        return $this->fromRows(self::REPORTS['ticket_credit'], self::CREDIT_COLUMNS, $rows,
            $from->format('d M Y').' – '.$to->format('d M Y').'. Tickets that stayed pending past the follow-up time ('.round($this->settings->int('ticketing.followup_minutes') / 60, 1).' SLA hours).');
    }

    public const CREDIT_COLUMNS = [
        'ticket' => 'Ticket No', 'account' => 'Account Number', 'customer' => 'Customer', 'phone' => 'Contact', 'issue' => 'Issue Reported', 'region' => 'Region',
        'olt' => 'OLT', 'reported' => 'Reported at', 'credit_ready' => 'Ready for Credit since', 'status' => 'Status', 'sla_hours' => 'SLA hrs', 'closed' => 'Closed at',
        'delay_reason' => 'Reason for Delay', 'follow_up' => 'Follow-up Done',
    ];

    /** @param  iterable<object>  $rows  rows of query() */
    public function creditRows(iterable $rows): Collection
    {
        return collect($rows)->map(fn (object $row) => array_intersect_key($this->row($row), self::CREDIT_COLUMNS) + ['credit_ready' => $this->time($row->credit_ready_at)])->values();
    }

    private function reopened(array $filters): ReportResult
    {
        [$from, $to] = $this->period($filters);
        $rows = collect($this->query($filters)->whereBetween('o.start_time', [$from, $to])
            ->where(fn ($q) => $q->where('o.reopen_count', '>', 0)->orWhere('t.ticket_type', 'reactive_repeat')->orWhere('t.reschedule_count', '>', 0))
            ->orderByDesc('o.start_time')->limit(5000)->get())
            ->map(fn (object $row) => $this->row($row) + ['why' => collect([
                $row->reopen_count > 0 ? "Reopened {$row->reopen_count}×" : null,
                $row->ticket_type === 'reactive_repeat' ? 'Repeat fault' : null,
                $row->reschedule_count > 0 ? "Rescheduled {$row->reschedule_count}×" : null,
            ])->filter()->implode(', ')]);

        return $this->fromRows(self::REPORTS['ticket_reopened'], [
            'ticket' => 'Ticket No', 'why' => 'Why Listed', 'account' => 'Account Number', 'customer' => 'Customer', 'issue' => 'Issue Reported', 'region' => 'Region', 'olt' => 'OLT',
            'technician' => 'Technician', 'status' => 'Status', 'reported' => 'Reported at', 'closed' => 'Closed at', 'final_reason' => 'Final Reason', 'sla_status' => 'SLA Status',
        ], $rows, $from->format('d M Y').' – '.$to->format('d M Y').'. Reopened tickets, repeat faults (“Reactive Repeat”) and rescheduled visits reported in the period.', [
            'Rows' => $rows->count(), 'Reopened' => $rows->where('reopened', '>', 0)->count(),
        ]);
    }

    /** Per desk and day: how the assignments were shared between the people working it. */
    private function fairness(array $filters): ReportResult
    {
        [$from, $to] = $this->period($filters);

        $rows = OutageAssignment::query()
            ->whereIn('queue_type', array_map(fn (QueueType $desk) => $desk->value, QueueType::ticketing()))
            ->whereBetween('assigned_at', [$from, $to])
            ->with('user:id,name')
            ->get(['id', 'user_id', 'queue_type', 'assignment_type', 'assigned_at'])
            ->groupBy(fn (OutageAssignment $a) => $a->assigned_at->format('Y-m-d').'|'.$a->queue_type->value)
            ->flatMap(function (Collection $group, string $key): Collection {
                [$day, $desk] = explode('|', $key);
                $people = $group->groupBy('user_id');
                $fair = $group->count() / max(1, $people->count());

                return $people->map(fn (Collection $mine) => [
                    'day' => $day,
                    'desk' => QueueType::from($desk)->getLabel(),
                    'person' => $mine->first()->user?->name,
                    'assigned' => $mine->count(),
                    'automatic' => $mine->filter(fn (OutageAssignment $a) => $a->assignment_type->value === 'auto_fifo')->count(),
                    'manual' => $mine->filter(fn (OutageAssignment $a) => $a->assignment_type->value !== 'auto_fifo')->count(),
                    'share' => round($mine->count() / $group->count() * 100, 1).'%',
                    'fair_share' => round($fair, 1),
                    'difference' => sprintf('%+.1f', $mine->count() - $fair),
                ])->values();
            })->sortBy([['day', 'desc'], ['desk', 'asc'], ['assigned', 'desc']])->values();

        return $this->fromRows(self::REPORTS['ticket_fairness'], [
            'day' => 'Day', 'desk' => 'Desk', 'person' => 'Person', 'assigned' => 'Assigned', 'automatic' => 'By FIFO', 'manual' => 'Manual / Override',
            'share' => 'Share of the Desk', 'fair_share' => 'Even Share', 'difference' => 'Difference',
        ], $rows, $from->format('d M Y').' – '.$to->format('d M Y').'. “Even share” = the day\'s assignments at the desk ÷ the people who received any. Manual assignments are the audited overrides.');
    }

    private function auditTrail(array $filters): ReportResult
    {
        [$from, $to] = $this->period($filters);
        $query = fn () => AuditLog::query()->ticketing()->whereBetween('created_at', [$from, $to]);

        return new ReportResult(self::REPORTS['ticket_audit'], [
            'time' => 'Date / Time', 'user' => 'User', 'record' => 'Ticket / Record', 'action' => 'Action', 'previous_status' => 'Previous Status',
            'status' => 'New Status', 'details' => 'Details / Comment', 'trigger' => 'Trigger', 'ip' => 'IP Address',
        ], function (?int $limit) use ($query) {
            $q = $query()->with('user:id,name')->orderByDesc('id');

            foreach ($limit ? $q->limit($limit)->get() : $q->lazyByIdDesc(1000) as $log) {
                $new = $log->new_values ?? [];
                $action = $log->isTicketAction();

                yield [
                    'time' => $log->created_at?->format('Y-m-d H:i:s'),
                    'user' => $log->user?->name ?? 'System',
                    'record' => $log->subjectLabel(),
                    'action' => $log->eventLabel(),
                    'previous_status' => $action ? ($log->old_values['status'] ?? null) : null,
                    'status' => $action ? ($new['status'] ?? null) : null,
                    'details' => $action ? ($new['details'] ?? $new['comment'] ?? null) : Str::limit(implode('; ', $log->changeLines()), 500),
                    'trigger' => $log->trigger,
                    'ip' => $log->ip_address,
                ];
            }
        }, ['Entries' => $query()->count()], $from->format('d M Y').' – '.$to->format('d M Y').'. Every action on customer tickets and site visits, and every change to customers and the ticketing configuration.');
    }

    // ------------------------------------------------------------------ helpers

    /** One row per ticket with everything the registers show. */
    public function query(array $filters = []): Builder
    {
        [$from, $to] = OutageQuery::period($filters);
        $ids = fn (string $key) => array_values(array_filter(array_map('intval', (array) ($filters[$key] ?? []))));

        return DB::table('outages as o')
            ->join('tickets as t', 't.outage_id', '=', 'o.id')
            ->whereIn('o.workflow', OutageType::TICKET_WORKFLOWS)
            ->leftJoin('olts', 'olts.id', '=', 't.olt_id')
            ->leftJoin('fdts', 'fdts.id', '=', 't.fdt_id')
            ->leftJoin('slots', 'slots.id', '=', 't.slot_id')
            ->leftJoin('ports', 'ports.id', '=', 't.port_id')
            ->leftJoin('regions', 'regions.id', '=', 't.region_id')
            ->leftJoin('ticket_categories as c', 'c.id', '=', 't.category_id')
            ->leftJoin('ticket_subcategories as s', 's.id', '=', 't.subcategory_id')
            ->leftJoin('visit_types as v', 'v.id', '=', 't.visit_type_id')
            ->leftJoin('delay_reasons as d', 'd.id', '=', 't.delay_reason_id')
            ->leftJoin('final_reasons as f', 'f.id', '=', 't.final_reason_id')
            ->leftJoin('priorities as p', 'p.id', '=', 'o.priority_id')
            ->leftJoin('outage_statuses as st', 'st.id', '=', 'o.status_id')
            ->leftJoin('teams as tm', 'tm.id', '=', 'o.team_id')
            ->leftJoin('users as tech', 'tech.id', '=', 'o.technician_id')
            ->leftJoin('users as u', 'u.id', '=', 'o.current_assignee_id')
            ->leftJoin('users as cb', 'cb.id', '=', 'o.resolved_by')
            ->leftJoin('users as fu', 'fu.id', '=', 't.followed_up_by')
            ->leftJoin('users as iv', 'iv.id', '=', 't.intervention_by')
            ->leftJoin('outages as lo', 'lo.id', '=', 't.linked_outage_id')
            ->leftJoin('outages as po', 'po.id', '=', 't.parent_outage_id')
            ->when($from, fn ($q) => $q->where('o.start_time', '>=', $from))
            ->when($to, fn ($q) => $q->where('o.start_time', '<=', $to))
            ->when(($filters['state'] ?? null) === 'active', fn ($q) => $q->where('o.is_closed', false))
            ->when(($filters['state'] ?? null) === 'closed', fn ($q) => $q->where('o.is_closed', true))
            ->when($ids('status_ids'), fn ($q, $v) => $q->whereIn('o.status_id', $v))
            ->when($ids('priority_ids'), fn ($q, $v) => $q->whereIn('o.priority_id', $v))
            ->when($ids('olt_ids'), fn ($q, $v) => $q->whereIn('t.olt_id', $v))
            ->when($ids('region_ids'), fn ($q, $v) => $q->whereIn('t.region_id', $v))
            ->when($ids('visit_type_ids'), fn ($q, $v) => $q->whereIn('t.visit_type_id', $v))
            ->when($ids('team_ids'), fn ($q, $v) => $q->whereIn('o.team_id', $v))
            ->when($ids('technician_ids'), fn ($q, $v) => $q->whereIn('o.technician_id', $v))
            ->when(array_filter((array) ($filters['sla_states'] ?? [])), fn ($q, $v) => $q->whereIn('o.sla_state', $v))
            ->select([
                'o.id', 'o.ticket_number', 'o.workflow', 'o.is_closed', 'o.start_time', 'o.end_time', 'o.resolved_at', 'o.sla_due_at', 'o.sla_state', 'o.final_reason as final_text',
                'o.reopen_count', 't.account_number', 't.customer_name', 't.calling_number', 't.primary_number', 't.source', 't.ticket_type', 't.desk', 't.home_desk',
                't.scheduled_at', 't.first_assigned_at', 't.arrived_at', 't.completed_at', 't.escalated_to', 't.escalated_at', 't.returned_at', 't.escalation_count',
                't.fat_number', 't.followed_up_at', 't.follow_up_outcome', 't.paused_seconds', 't.intervention_seconds', 't.reschedule_count', 't.external_reference',
                't.credit_ready_at', 'olts.name as olt', 'fdts.name as fdt', 'slots.name as slot', 'ports.name as port', 'regions.name as region', 'c.name as category',
                's.name as subcategory', 'v.name as visit_type', 'd.name as delay_reason', 'f.name as final_reason', 'p.name as priority', 'st.name as status',
                'tm.name as team', 'tech.name as technician', 'u.name as assignee', 'cb.name as closed_by', 'fu.name as follow_up_agent', 'iv.name as intervention_by',
                'lo.ticket_number as linked_outage', 'po.ticket_number as parent',
            ]);
    }

    public function row(object $r): array
    {
        $start = CarbonImmutable::parse($r->start_time);
        $end = $r->is_closed ? CarbonImmutable::parse($r->end_time ?? $r->resolved_at ?? now()) : CarbonImmutable::now();
        $minutes = $this->clock->minutesBetween($start, $end);
        $target = $r->sla_due_at ? $this->clock->minutesBetween($start, CarbonImmutable::parse($r->sla_due_at)) : null;
        $desk = fn (?string $value) => QueueType::tryFrom((string) $value)?->getLabel();

        return [
            'id' => (int) $r->id,
            'ticket' => $r->ticket_number,
            'account' => $r->account_number,
            'customer' => $r->customer_name,
            'phone' => $r->calling_number ?: $r->primary_number,
            'source' => Ticket::SOURCES[$r->source] ?? $r->source,
            'region' => $r->region,
            'olt' => $r->olt,
            'topology' => collect([$r->fdt, $r->slot !== null ? 'Slot '.$r->slot : null, $r->port !== null ? 'Port '.$r->port : null])->filter()->implode(' / '),
            'category' => $r->category,
            'issue' => $r->subcategory ?? $r->visit_type,
            'visit_type' => $r->visit_type,
            'ticket_type' => Ticket::TYPES[$r->ticket_type] ?? $r->ticket_type,
            'priority' => $r->priority,
            'department' => $desk($r->desk) ?? $desk($r->home_desk),
            'team' => $r->team,
            'technician' => $r->technician,
            'assignee' => $r->assignee,
            'status' => $r->status,
            'reported' => $this->time($r->start_time),
            'scheduled' => $this->time($r->scheduled_at),
            'assigned' => $this->time($r->first_assigned_at),
            'arrived' => $this->time($r->arrived_at),
            'restored' => $this->time($r->completed_at),
            'closed' => $r->is_closed ? $this->time($r->resolved_at) : null,
            'closed_by' => $r->is_closed ? $r->closed_by : null,
            'escalated_to' => $desk($r->escalated_to),
            'escalated_at' => $this->time($r->escalated_at),
            'returned_at' => $this->time($r->returned_at),
            'escalations' => $r->escalation_count ?: null,
            'delay_reason' => $r->delay_reason,
            'final_reason' => $r->final_reason ?? ($r->is_closed ? Str::limit((string) $r->final_text, 120) : null),
            'fat' => $r->fat_number,
            'follow_up' => $r->followed_up_at ? $this->time($r->followed_up_at) : null,
            'follow_up_agent' => $r->follow_up_agent,
            'follow_up_outcome' => TicketFollowUp::OUTCOMES[$r->follow_up_outcome] ?? $r->follow_up_outcome,
            'sla_hours' => round($minutes / 60, 1),
            'sla_target' => $target !== null ? round($target / 60, 1) : null,
            'sla_status' => match (true) {
                $r->sla_state === 'met' => 'Met',
                $r->sla_state === 'missed' => 'Breached',
                $r->sla_state === 'not_applicable' => 'N/A',
                $target !== null && $minutes >= $target => 'Breached - still open',
                default => 'Within SLA',
            },
            'hours_over' => $target !== null && $minutes > $target && $r->sla_state !== 'not_applicable' ? round(($minutes - $target) / 60, 1) : null,
            'tier' => $this->metrics->tier($minutes),
            'dispatch_minutes' => $r->first_assigned_at ? $this->clock->minutesBetween($start, CarbonImmutable::parse($r->first_assigned_at)) : null,
            'on_premise_minutes' => $r->arrived_at && $r->completed_at
                ? max(0, intdiv(strtotime((string) $r->completed_at) - strtotime((string) $r->arrived_at) - (int) $r->paused_seconds, 60)) : null,
            'intervention' => $r->intervention_by ? $r->intervention_by.' ('.intdiv((int) $r->intervention_seconds, 60).' min)' : null,
            'reschedules' => $r->reschedule_count ?: null,
            'reopened' => $r->reopen_count ?: null,
            'linked_outage' => $r->linked_outage,
            'reference' => $r->external_reference,
            'parent' => $r->parent,
        ];
    }

    private function time(mixed $value): ?string
    {
        return $value ? Carbon::parse($value)->format('Y-m-d H:i') : null;
    }

    /** Defaults to the last 30 days when the report is run without dates. */
    private function period(array $filters): array
    {
        [$from, $to] = OutageQuery::period($filters);

        return [$from ?? now()->subDays(29)->startOfDay(), $to ?? now()->endOfDay()];
    }

    private function fromRows(string $title, array $columns, Collection $rows, string $description, ?array $summary = null): ReportResult
    {
        $rows = $rows->map(fn (array $row) => array_replace(array_fill_keys(array_keys($columns), null), array_intersect_key($row, $columns)))->values();

        return new ReportResult(
            $title,
            $columns,
            fn (?int $limit) => $limit ? $rows->take($limit)->all() : $rows->all(),
            $summary ?? ['Rows' => $rows->count()],
            $description,
        );
    }
}
