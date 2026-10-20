<?php

namespace App\Services\Tickets;

use App\Enums\QueueType;
use App\Enums\SlaState;
use App\Enums\StatusCode;
use App\Models\OutageStatus;
use App\Models\OutageType;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Figures of the customer ticketing module: the live pending picture (SLA per department, OLT and
 * region, issues per OLT, tickets in follow-up) and the day / week / month movement report
 * (brought forward, inflow, closed, closed within SLA, pending by SLA state, reasons for pending,
 * trend). Used by the ticket dashboard and the scheduled ticket e-mails, so both always agree.
 *
 * Definitions (as on the ticket tracker in use): duplicates and cancelled tickets are left out of
 * every figure; "inflow" counts a ticket on the day it was reported; "closed" on the day it was
 * closed, whenever it was reported; "pending" = reported before the moment and not closed by then.
 */
class TicketMetrics
{
    /** @var array<string, int>|null */
    private ?array $statusIds = null;

    public function __construct(private readonly BusinessClock $clock, private readonly Settings $settings) {}

    // ------------------------------------------------------------------ live picture

    /**
     * Every open ticket with where it stands against the SLA.
     *
     * @param  array{kind?: ?string, region_ids?: array, olt_ids?: array}  $filters
     * @return Collection<int, array<string, mixed>>
     */
    public function pending(?CarbonInterface $now = null, array $filters = []): Collection
    {
        $now = CarbonImmutable::instance($now ?? now());
        $waiting = $this->statusId(StatusCode::TicketSiteVisit);

        return $this->filtered($this->detailed(), $filters)
            ->where('o.is_closed', false)
            ->orderBy('o.start_time')
            ->get()
            ->map(function (object $row) use ($now, $waiting): array {
                $minutes = $this->clock->minutesBetween(CarbonImmutable::parse($row->start_time), $now);

                return $this->describe($row) + [
                    'department' => QueueType::tryFrom((string) $row->desk)?->getLabel()
                        ?? ((int) $row->status_id === $waiting ? 'Waiting for the site visit' : 'Not at a desk'),
                    'state' => $this->stateAt($row, $now),
                    'sla_hours' => round($minutes / 60, 1),
                    'tier' => $this->tier($minutes),
                    'in_follow_up' => $row->follow_up_due_at !== null,
                    'followed_up' => $row->followed_up_at !== null,
                ];
            });
    }

    /**
     * Tickets closed in the window, with their SLA result and SLA hours.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function closed(CarbonInterface $from, CarbonInterface $to, array $filters = []): Collection
    {
        return $this->filtered($this->detailed(), $filters)
            ->where('o.is_closed', true)
            ->whereNotIn('o.status_id', $this->voidIds())
            ->whereBetween('o.resolved_at', [$from, $to])
            ->orderByDesc('o.resolved_at')
            ->get()
            ->map(function (object $row): array {
                $end = CarbonImmutable::parse($row->end_time ?? $row->resolved_at);
                $minutes = $this->clock->minutesBetween(CarbonImmutable::parse($row->start_time), $end);
                $target = $row->sla_due_at ? $this->clock->minutesBetween(CarbonImmutable::parse($row->start_time), CarbonImmutable::parse($row->sla_due_at)) : null;

                return $this->describe($row) + [
                    'department' => QueueType::tryFrom((string) $row->home_desk)?->getLabel() ?? '—',
                    'closed_at' => $row->resolved_at,
                    'result' => match ($row->sla_state) {
                        SlaState::Met->value => 'met', SlaState::Missed->value => 'breached', default => 'none',
                    },
                    'sla_hours' => round($minutes / 60, 1),
                    'hours_over' => $row->sla_state === SlaState::Missed->value && $target !== null ? round(max(0, $minutes - $target) / 60, 1) : 0.0,
                    'tier' => $this->tier($minutes),
                    'final_reason' => $row->final_reason,
                ];
            });
    }

    /**
     * Tickets raised (entered into the system) in the window, newest first.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function raised(CarbonInterface $from, CarbonInterface $to, array $filters = []): Collection
    {
        return $this->filtered($this->detailed(), $filters)
            ->whereNotIn('o.status_id', $this->voidIds())
            ->whereBetween('o.created_at', [$from, $to])
            ->orderByDesc('o.id')
            ->get()
            ->map(fn (object $row): array => $this->describe($row) + [
                'department' => QueueType::tryFrom((string) ($row->desk ?? $row->home_desk))?->getLabel() ?? '—',
            ]);
    }

    /**
     * The live picture: headline counts, the cross-tabs and the two lists.
     *
     * @return array<string, mixed>
     */
    public function snapshot(?CarbonInterface $now = null, int $periodHours = 3, array $filters = []): array
    {
        $now = CarbonImmutable::instance($now ?? now());
        $pending = $this->pending($now, $filters);
        $closed = $this->closed($now->subHours(max(1, $periodHours)), $now, $filters);
        $measured = $closed->whereIn('result', ['met', 'breached']);
        // Since midnight, whatever the period of the e-mail.
        $today = $this->closed($now->startOfDay(), $now, $filters);
        $todayMeasured = $today->whereIn('result', ['met', 'breached']);
        $tiers = $this->tierLabels();

        $issues = $pending->countBy('issue')->sortDesc()->keys();
        $top = $issues->take(8)->all();
        $issueOf = fn (array $row) => in_array($row['issue'], $top, true) ? $row['issue'] : 'Other';

        return [
            'now' => $now,
            'period_hours' => $periodHours,
            'counts' => [
                'pending' => $pending->count(),
                'within' => $pending->where('state', 'within')->count(),
                'at_risk' => $pending->where('state', 'at_risk')->count(),
                'breached' => $pending->where('state', 'breached')->count(),
                'in_follow_up' => $pending->where('in_follow_up', true)->count(),
                'unassigned' => $pending->whereNull('assignee')->count(),
                'tickets' => $pending->where('kind', 'ticket')->count(),
                'visits' => $pending->where('kind', 'visit')->count(),
                'closed' => $closed->count(),
                'closed_met' => $measured->where('result', 'met')->count(),
                'closed_breached' => $measured->where('result', 'breached')->count(),
                'closed_pct' => $this->percent($measured->where('result', 'met')->count(), $measured->count()),
                'closed_today' => $today->count(),
                'closed_today_pct' => $this->percent($todayMeasured->where('result', 'met')->count(), $todayMeasured->count()),
            ],
            'by_department' => $this->crosstab($pending, 'department', 'tier', $tiers, 'Department'),
            'by_olt' => $this->crosstab($pending, 'olt', 'tier', $tiers, 'OLT'),
            'by_region' => $this->crosstab($pending, 'region', 'tier', $tiers, 'Region'),
            'state_by_department' => $this->crosstab($pending->map(fn (array $row) => $row + ['state_label' => self::STATES[$row['state']]]), 'department', 'state_label', array_values(self::STATES), 'Department'),
            'issue_by_olt' => $this->crosstab($pending->map(fn (array $row) => ['issue' => $issueOf($row)] + $row), 'olt', 'issue', $issues->count() > 8 ? [...$top, 'Other'] : $top, 'OLT'),
            'follow_up' => $pending->where('in_follow_up', true)->sortByDesc('sla_hours')->values(),
            'oldest' => $pending->sortByDesc('sla_hours')->take(25)->values(),
            'closed' => $closed,
        ];
    }

    public const STATES = ['within' => 'Within SLA', 'at_risk' => 'At risk', 'breached' => 'Breached'];

    // ------------------------------------------------------------------ period report

    /**
     * Day / week / month report: the selected period against the one before it.
     *
     * @param  string  $kind  day | week | month
     * @return array<string, mixed>
     */
    public function period(string $kind, ?CarbonInterface $now = null, array $filters = []): array
    {
        $now = CarbonImmutable::instance($now ?? now());
        [$start, $end, $previousStart, $buckets] = $this->window($kind, $now);
        $rows = $this->light($buckets[0][0], $filters);

        $selected = $this->summarise($rows, $start, $end, $now);
        $previous = $this->summarise($rows, $previousStart, $start, $now);
        $closed = $this->closed($start, $end->subSecond(), $filters);
        $measured = $closed->whereIn('result', ['met', 'breached']);
        $pendingNow = $this->pending($now, $filters);
        $split = fn (string $key) => ['all' => $selected['all'][$key], 'ticket' => $selected['ticket'][$key], 'visit' => $selected['visit'][$key]];
        $change = fn (string $key, bool $percent = false) => $percent
            ? ($selected['all'][$key] !== null && $previous['all'][$key] !== null ? round($selected['all'][$key] - $previous['all'][$key], 1).' pts' : '—')
            : sprintf('%+d', $selected['all'][$key] - $previous['all'][$key]);

        $reasons = collect($selected['pending_rows'])->countBy(fn (array $row) => $row['delay_reason'] ?? 'No reason recorded')->sortDesc();
        $control = DB::table('delay_reasons')->pluck('within_control', 'name');

        return [
            'kind' => $kind,
            'title' => ['day' => 'Daily Ticket Report', 'week' => 'Weekly Ticket Report', 'month' => 'Monthly Ticket Report'][$kind],
            'label' => $this->label($kind, $start, $end, $now),
            'previous_label' => $this->label($kind, $previousStart, $start, $now),
            'start' => $start,
            'end' => $end,
            'headline' => [
                ['Inflow: tickets logged', $previous['all']['inflow'], $selected['all']['inflow'], $change('inflow')],
                ['Closed', $previous['all']['closed'], $selected['all']['closed'], $change('closed')],
                ['Closed within SLA', $previous['all']['met'], $selected['all']['met'], $change('met')],
                ['Closed but breached the SLA', $previous['all']['breached'], $selected['all']['breached'], $change('breached')],
                ['Closed within SLA %', $this->pct($previous['all']['pct']), $this->pct($selected['all']['pct']), $change('pct', true)],
                ['Pending at the end', $previous['all']['pending_end'], $selected['all']['pending_end'], $change('pending_end')],
            ],
            'movement' => [
                ['Pending at the start (brought forward)', ...array_values($split('pending_start'))],
                ['+ Inflow: tickets logged', ...array_values($split('inflow'))],
                ['− Closed', ...array_values($split('closed'))],
                ['   of which reported in the period', ...array_values($split('closed_new'))],
                ['   of which reported before (backlog cleared)', ...array_values($split('closed_backlog'))],
                ['Pending at the end (carried forward)', ...array_values($split('pending_end'))],
                ['Net change in pending', ...array_map(fn ($value) => sprintf('%+d', $value), array_values($split('net')))],
                ['Duplicates / cancelled (not counted anywhere else)', ...array_values($split('void'))],
            ],
            'closed_sla' => [
                ['Closed with an SLA result', $measured->count(), $measured->where('kind', 'ticket')->count(), $measured->where('kind', 'visit')->count()],
                ['Closed within SLA', ...$this->kinds($measured->where('result', 'met'))],
                ['Closed but breached the SLA', ...$this->kinds($measured->where('result', 'breached'))],
                ['Closed within SLA %', ...array_map(fn (string $kind) => $this->pct($this->percent(
                    $this->ofKind($measured, $kind)->where('result', 'met')->count(), $this->ofKind($measured, $kind)->count())), ['all', 'ticket', 'visit'])],
                ['Average SLA hours to close', ...array_map(fn (string $kind) => $this->ofKind($closed, $kind)->isEmpty() ? '—' : round($this->ofKind($closed, $kind)->avg('sla_hours'), 1), ['all', 'ticket', 'visit'])],
                ['Hours lost beyond the SLA', ...array_map(fn (string $kind) => round($this->ofKind($closed, $kind)->sum('hours_over'), 1), ['all', 'ticket', 'visit'])],
            ],
            'pending_sla' => [
                ['Pending tickets', ...array_values($split('pending_end'))],
                ['Within SLA', ...array_values($split('pending_within'))],
                ['At risk', ...array_values($split('pending_at_risk'))],
                ['Already breached the SLA', ...array_values($split('pending_breached'))],
                ['% of pending already breached', ...array_map(fn (string $kind) => $this->pct($this->percent($selected[$kind]['pending_breached'], $selected[$kind]['pending_end'])), ['all', 'ticket', 'visit'])],
            ],
            'reasons' => $reasons->map(fn (int $count, int|string $reason) => [
                $reason, $count, $this->pct($this->percent($count, max(1, $reasons->sum()))),
                $reason === 'No reason recorded' ? '—' : (($control[$reason] ?? false) ? 'Yes' : 'No'),
            ])->values()->all(),
            'regions' => $this->regions($rows, $start, $end, $now),
            'trend' => collect($buckets)->map(function (array $bucket) use ($rows, $now, $kind): array {
                $summary = $this->summarise($rows, $bucket[0], $bucket[1], $now)['all'];

                return [
                    'label' => $bucket[0]->format(['day' => 'D d M', 'week' => '\W\k d M', 'month' => 'M Y'][$kind]),
                    'inflow' => $summary['inflow'], 'closed' => $summary['closed'], 'met' => $summary['met'], 'breached' => $summary['breached'],
                    'pct' => $summary['pct'], 'pending' => $summary['pending_end'],
                ];
            })->all(),
            'by_department' => $this->crosstab($pendingNow, 'department', 'tier', $this->tierLabels(), 'Department'),
        ];
    }

    // ------------------------------------------------------------------ TAT statistics

    /**
     * Average, median and percentile turnaround of a set of closed tickets, with the SLA result
     * and the count per TAT bracket.
     *
     * @param  Collection<int, array<string, mixed>>  $closed  rows of closed()
     * @return array<string, mixed>
     */
    public function tat(Collection $closed): array
    {
        $hours = $closed->pluck('sla_hours')->map(fn ($value) => (float) $value)->sort()->values();
        $measured = $closed->whereIn('result', ['met', 'breached']);
        $percentile = function (float $p) use ($hours): ?float {
            if ($hours->isEmpty()) {
                return null;
            }

            return round($hours[(int) min($hours->count() - 1, max(0, ceil($p * $hours->count()) - 1))], 1);
        };

        return [
            'tickets' => $closed->count(),
            'average' => $hours->isEmpty() ? null : round($hours->avg(), 1),
            'median' => $percentile(0.5),
            'p90' => $percentile(0.9),
            'p95' => $percentile(0.95),
            'longest' => $hours->isEmpty() ? null : round($hours->max(), 1),
            'met' => $measured->where('result', 'met')->count(),
            'breached' => $measured->where('result', 'breached')->count(),
            'met_pct' => $this->percent($measured->where('result', 'met')->count(), $measured->count()),
            'hours_over' => round($closed->sum('hours_over'), 1),
            'tiers' => array_map(fn (string $tier) => $closed->where('tier', $tier)->count(), array_combine($this->tierLabels(), $this->tierLabels())),
        ];
    }

    /** @return list<string> "3 hours", "6 hours", … "Above 72 hours" */
    public function tierLabels(): array
    {
        $limits = $this->tierLimits();

        return [...array_map(fn (int $hours) => "{$hours} hours", $limits), 'Above '.(end($limits) ?: 72).' hours'];
    }

    /** The first bracket whose limit is not less than the SLA time used. */
    public function tier(int $minutes): string
    {
        $limits = $this->tierLimits();

        foreach ($limits as $hours) {
            if ($minutes <= $hours * 60) {
                return "{$hours} hours";
            }
        }

        return 'Above '.(end($limits) ?: 72).' hours';
    }

    // ------------------------------------------------------------------ building blocks

    /**
     * Counts of rows by two attributes, with row and column totals.
     *
     * @param  Collection<int, array<string, mixed>>  $rows
     * @param  list<string>  $columns
     * @return array{heading: string, columns: list<string>, rows: list<array{label: string, cells: list<int>, total: int}>, totals: list<int>, total: int}
     */
    public function crosstab(Collection $rows, string $rowKey, string $columnKey, array $columns, string $heading): array
    {
        $lines = $rows->groupBy(fn (array $row) => (string) ($row[$rowKey] ?? '—'))
            ->map(function (Collection $group, int|string $label) use ($columns, $columnKey): array {
                $counts = $group->countBy($columnKey);

                return ['label' => (string) $label, 'cells' => array_map(fn (string $column) => (int) ($counts[$column] ?? 0), $columns), 'total' => $group->count()];
            })
            ->sortByDesc('total')->values()->all();

        return [
            'heading' => $heading,
            'columns' => $columns,
            'rows' => $lines,
            'totals' => array_map(fn (int $i) => array_sum(array_map(fn (array $line) => $line['cells'][$i], $lines)), array_keys($columns)),
            'total' => $rows->count(),
        ];
    }

    private function base(): Builder
    {
        return DB::table('outages as o')
            ->join('tickets as t', 't.outage_id', '=', 'o.id')
            ->whereIn('o.workflow', OutageType::TICKET_WORKFLOWS);
    }

    private function detailed(): Builder
    {
        return $this->base()
            ->leftJoin('olts', 'olts.id', '=', 't.olt_id')
            ->leftJoin('regions', 'regions.id', '=', 't.region_id')
            ->leftJoin('ticket_subcategories as s', 's.id', '=', 't.subcategory_id')
            ->leftJoin('visit_types as v', 'v.id', '=', 't.visit_type_id')
            ->leftJoin('delay_reasons as d', 'd.id', '=', 't.delay_reason_id')
            ->leftJoin('users as u', 'u.id', '=', 'o.current_assignee_id')
            ->leftJoin('users as tech', 'tech.id', '=', 'o.technician_id')
            ->leftJoin('outage_statuses as st', 'st.id', '=', 'o.status_id')
            ->select([
                'o.id', 'o.ticket_number', 'o.workflow', 'o.status_id', 'o.start_time', 'o.end_time', 'o.resolved_at', 'o.sla_due_at', 'o.sla_pre_breach_at',
                'o.sla_state', 'o.final_reason', 't.account_number', 't.customer_name', 't.desk', 't.home_desk', 't.scheduled_at', 't.follow_up_due_at',
                't.followed_up_at', 't.ticket_type', 'olts.name as olt', 'regions.name as region', 's.name as subcategory', 'v.name as visit_type',
                'd.name as delay_reason', 'u.name as assignee', 'tech.name as technician', 'st.name as status',
            ]);
    }

    private function filtered(Builder $query, array $filters): Builder
    {
        $ids = fn (string $key) => array_values(array_filter(array_map('intval', (array) ($filters[$key] ?? []))));

        return $query
            ->when(($filters['kind'] ?? null) === 'ticket', fn (Builder $q) => $q->where('o.workflow', OutageType::WORKFLOW_CX_TICKET))
            ->when(($filters['kind'] ?? null) === 'visit', fn (Builder $q) => $q->where('o.workflow', OutageType::WORKFLOW_SITE_VISIT))
            ->when($ids('region_ids'), fn (Builder $q, array $v) => $q->whereIn('t.region_id', $v))
            ->when($ids('olt_ids'), fn (Builder $q, array $v) => $q->whereIn('t.olt_id', $v))
            ->when($ids('visit_type_ids'), fn (Builder $q, array $v) => $q->whereIn('t.visit_type_id', $v))
            ->when($ids('team_ids'), fn (Builder $q, array $v) => $q->whereIn('o.team_id', $v))
            ->when($ids('technician_ids'), fn (Builder $q, array $v) => $q->whereIn('o.technician_id', $v));
    }

    private function describe(object $row): array
    {
        return [
            'id' => (int) $row->id,
            'ticket' => $row->ticket_number,
            'kind' => $row->workflow === OutageType::WORKFLOW_SITE_VISIT ? 'visit' : 'ticket',
            'account' => $row->account_number,
            'customer' => $row->customer_name,
            'issue' => $row->subcategory ?? $row->visit_type ?? '—',
            'visit_type' => $row->visit_type,
            'ticket_type' => $row->ticket_type,
            'olt' => $row->olt ?? 'No OLT on file',
            'region' => $row->region ?? 'No region',
            'status' => $row->status,
            'assignee' => $row->assignee,
            'technician' => $row->technician,
            'reported' => $row->start_time,
            'scheduled' => $row->scheduled_at,
            'sla_due' => $row->sla_due_at,
            'delay_reason' => $row->delay_reason,
        ];
    }

    /** within | at_risk | breached, at the given moment. */
    private function stateAt(object $row, CarbonInterface $moment): string
    {
        return match (true) {
            $row->sla_due_at !== null && $moment->greaterThanOrEqualTo(CarbonImmutable::parse($row->sla_due_at)) => 'breached',
            $row->sla_pre_breach_at !== null && $moment->greaterThanOrEqualTo(CarbonImmutable::parse($row->sla_pre_breach_at)) => 'at_risk',
            default => 'within',
        };
    }

    /**
     * Every ticket not closed before $since, with only what the movement figures need.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function light(CarbonInterface $since, array $filters): Collection
    {
        $void = $this->voidIds();
        $declined = $this->statusId(StatusCode::TicketDeclined);

        return $this->filtered($this->base(), $filters)
            ->leftJoin('regions', 'regions.id', '=', 't.region_id')
            ->leftJoin('delay_reasons as d', 'd.id', '=', 't.delay_reason_id')
            ->where(fn (Builder $q) => $q->where('o.is_closed', false)->orWhere('o.resolved_at', '>=', $since)->orWhere('o.start_time', '>=', $since))
            ->orderBy('o.id')
            ->get(['o.id', 'o.workflow', 'o.status_id', 'o.is_closed', 'o.start_time', 'o.resolved_at', 'o.sla_state', 'o.sla_due_at', 'o.sla_pre_breach_at',
                'regions.name as region', 'd.name as delay_reason'])
            ->map(fn (object $row) => [
                'kind' => $row->workflow === OutageType::WORKFLOW_SITE_VISIT ? 'visit' : 'ticket',
                'void' => in_array((int) $row->status_id, $void, true),
                'start' => strtotime((string) $row->start_time),
                // A declined request is closed, but has no SLA result.
                'closed' => $row->is_closed && $row->resolved_at ? strtotime((string) $row->resolved_at) : null,
                'result' => (int) $row->status_id === $declined ? null : match ($row->sla_state) {
                    SlaState::Met->value => 'met', SlaState::Missed->value => 'breached', default => null,
                },
                'due' => $row->sla_due_at ? strtotime((string) $row->sla_due_at) : null,
                'risk' => $row->sla_pre_breach_at ? strtotime((string) $row->sla_pre_breach_at) : null,
                'region' => $row->region ?? 'No region',
                'delay_reason' => $row->delay_reason,
            ]);
    }

    /**
     * Movement of a period [start, end) for all tickets, customer tickets and site visits.
     *
     * @return array<string, mixed>
     */
    private function summarise(Collection $rows, CarbonInterface $start, CarbonInterface $end, CarbonInterface $now): array
    {
        $from = $start->getTimestamp();
        // A period still running is measured up to now.
        $to = min($end->getTimestamp(), $now->getTimestamp() + 1);
        $blank = array_fill_keys(['pending_start', 'inflow', 'closed', 'closed_new', 'closed_backlog', 'met', 'breached', 'pending_end', 'pending_within',
            'pending_at_risk', 'pending_breached', 'void'], 0);
        $out = ['all' => $blank, 'ticket' => $blank, 'visit' => $blank];
        $pending = [];

        foreach ($rows as $row) {
            $in = $row['start'] >= $from && $row['start'] < $to;

            foreach (['all', $row['kind']] as $key) {
                if ($row['void']) {
                    $out[$key]['void'] += $in ? 1 : 0;

                    continue;
                }

                $out[$key]['inflow'] += $in ? 1 : 0;
                $out[$key]['pending_start'] += $row['start'] < $from && ($row['closed'] === null || $row['closed'] >= $from) ? 1 : 0;

                if ($row['closed'] !== null && $row['closed'] >= $from && $row['closed'] < $to) {
                    $out[$key]['closed']++;
                    $out[$key][$in ? 'closed_new' : 'closed_backlog']++;
                    $out[$key]['met'] += $row['result'] === 'met' ? 1 : 0;
                    $out[$key]['breached'] += $row['result'] === 'breached' ? 1 : 0;
                }

                if ($row['start'] < $to && ($row['closed'] === null || $row['closed'] >= $to)) {
                    $out[$key]['pending_end']++;
                    $state = match (true) {
                        $row['due'] !== null && $to >= $row['due'] => 'breached',
                        $row['risk'] !== null && $to >= $row['risk'] => 'at_risk',
                        default => 'within',
                    };
                    $out[$key]['pending_'.$state]++;

                    if ($key === 'all') {
                        $pending[] = $row;
                    }
                }
            }
        }

        foreach ($out as $key => $figures) {
            $out[$key]['net'] = $figures['pending_end'] - $figures['pending_start'];
            $out[$key]['pct'] = $this->percent($figures['met'], $figures['met'] + $figures['breached']);
        }

        return $out + ['pending_rows' => $pending];
    }

    /** @return list<array{0: string, 1: int, 2: int, 3: string, 4: int, 5: int}> region, inflow, closed, closed within SLA %, pending, pending breached */
    private function regions(Collection $rows, CarbonInterface $start, CarbonInterface $end, CarbonInterface $now): array
    {
        return $rows->groupBy('region')->map(function (Collection $group, int|string $region) use ($start, $end, $now): array {
            $summary = $this->summarise($group, $start, $end, $now)['all'];

            return [(string) $region, $summary['inflow'], $summary['closed'], $this->pct($summary['pct']), $summary['pending_end'], $summary['pending_breached']];
        })->filter(fn (array $line) => $line[1] + $line[2] + $line[4] > 0)->sortByDesc(fn (array $line) => $line[4])->values()->all();
    }

    /**
     * The selected period, the one before it, and the trend buckets (14 days / 12 weeks / 12 months).
     * A daily report before 09:00 is about yesterday; weekly and monthly ones about the last full
     * week (Monday–Sunday) and month.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable, 2: CarbonImmutable, 3: list<array{0: CarbonImmutable, 1: CarbonImmutable}>}
     */
    private function window(string $kind, CarbonImmutable $now): array
    {
        [$start, $step, $count] = match ($kind) {
            'week' => [$now->startOfWeek(CarbonInterface::MONDAY)->subWeek(), 'week', 12],
            'month' => [$now->startOfMonth()->subMonthNoOverflow(), 'month', 12],
            default => [$now->hour < 9 ? $now->startOfDay()->subDay() : $now->startOfDay(), 'day', 14],
        };

        $add = fn (CarbonImmutable $moment, int $n) => match ($step) {
            'week' => $moment->addWeeks($n), 'month' => $moment->addMonthsNoOverflow($n), default => $moment->addDays($n),
        };

        $buckets = [];
        for ($i = $count - 1; $i >= 0; $i--) {
            $buckets[] = [$add($start, -$i), $add($start, -$i + 1)];
        }

        return [$start, $add($start, 1), $add($start, -1), $buckets];
    }

    private function label(string $kind, CarbonInterface $start, CarbonInterface $end, CarbonInterface $now): string
    {
        return match ($kind) {
            'week' => $start->format('d M').' – '.$end->subDay()->format('d M Y'),
            'month' => $start->format('F Y'),
            default => $start->format('D d M Y').($end->greaterThan($now) && $start->lessThanOrEqualTo($now) ? ' (to '.$now->format('H:i').')' : ''),
        };
    }

    /** @return list<int> All / customer tickets / site visits */
    private function kinds(Collection $rows): array
    {
        return [$rows->count(), $rows->where('kind', 'ticket')->count(), $rows->where('kind', 'visit')->count()];
    }

    private function ofKind(Collection $rows, string $kind): Collection
    {
        return $kind === 'all' ? $rows : $rows->where('kind', $kind);
    }

    private function percent(int $part, int $whole): ?float
    {
        return $whole > 0 ? round($part / $whole * 100, 1) : null;
    }

    public function pct(?float $value): string
    {
        return $value === null ? '—' : rtrim(rtrim(number_format($value, 1), '0'), '.').'%';
    }

    /** @return list<int> */
    private function tierLimits(): array
    {
        $limits = array_values(array_unique(array_filter(array_map('intval', (array) $this->settings->get('ticketing.sla_tiers')), fn (int $hours) => $hours > 0)));
        sort($limits);

        return $limits ?: [3, 6, 9, 12, 15, 18, 21, 24, 48, 72];
    }

    /** Cancelled and duplicate tickets: left out of every figure. */
    private function voidIds(): array
    {
        return [$this->statusId(StatusCode::TicketCancelled), $this->statusId(StatusCode::TicketDuplicate)];
    }

    private function statusId(StatusCode $code): int
    {
        $this->statusIds ??= OutageStatus::query()->whereNotNull('code')->pluck('id', 'code')->all();

        return (int) ($this->statusIds[$code->value] ?? 0);
    }
}
