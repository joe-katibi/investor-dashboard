<?php

namespace App\Services\Reports;

use App\Enums\QueueType;
use App\Models\Outage;
use App\Models\OutageType;
use App\Models\Vertical;
use App\Services\Tickets\BusinessClock;
use App\Services\Tickets\TicketMetrics;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * 4G customer tickets (CX agent → CX Tier II → 4G NOC → IP / RF / Core team → vendor): what is
 * open and closed, where each open ticket is and with whom, whose desk let the SLA run out, and
 * what the vendors are holding. The Reports page, its exports and the scheduled 4G ticket e-mails
 * (App\Services\Tickets\FourGTicketReportData) all use these figures.
 *
 * A ticket is counted in one state: closed; with the customer call-back desk; with a vendor (at the
 * IP / RF / Core team, vendor called in, no feedback yet); in progress (someone holds it); or open
 * and waiting for someone at its desk (FIFO). "SLA breached": closed past its SLA, or still open past it.
 */
class FourGTicketReports
{
    public const REPORTS = [
        'tkt4g_summary' => '4G Tickets: Summary by Department & Issue',
        'tkt4g_register' => '4G Tickets: Register (Open & Closed)',
        'tkt4g_pending' => '4G Tickets: Pending — With Whom',
        'tkt4g_breaches' => '4G Tickets: SLA Breaches — By Whom',
        'tkt4g_vendors' => '4G Tickets: Vendors',
    ];

    public const STATES = [
        'waiting' => 'Open (waiting)',
        'in_progress' => 'In progress',
        'vendor' => 'With vendor',
        'callback' => 'Customer call-back',
        'closed' => 'Closed',
    ];

    /** The 4G flow, in order: how the departments are listed. */
    public const DESKS = [
        QueueType::CxTier2, QueueType::Ticket4gNoc, QueueType::TicketIp, QueueType::TicketRf, QueueType::TicketCore, QueueType::CxCallback,
    ];

    public function __construct(
        private readonly TicketReports $tickets,
        private readonly DeskSlaReports $deskSla,
        private readonly TicketMetrics $metrics,
        private readonly BusinessClock $clock,
    ) {}

    public function run(string $key, array $filters = []): ReportResult
    {
        [$from, $to] = $this->period($filters);
        $label = $from->format('d M Y').' – '.$to->format('d M Y');

        return match ($key) {
            'tkt4g_summary' => $this->summaryReport($from, $to, $label),
            'tkt4g_register' => $this->register($from, $to, $label, $filters),
            'tkt4g_pending' => $this->pending(),
            'tkt4g_breaches' => $this->breachReport($from, $to, $label),
            'tkt4g_vendors' => $this->vendorReport($from, $to, $label),
        };
    }

    // ------------------------------------------------------------------ the figures

    /**
     * The 4G tickets of a period — raised in it — and every 4G ticket still open, whenever raised.
     */
    public function base(CarbonInterface $from, CarbonInterface $to): Builder
    {
        $lastDesk = DB::table('ticket_desk_moves as m')->select('m.to_desk')->whereColumn('m.outage_id', 'o.id')
            ->orderByDesc('m.moved_at')->orderByDesc('m.id')->limit(1);

        return $this->tickets->query()
            ->where('o.workflow', OutageType::WORKFLOW_CX_TICKET)
            ->where('o.vertical_id', Vertical::idFor(Vertical::MOBILITY_4G) ?? 0)
            ->where(fn ($q) => $q->whereBetween('o.start_time', [$from, $to])->orWhere('o.is_closed', false))
            ->leftJoin('vendors as vnd', 'vnd.id', '=', 'o.vendor_id')
            ->addSelect(['vnd.name as vendor', 't.vendor_notified_at', 't.vendor_feedback_at', 't.desk_since', 'o.current_assignee_id'])
            ->selectSub($lastDesk, 'last_desk');
    }

    /**
     * One ticket, reduced to what the summaries count.
     *
     * @return array<string, mixed>
     */
    public function record(object $r): array
    {
        $desk = QueueType::tryFrom((string) ($r->desk ?: $r->last_desk ?: $r->home_desk));
        $withVendor = ! $r->is_closed && $r->vendor && $r->vendor_notified_at && ! $r->vendor_feedback_at && $desk?->isEngineeringTeam();

        return [
            'id' => (int) $r->id,
            'ticket' => $r->ticket_number,
            'raised' => Carbon::parse($r->start_time),
            'closed' => (bool) $r->is_closed,
            'desk' => $desk,
            'department' => $desk?->getLabel() ?? '—',
            'with' => $r->is_closed ? null : $r->assignee,
            'vendor' => $r->vendor,
            'issue' => $r->subcategory ?? '—',
            'category' => $r->category ?? '—',
            'state' => match (true) {
                (bool) $r->is_closed => 'closed',
                $desk === QueueType::CxCallback => 'callback',
                $withVendor => 'vendor',
                $r->assignee !== null => 'in_progress',
                default => 'waiting',
            },
            'breached' => $r->is_closed
                ? $r->sla_state === 'missed'
                : ($r->sla_state === 'breached' || ($r->sla_due_at && Carbon::parse($r->sla_due_at)->isPast())),
            'desk_since' => $r->desk_since ? Carbon::parse($r->desk_since) : null,
        ];
    }

    /** @return Collection<int, array<string, mixed>> */
    public function records(CarbonInterface $from, CarbonInterface $to): Collection
    {
        return $this->base($from, $to)->get()->map(fn (object $r) => $this->record($r));
    }

    /**
     * Total / per state / breached, one line per group (department, issue, category…).
     *
     * @param  Collection<int, array<string, mixed>>  $records
     * @return list<array<string, mixed>>
     */
    public function crosstab(Collection $records, string $by, ?array $order = null): array
    {
        $groups = $records->groupBy($by);

        if ($order !== null) {
            $groups = collect($order)->mapWithKeys(fn (string $name) => [$name => $groups->get($name, collect())])
                ->merge($groups->except($order))->reject(fn (Collection $rows) => $rows->isEmpty());
        } else {
            $groups = $groups->sortByDesc(fn (Collection $rows) => $rows->count());
        }

        return $groups->map(fn (Collection $rows, string $name) => $this->line($name, $rows))->values()->all();
    }

    /** @param Collection<int, array<string, mixed>> $rows */
    public function line(string $name, Collection $rows): array
    {
        $states = $rows->countBy('state');

        return ['name' => $name, 'total' => $rows->count()]
            + array_map(fn (string $state) => $states->get($state, 0), array_combine(array_keys(self::STATES), array_keys(self::STATES)))
            + ['breached' => $rows->where('breached', true)->count()];
    }

    /** Department names in the order of the flow. */
    public function deskOrder(): array
    {
        return array_map(fn (QueueType $desk) => $desk->getLabel(), self::DESKS);
    }

    /**
     * Open tickets by where they are and with whom: one line per department and person (or
     * "Waiting — nobody yet", or the vendor holding it).
     *
     * @return list<array<string, mixed>>
     */
    public function withWhom(Collection $open): array
    {
        return $open->groupBy(fn (array $r) => $r['department'].'|'.match ($r['state']) {
            'vendor' => 'Vendor: '.$r['vendor'].' (for '.($r['with'] ?? $r['department']).')',
            'waiting' => 'Waiting — nobody yet',
            default => $r['with'] ?? 'Waiting — nobody yet',
        })
            ->map(function (Collection $rows, string $key): array {
                [$department, $with] = explode('|', $key, 2);
                $oldest = $rows->min('raised');

                return [
                    'department' => $department, 'with' => $with, 'open' => $rows->count(), 'breached' => $rows->where('breached', true)->count(),
                    'oldest_hours' => $oldest ? round($oldest->diffInMinutes(now()) / 60, 1) : null,
                    'sort' => array_search($department, $this->deskOrder(), true),
                ];
            })
            ->sortBy([['sort', 'asc'], ['open', 'desc']])->values()->all();
    }

    /**
     * Every SLA breach of the period on a 4G ticket, with the desk and person it happened with —
     * the ticket's own SLA and each desk's target (Configuration → FIFO & SLA Settings).
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function breaches(CarbonInterface $from, CarbonInterface $to): Collection
    {
        $rows = $this->deskSla->breaches($from, $to, ['ticket']);
        $fourG = Outage::query()->cxTickets()->where('vertical_id', Vertical::idFor(Vertical::MOBILITY_4G) ?? 0)
            ->whereIn('ticket_number', $rows->pluck('ticket')->unique()->all())->pluck('ticket_number')->flip();

        return $rows->filter(fn (array $row) => isset($fourG[$row['ticket']]))->values();
    }

    /**
     * Per vendor: tickets called in, still waiting for the vendor, answered, closed, hours to answer.
     *
     * @param  Collection<int, object>  $rows  raw rows of base()
     * @return list<array<string, mixed>>
     */
    public function vendors(Collection $rows): array
    {
        return $rows->filter(fn (object $r) => $r->vendor && $r->vendor_notified_at)->groupBy('vendor')
            ->map(function (Collection $tickets, string $vendor): array {
                $answered = $tickets->filter(fn (object $r) => $r->vendor_feedback_at);
                $hours = $answered->map(fn (object $r) => Carbon::parse($r->vendor_notified_at)->diffInMinutes(Carbon::parse($r->vendor_feedback_at)) / 60);

                return [
                    'vendor' => $vendor,
                    'escalated' => $tickets->count(),
                    'waiting' => $tickets->filter(fn (object $r) => ! $r->is_closed && ! $r->vendor_feedback_at)->count(),
                    'answered' => $answered->count(),
                    'closed' => $tickets->where('is_closed', true)->count(),
                    'avg_hours' => $hours->isEmpty() ? null : round($hours->avg(), 1),
                    'longest_hours' => $hours->isEmpty() ? null : round($hours->max(), 1),
                ];
            })->sortByDesc('escalated')->values()->all();
    }

    // ------------------------------------------------------------------ reports

    private function summaryReport(CarbonInterface $from, CarbonInterface $to, string $label): ReportResult
    {
        $records = $this->records($from, $to);
        $rows = collect([['group' => 'All'] + $this->line('All 4G tickets', $records)])
            ->merge(collect($this->crosstab($records, 'department', $this->deskOrder()))->map(fn (array $row) => ['group' => 'Department'] + $row))
            ->merge(collect($this->crosstab($records, 'issue'))->map(fn (array $row) => ['group' => 'Issue reported'] + $row))
            ->merge(collect($this->crosstab($records, 'category'))->map(fn (array $row) => ['group' => 'Category'] + $row))
            ->map(fn (array $row) => $row + ['breached_pct' => $row['total'] > 0 ? $this->metrics->pct(round($row['breached'] / $row['total'] * 100, 1)) : '—']);

        return $this->fromRows(self::REPORTS['tkt4g_summary'], [
            'group' => 'By', 'name' => 'Name', 'total' => 'Total', 'waiting' => self::STATES['waiting'], 'in_progress' => self::STATES['in_progress'],
            'vendor' => self::STATES['vendor'], 'callback' => self::STATES['callback'], 'closed' => self::STATES['closed'], 'breached' => 'SLA Breached', 'breached_pct' => 'Breached %',
        ], $rows, $label.'. 4G tickets raised in the period and every 4G ticket still open. Department: where an open ticket is now, or where a closed one was closed.', [
            'Tickets' => $records->count(), 'Open' => $records->where('closed', false)->count(), 'Closed' => $records->where('closed', true)->count(),
            'SLA breached' => $records->where('breached', true)->count(),
        ]);
    }

    private function register(CarbonInterface $from, CarbonInterface $to, string $label, array $filters): ReportResult
    {
        $columns = [
            'ticket' => 'Ticket No', 'reported' => 'Reported', 'account' => 'Account Number', 'customer' => 'Customer', 'phone' => 'Phone', 'category' => 'Category',
            'issue' => 'Issue Reported', 'status' => 'Status', 'state' => 'State', 'department' => 'Department (now / closed at)', 'assignee' => 'With',
            'vendor' => 'Vendor', 'vendor_escalated' => 'Vendor Called In', 'vendor_feedback' => 'Vendor Feedback', 'sla_target' => 'SLA Target (hrs)',
            'sla_hours' => 'SLA hrs Open', 'sla_status' => 'SLA Status', 'hours_over' => 'Hrs Beyond SLA', 'closed' => 'Closed', 'closed_by' => 'Closed By',
            'final_reason' => 'Final Reason', 'delay_reason' => 'Reason for Delay', 'escalations' => 'Escalations', 'reference' => 'Billing System Ticket No',
        ];
        $state = $filters['state'] ?? null;
        $query = fn () => $this->base($from, $to)
            ->when($state === 'active', fn ($q) => $q->where('o.is_closed', false))
            ->when($state === 'closed', fn ($q) => $q->where('o.is_closed', true));
        $counts = $query()->get()->map(fn (object $r) => $this->record($r));

        return new ReportResult(self::REPORTS['tkt4g_register'], $columns, function (?int $limit) use ($query, $columns) {
            $q = $query()->orderByDesc('o.start_time')->orderByDesc('o.id');

            foreach ($limit ? $q->limit($limit)->get() : $q->lazy(500) as $r) {
                $record = $this->record($r);
                $row = $this->tickets->row($r) + [
                    'state' => self::STATES[$record['state']], 'vendor' => $r->vendor,
                    'vendor_escalated' => $r->vendor_notified_at ? Carbon::parse($r->vendor_notified_at)->format('Y-m-d H:i') : null,
                    'vendor_feedback' => $r->vendor_feedback_at ? Carbon::parse($r->vendor_feedback_at)->format('Y-m-d H:i') : null,
                ];
                $row['department'] = $record['department'];

                yield array_replace(array_fill_keys(array_keys($columns), null), array_intersect_key($row, $columns));
            }
        }, [
            'Tickets' => $counts->count(), 'Open' => $counts->where('closed', false)->count(), 'Closed' => $counts->where('closed', true)->count(),
            'SLA breached' => $counts->where('breached', true)->count(), 'With vendors' => $counts->where('state', 'vendor')->count(),
        ], $label.'. 4G tickets raised in the period and every 4G ticket still open, newest first. SLA hours: '.$this->clock->describe().'.');
    }

    private function pending(): ReportResult
    {
        $now = CarbonImmutable::now();
        $raw = $this->base($now, $now)->where('o.is_closed', false)->get();
        $rows = $raw->map(function (object $r): array {
            $record = $this->record($r);
            $row = $this->tickets->row($r);

            return [
                'department' => $record['department'],
                'with' => match ($record['state']) {
                    'vendor' => 'Vendor: '.$r->vendor,
                    'waiting' => 'Waiting — nobody yet',
                    default => $r->assignee,
                },
                'person' => $r->assignee,
                'state' => self::STATES[$record['state']],
                'ticket' => $r->ticket_number, 'account' => $r->account_number, 'customer' => $r->customer_name, 'issue' => $r->subcategory,
                'reported' => $row['reported'], 'at_desk_hours' => $record['desk_since'] ? round($record['desk_since']->diffInMinutes(now()) / 60, 1) : null,
                'sla_hours' => $row['sla_hours'], 'sla_target' => $row['sla_target'], 'sla_status' => $row['sla_status'],
                'vendor_since' => $record['state'] === 'vendor' && $r->vendor_notified_at ? Carbon::parse($r->vendor_notified_at)->format('Y-m-d H:i') : null,
                'latest_desk_order' => array_search($record['department'], $this->deskOrder(), true),
                'breached' => $record['breached'],
            ];
        })->sortBy([['latest_desk_order', 'asc'], ['breached', 'desc'], ['sla_hours', 'desc']])->values();

        return $this->fromRows(self::REPORTS['tkt4g_pending'], [
            'department' => 'Department', 'with' => 'Pending With', 'person' => 'Person Holding It', 'state' => 'State', 'ticket' => 'Ticket No', 'account' => 'Account Number',
            'customer' => 'Customer', 'issue' => 'Issue Reported', 'reported' => 'Reported', 'at_desk_hours' => 'Hrs at This Desk', 'sla_hours' => 'SLA hrs Open',
            'sla_target' => 'SLA Target (hrs)', 'sla_status' => 'SLA Status', 'vendor_since' => 'With Vendor Since',
        ], $rows, 'Every open 4G ticket now, department by department in the order of the flow; breached first.', [
            'Open' => $rows->count(), 'Breached' => $rows->where('breached', true)->count(),
            'Waiting for someone' => $rows->where('with', 'Waiting — nobody yet')->count(), 'With vendors' => $rows->where('state', self::STATES['vendor'])->count(),
        ]);
    }

    private function breachReport(CarbonInterface $from, CarbonInterface $to, string $label): ReportResult
    {
        $rows = $this->breaches($from, $to);
        $byDesk = $rows->countBy(fn (array $row) => $row['desk'] ?? '—')->sortDesc();
        $byPerson = $rows->whereNotNull('held_by')->countBy('held_by')->sortDesc();

        return $this->fromRows(self::REPORTS['tkt4g_breaches'], [
            'when' => 'Breached At', 'ticket' => 'Ticket', 'kind' => 'What Was Breached', 'desk' => 'Department', 'held_by' => 'With (person)',
            'target' => 'Target (min)', 'taken' => 'Time Taken (min)', 'over' => 'Over by (min)', 'state' => 'Now', 'status' => 'Ticket Status',
        ], $rows, $label.'. “Ticket SLA”: the ticket\'s SLA ran out while it was with that department and person. “Desk target”: it waited at that department past the department\'s own target.', [
            'Breaches' => $rows->count(),
            'Most at' => $byDesk->isEmpty() ? '—' : $byDesk->keys()->first().' ('.$byDesk->first().')',
            'Most with' => $byPerson->isEmpty() ? '—' : $byPerson->keys()->first().' ('.$byPerson->first().')',
        ]);
    }

    private function vendorReport(CarbonInterface $from, CarbonInterface $to, string $label): ReportResult
    {
        $rows = collect($this->vendors($this->base($from, $to)->get()));

        return $this->fromRows(self::REPORTS['tkt4g_vendors'], [
            'vendor' => 'Vendor', 'escalated' => 'Tickets Called In', 'waiting' => 'Waiting for Vendor Now', 'answered' => 'Feedback Received',
            'closed' => 'Closed', 'avg_hours' => 'Avg Hrs to Feedback', 'longest_hours' => 'Longest (hrs)',
        ], $rows, $label.'. 4G tickets a team called a vendor in on (raised in the period, or still open). Hours = called in → feedback recorded, wall clock.', [
            'Vendors' => $rows->count(), 'Tickets called in' => $rows->sum('escalated'), 'Waiting for vendors now' => $rows->sum('waiting'),
        ]);
    }

    // ------------------------------------------------------------------ helpers

    /** The report's period; the last 30 days when no dates are given. */
    public function period(array $filters): array
    {
        [$from, $to] = OutageQuery::period($filters);

        return [CarbonImmutable::instance($from ?? now()->subDays(29)->startOfDay()), CarbonImmutable::instance($to ?? now()->endOfDay())];
    }

    private function fromRows(string $title, array $columns, Collection $rows, string $description, array $summary): ReportResult
    {
        $rows = $rows->map(fn (array $row) => array_replace(array_fill_keys(array_keys($columns), null), array_intersect_key($row, $columns)))->values();

        return new ReportResult($title, $columns, fn (?int $limit) => $limit ? $rows->take($limit)->all() : $rows->all(), $summary, $description);
    }
}
