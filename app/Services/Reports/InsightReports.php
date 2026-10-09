<?php

namespace App\Services\Reports;

use App\Enums\SlaState;
use App\Enums\StatusCode;
use App\Models\Outage;
use App\Models\OutageQueueEntry;
use App\Models\OutageStatus;
use App\Models\OutageType;
use App\Models\User;
use App\Services\Tickets\BusinessClock;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Management reports that follow a ticket through its life or sum up why things happened:
 *
 *   ticket_lifecycle      every step of every ticket — raised, each desk it waited at (who had it, how long,
 *                         against the desk's target), closed — for outages, 4G, customer tickets and site visits
 *   ticket_final_reasons  customer tickets and site visits by final reason, day by day for seven days
 *   outage_reasons        network outages by reason for outage (RFO)
 *   olt_performance       per OLT, technician and dispatcher: tickets, SLA met / breached, time to dispatch and to restore
 */
class InsightReports
{
    public const REPORTS = [
        'ticket_lifecycle' => 'Ticket Life Cycle (every step, all modules)',
        'olt_performance' => 'OLT Performance: SLA per Technician & Dispatcher',
        'outage_reasons' => 'Reason for Outage (RFO) Report',
        'ticket_final_reasons' => 'Tickets: Final Reasons (7-day trend)',
    ];

    public const NO_REASON = 'No final reason recorded';

    public function __construct(private readonly BusinessClock $clock, private readonly ProductivityReport $productivity) {}

    /** May this person run the report? Each one follows the modules its figures come from. */
    public static function allows(string $key, User $user): bool
    {
        return match ($key) {
            'outage_reasons' => $user->can('outage.view'),
            'ticket_final_reasons' => $user->can('ticket.view'),
            default => $user->canAny(['outage.view', 'mobility.view', 'ticket.view']),
        };
    }

    public function run(string $key, array $filters = []): ReportResult
    {
        return match ($key) {
            'ticket_lifecycle' => $this->lifecycle($filters),
            'olt_performance' => $this->oltPerformance($filters),
            'outage_reasons' => $this->outageReasons($filters),
            'ticket_final_reasons' => $this->finalReasonReport($filters),
            default => throw new InvalidArgumentException("Unknown report [{$key}]."),
        };
    }

    // ------------------------------------------------------------------ ticket life cycle

    private function lifecycle(array $filters): ReportResult
    {
        [$from, $to] = $this->period($filters);
        $modules = $this->productivity->modules((array) ($filters['modules'] ?? []));

        $query = fn (): Builder => $this->ofModules(Outage::query(), $modules)
            ->whereBetween('start_time', [$from, $to])
            ->when(($filters['state'] ?? null) === 'active', fn (Builder $q) => $q->where('is_closed', false))
            ->when(($filters['state'] ?? null) === 'closed', fn (Builder $q) => $q->where('is_closed', true));

        $columns = [
            'ticket' => 'Ticket', 'module' => 'Module', 'step' => 'Step', 'stage' => 'Stage / Desk', 'arrived' => 'From', 'with' => 'With',
            'accepted' => 'Accepted at', 'left' => 'Until', 'minutes' => 'Minutes', 'target' => 'Desk Target (min)', 'result' => 'Result', 'note' => 'What Happened',
        ];

        return new ReportResult(
            self::REPORTS['ticket_lifecycle'],
            $columns,
            function (?int $limit) use ($query) {
                $sent = 0;
                $tickets = $query()->with([
                    'status:id,name', 'creator:id,name', 'resolvedBy:id,name',
                    'queueEntries' => fn ($q) => $q->orderBy('id')->with(['assignments' => fn ($a) => $a->orderBy('id')->with('user:id,name')]),
                ]);

                foreach ($tickets->lazyByIdDesc(200) as $outage) {
                    foreach ($this->steps($outage) as $row) {
                        yield $row;

                        if ($limit && ++$sent >= $limit) {
                            return;
                        }
                    }
                }
            },
            ['Tickets' => $query()->count(), 'Still open' => $query()->where('is_closed', false)->count()],
            $this->label($from, $to, $modules).'. Tickets raised in the period, newest first, one line per step: raised, every desk the ticket waited at '
                .'(who had it, how long, against that desk\'s target) and how it was closed. Customer tickets and site visits count working hours; the others every minute.',
        );
    }

    /** @return list<array<string, mixed>> */
    private function steps(Outage $outage): array
    {
        $now = CarbonImmutable::now();
        $module = ProductivityReport::MODULES[ProductivityReport::moduleOf($outage->workflow)];
        $line = fn (int $step, string $stage, array $values) => [
            'ticket' => $outage->ticket_number, 'module' => $module, 'step' => $step, 'stage' => $stage,
        ] + $values + ['arrived' => null, 'with' => null, 'accepted' => null, 'left' => null, 'minutes' => null, 'target' => null, 'result' => null, 'note' => null];

        $rows = [$line(0, 'Raised', [
            'arrived' => $outage->created_at?->format('Y-m-d H:i'), 'with' => $outage->creator?->name ?? 'System', 'note' => Str::limit((string) $outage->title, 120),
        ])];

        foreach ($outage->queueEntries as $i => $entry) {
            $start = $this->startOf($entry);
            $end = $entry->completed_at ?? ($outage->is_closed ? ($outage->resolved_at ?? $outage->end_time ?? $now) : $now);
            $last = $entry->assignments->last();
            $late = $entry->sla_due_at && $end->greaterThan($entry->sla_due_at);

            $rows[] = $line($i + 1, $entry->queue_type->getLabel(), [
                'arrived' => $start->format('Y-m-d H:i'),
                'with' => $entry->assignments->map(fn ($a) => $a->user?->name)->filter()->unique()->implode(' → ') ?: 'Nobody (queue)',
                'accepted' => $entry->assignments->pluck('accepted_at')->filter()->last()?->format('Y-m-d H:i'),
                'left' => $entry->completed_at?->format('Y-m-d H:i'),
                'minutes' => $this->between($outage, $start, $end),
                'target' => $entry->sla_minutes,
                'result' => match (true) {
                    ! $entry->sla_due_at => null,
                    $late => $entry->completed_at ? 'Breached' : 'Breached – still waiting',
                    default => $entry->completed_at ? 'Within target' : 'Waiting',
                },
                'note' => Str::limit((string) ($last?->release_reason ?: ($entry->completed_at ? $entry->status->getLabel() : 'Still at this desk')), 160),
            ]);
        }

        if ($outage->is_closed) {
            $end = $outage->resolved_at ?? $outage->end_time ?? $outage->updated_at;

            $rows[] = $line(count($rows), 'Closed', [
                'arrived' => $end?->format('Y-m-d H:i'),
                'with' => $outage->resolvedBy?->name,
                'minutes' => $end ? $this->between($outage, $outage->start_time, $end) : null,
                'result' => $outage->sla_state?->getLabel(),
                'note' => Str::limit(collect([$outage->status?->name, $outage->final_reason])->filter()->implode(': '), 200),
            ]);
        }

        return $rows;
    }

    // ------------------------------------------------------------------ OLT performance

    private function oltPerformance(array $filters): ReportResult
    {
        [$from, $to] = $this->period($filters);
        $modules = $this->productivity->modules((array) ($filters['modules'] ?? []));
        $period = ['from' => $from->toDateTimeString(), 'to' => $to->toDateTimeString()];
        $groups = [];
        $now = CarbonImmutable::now();

        $add = function (string $olt, string $module, ?int $technicianId, ?int $dispatcherId, array $ticket) use (&$groups): void {
            $key = implode('|', [$olt, $module, (int) $technicianId, (int) $dispatcherId]);
            $groups[$key] ??= ['olt' => $olt, 'module' => $module, 'technician_id' => $technicianId, 'dispatcher_id' => $dispatcherId, 'tickets' => 0, 'closed' => 0,
                'met' => 0, 'breached' => 0, 'customers' => 0, 'dispatch' => [], 'restore' => []];
            $g = &$groups[$key];
            $g['tickets']++;
            $g['closed'] += $ticket['closed'] ? 1 : 0;
            $g['met'] += $ticket['sla'] === 'met' ? 1 : 0;
            $g['breached'] += $ticket['sla'] === 'breached' ? 1 : 0;
            $g['customers'] += $ticket['customers'];

            foreach (['dispatch', 'restore'] as $measure) {
                if ($ticket[$measure] !== null) {
                    $g[$measure][] = $ticket[$measure];
                }
            }
        };

        $sla = fn (?string $state, bool $closed, ?string $due) => match (true) {
            $state === SlaState::Met->value => 'met',
            $state === SlaState::Missed->value, $state === SlaState::Breached->value => 'breached',
            ! $closed && $due !== null && $now->greaterThan(CarbonImmutable::parse($due)) => 'breached',
            default => null,
        };

        if (in_array('outage', $modules, true)) {
            $outages = OutageQuery::apply(Outage::query()->standard()->with('olts:id,name'), array_merge($filters, $period));

            foreach ($outages->lazyById(500, 'outages.id') as $o) {
                $end = $o->restored_at ?? $o->end_time ?? $o->resolved_at;
                $ticket = [
                    'closed' => $o->is_closed, 'customers' => (int) $o->customers_affected,
                    'sla' => $sla($o->sla_state?->value, $o->is_closed, $o->sla_due_at?->toDateTimeString()),
                    'dispatch' => $o->dispatched_at ? max(0, (int) round($o->created_at->diffInMinutes($o->dispatched_at))) : null,
                    'restore' => $end ? max(0, (int) round($o->start_time->diffInMinutes($end))) : null,
                ];

                foreach ($o->olts->pluck('name')->all() ?: ['No OLT on the ticket'] as $olt) {
                    $add($olt, 'Outages', $o->technician_id, $o->dispatched_by, $ticket);
                }
            }
        }

        if (in_array('visit', $modules, true)) {
            $ids = fn (string $key) => array_values(array_filter(array_map('intval', (array) ($filters[$key] ?? []))));

            $visits = DB::table('outages as o')->join('tickets as t', 't.outage_id', '=', 'o.id')->leftJoin('olts', 'olts.id', '=', 't.olt_id')
                ->where('o.workflow', OutageType::WORKFLOW_SITE_VISIT)
                ->whereNotIn('o.status_id', $this->voidStatusIds())
                ->whereBetween('o.start_time', [$from, $to])
                ->when($ids('olt_ids'), fn ($q, $v) => $q->whereIn('t.olt_id', $v))
                ->when($ids('technician_ids'), fn ($q, $v) => $q->whereIn('o.technician_id', $v))
                ->orderBy('o.id')
                ->select(['o.id', 'o.is_closed', 'o.sla_state', 'o.sla_due_at', 'o.start_time', 'o.resolved_at', 'o.technician_id', 'o.dispatched_by',
                    't.first_assigned_at', 't.completed_at', 'olts.name as olt']);

            foreach ($visits->lazy(1000) as $v) {
                $start = CarbonImmutable::parse($v->start_time);
                $done = $v->completed_at ?? $v->resolved_at;

                $add($v->olt ?? 'No OLT on the ticket', 'Site visits', $v->technician_id ? (int) $v->technician_id : null, $v->dispatched_by ? (int) $v->dispatched_by : null, [
                    'closed' => (bool) $v->is_closed, 'customers' => 1,
                    'sla' => $sla($v->sla_state, (bool) $v->is_closed, $v->sla_due_at),
                    'dispatch' => $v->first_assigned_at ? $this->clock->minutesBetween($start, CarbonImmutable::parse($v->first_assigned_at)) : null,
                    'restore' => $done ? $this->clock->minutesBetween($start, CarbonImmutable::parse($done)) : null,
                ]);
            }
        }

        $names = User::query()->whereIn('id', collect($groups)->flatMap(fn (array $g) => [$g['technician_id'], $g['dispatcher_id']])->filter()->unique())->pluck('name', 'id');
        $avg = fn (array $values) => $values ? (int) round(array_sum($values) / count($values)) : null;

        $rows = collect($groups)->map(fn (array $g) => [
            'olt' => $g['olt'], 'module' => $g['module'],
            'technician' => $g['technician_id'] ? ($names[$g['technician_id']] ?? '#'.$g['technician_id']) : 'Not assigned',
            'dispatcher' => $g['dispatcher_id'] ? ($names[$g['dispatcher_id']] ?? '#'.$g['dispatcher_id']) : 'Not dispatched / FIFO',
            'tickets' => $g['tickets'], 'closed' => $g['closed'], 'met' => $g['met'], 'breached' => $g['breached'],
            'compliance' => $g['met'] + $g['breached'] > 0 ? round($g['met'] / ($g['met'] + $g['breached']) * 100, 1).'%' : 'n/a',
            'avg_dispatch' => $avg($g['dispatch']), 'avg_restore' => $avg($g['restore']), 'customers' => $g['customers'],
        ])->sortBy([['olt', 'asc'], ['module', 'asc'], ['tickets', 'desc']])->values();

        $met = $rows->sum('met');
        $breached = $rows->sum('breached');

        return new ReportResult(
            self::REPORTS['olt_performance'],
            [
                'olt' => 'OLT', 'module' => 'Work', 'technician' => 'Technician', 'dispatcher' => 'Dispatcher', 'tickets' => 'Tickets', 'closed' => 'Closed',
                'met' => 'Within SLA', 'breached' => 'SLA Breached', 'compliance' => 'SLA Compliance', 'avg_dispatch' => 'Avg Minutes to Dispatch',
                'avg_restore' => 'Avg Minutes to Restore / Complete', 'customers' => 'Customers Affected',
            ],
            fn (?int $limit) => $limit ? $rows->take($limit)->all() : $rows->all(),
            [
                'OLTs' => $rows->pluck('olt')->unique()->count(),
                'Within SLA' => $met,
                'SLA breached' => $breached,
                'SLA compliance' => $met + $breached > 0 ? round($met / ($met + $breached) * 100, 1).'%' : 'n/a',
                'Most breaches' => ($worst = $rows->groupBy('olt')->map->sum('breached')->sortDesc())->first() > 0 ? $worst->keys()->first().' ('.$worst->first().')' : '—',
            ],
            $this->label($from, $to, array_values(array_intersect($modules, ['outage', 'visit']))).'. One line per OLT, technician and dispatcher. Outages: reported → dispatched, start → restored (every minute counts). '
                .'Site visits: reported → first assigned, reported → completed (working hours). An outage on several OLTs counts under each.',
        );
    }

    // ------------------------------------------------------------------ reason for outage

    private function outageReasons(array $filters): ReportResult
    {
        [$from, $to] = $this->period($filters);
        $groups = [];

        $query = OutageQuery::apply(Outage::query()->standard()->with('olts:id,name'), array_merge($filters, ['from' => $from->toDateTimeString(), 'to' => $to->toDateTimeString()]))
            ->where('is_closed', true);

        foreach ($query->lazyById(500, 'outages.id') as $o) {
            $reason = trim((string) preg_replace('/\s+/', ' ', (string) $o->final_reason));
            $confirmed = $o->noc_rejected_at === null;
            $key = Str::lower($reason).'|'.(int) $confirmed;
            $groups[$key] ??= ['reason' => $reason !== '' ? $reason : self::NO_REASON, 'confirmed' => $confirmed, 'outages' => 0, 'customers' => 0, 'minutes' => [],
                'olts' => [], 'last' => null, 'example' => $o->ticket_number];
            $g = &$groups[$key];
            $g['outages']++;
            $g['customers'] += (int) $o->customers_affected;

            if ($confirmed && ($end = $o->restored_at ?? $o->end_time ?? $o->resolved_at)) {
                $g['minutes'][] = max(0, (int) round($o->start_time->diffInMinutes($end)));
            }

            foreach ($o->olts as $olt) {
                $g['olts'][$olt->name] = ($g['olts'][$olt->name] ?? 0) + 1;
            }

            $start = $o->start_time->format('Y-m-d H:i');
            if ($g['last'] === null || $start > $g['last']) {
                $g['last'] = $start;
                $g['example'] = $o->ticket_number;
            }
            unset($g);
        }

        $total = max(1, array_sum(array_column($groups, 'outages')));

        $rows = collect($groups)->map(function (array $g) use ($total): array {
            arsort($g['olts']);

            return [
                'reason' => Str::limit($g['reason'], 200),
                'kind' => $g['confirmed'] ? 'Outage' : 'Not an outage (NOC rejected)',
                'outages' => $g['outages'],
                'share' => round($g['outages'] / $total * 100, 1).'%',
                'customers' => $g['customers'],
                'avg_duration' => $g['minutes'] ? (int) round(array_sum($g['minutes']) / count($g['minutes'])) : null,
                'downtime' => $g['minutes'] ? array_sum($g['minutes']) : null,
                'olts' => collect($g['olts'])->take(4)->map(fn (int $count, string $name) => "{$name} ({$count})")->implode(', '),
                'last' => $g['last'],
                'example' => $g['example'],
            ];
        })->sortByDesc('outages')->values();

        return new ReportResult(
            self::REPORTS['outage_reasons'],
            [
                'reason' => 'Reason for Outage (RFO)', 'kind' => 'Kind', 'outages' => 'Tickets', 'share' => 'Share', 'customers' => 'Customers Affected',
                'avg_duration' => 'Avg Duration (min)', 'downtime' => 'Total Downtime (min)', 'olts' => 'Most Affected OLTs', 'last' => 'Last Occurrence', 'example' => 'Latest Ticket',
            ],
            fn (?int $limit) => $limit ? $rows->take($limit)->all() : $rows->all(),
            [
                'Closed tickets' => $rows->sum('outages'),
                'Different reasons' => $rows->count(),
                'Customers affected' => number_format((int) $rows->sum('customers')),
                'Most common' => $rows->isEmpty() ? '—' : Str::limit((string) $rows->first()['reason'], 60).' ('.$rows->first()['outages'].')',
            ],
            $from->format('d M Y').' – '.$to->format('d M Y').'. Closed FTTH / core / system outages of the period, grouped by the final reason recorded when they were resolved '
                .'(the same wording is counted together). 4G outages: see “4G Root Cause Analysis”.',
        );
    }

    // ------------------------------------------------------------------ final reasons of customer tickets

    private function finalReasonReport(array $filters): ReportResult
    {
        [, $to] = OutageQuery::period($filters);
        $trend = $this->finalReasons($to ?? now(), 7, $filters);
        $columns = ['reason' => 'Final Reason', 'kind' => 'Used On'];

        foreach ($trend['days'] as $i => $day) {
            $columns["d{$i}"] = $day;
        }

        $columns += ['total' => 'Total (7 days)', 'share' => 'Share', 'previous' => 'Previous 7 Days', 'change' => 'Change'];
        $rows = collect($trend['rows']);

        return new ReportResult(
            self::REPORTS['ticket_final_reasons'],
            $columns,
            fn (?int $limit) => $limit ? $rows->take($limit)->all() : $rows->all(),
            [
                'Closed (7 days)' => $trend['total'],
                'Previous 7 days' => $trend['previous'],
                'Most common' => $rows->isEmpty() ? '—' : $rows->first()['reason'].' ('.$rows->first()['total'].')',
                'Rising fastest' => ($rising = $rows->where('delta', '>', 0)->sortByDesc('delta')->first()) ? $rising['reason'].' ('.$rising['change'].')' : '—',
            ],
            'The seven days up to '.$trend['until'].' (the “To” date). Customer tickets and site visits closed on each day, by the final reason chosen on closing, '
                .'against the seven days before. Cancelled and duplicate tickets are left out.',
        );
    }

    /**
     * Tickets closed per final reason on each of the last $days days, with the window before for comparison.
     *
     * @param  array{kind?: ?string, region_ids?: array, olt_ids?: array}  $filters
     * @return array{days: list<string>, until: string, total: int, previous: int, rows: list<array<string, mixed>>}
     */
    public function finalReasons(CarbonInterface $until, int $days = 7, array $filters = []): array
    {
        $end = CarbonImmutable::instance($until)->endOfDay();
        $start = $end->subDays($days - 1)->startOfDay();
        $before = $start->subDays($days);
        $ids = fn (string $key) => array_values(array_filter(array_map('intval', (array) ($filters[$key] ?? []))));

        $closed = DB::table('outages as o')->join('tickets as t', 't.outage_id', '=', 'o.id')->leftJoin('final_reasons as f', 'f.id', '=', 't.final_reason_id')
            ->whereIn('o.workflow', OutageType::TICKET_WORKFLOWS)
            ->where('o.is_closed', true)
            ->whereNotIn('o.status_id', $this->voidStatusIds())
            ->whereBetween('o.resolved_at', [$before, $end])
            ->when(($filters['kind'] ?? null) === 'ticket', fn ($q) => $q->where('o.workflow', OutageType::WORKFLOW_CX_TICKET))
            ->when(($filters['kind'] ?? null) === 'visit', fn ($q) => $q->where('o.workflow', OutageType::WORKFLOW_SITE_VISIT))
            ->when($ids('region_ids'), fn ($q, $v) => $q->whereIn('t.region_id', $v))
            ->when($ids('olt_ids'), fn ($q, $v) => $q->whereIn('t.olt_id', $v))
            ->get(['o.resolved_at', 'o.workflow', 'f.name as reason']);

        $dayKeys = collect(range(0, $days - 1))->map(fn (int $i) => $start->addDays($i)->format('Y-m-d'))->all();
        $groups = [];

        foreach ($closed as $row) {
            $reason = $row->reason ?? self::NO_REASON;
            $at = CarbonImmutable::parse($row->resolved_at);
            $groups[$reason] ??= ['days' => array_fill_keys($dayKeys, 0), 'previous' => 0, 'kinds' => []];

            if ($at->lessThan($start)) {
                $groups[$reason]['previous']++;
            } else {
                $groups[$reason]['days'][$at->format('Y-m-d')]++;
                $groups[$reason]['kinds'][$row->workflow === OutageType::WORKFLOW_SITE_VISIT ? 'Site visits' : 'Customer tickets'] = true;
            }
        }

        $total = array_sum(array_map(fn (array $g) => array_sum($g['days']), $groups));

        $rows = collect($groups)->map(function (array $g, string $reason) use ($total): array {
            $sum = array_sum($g['days']);
            $delta = $sum - $g['previous'];
            $row = ['reason' => $reason, 'kind' => implode(' + ', array_keys($g['kinds'])) ?: '—'];

            foreach (array_values($g['days']) as $i => $count) {
                $row["d{$i}"] = $count;
            }

            return $row + [
                'total' => $sum,
                'share' => $total > 0 ? round($sum / $total * 100, 1).'%' : '0%',
                'previous' => $g['previous'],
                'change' => ($delta > 0 ? '+' : '').$delta,
                'delta' => $delta,
            ];
        })->sortBy([['total', 'desc'], ['previous', 'desc']])->values()->all();

        return [
            'days' => collect($dayKeys)->map(fn (string $day) => CarbonImmutable::parse($day)->format('D d M'))->all(),
            'until' => $end->format('d M Y'),
            'total' => $total,
            'previous' => array_sum(array_column($groups, 'previous')),
            'rows' => $rows,
        ];
    }

    // ------------------------------------------------------------------ helpers

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} */
    private function period(array $filters): array
    {
        [$from, $to] = OutageQuery::period($filters);

        return [CarbonImmutable::instance($from ?? now()->subDays(6)->startOfDay()), CarbonImmutable::instance($to ?? now()->endOfDay())];
    }

    /** @param list<string> $modules */
    private function label(CarbonInterface $from, CarbonInterface $to, array $modules): string
    {
        return $from->format('d M Y H:i').' – '.$to->format('d M Y H:i').' · '.implode(', ', array_map(fn (string $m) => ProductivityReport::MODULES[$m], $modules));
    }

    /** @param list<string> $modules */
    private function ofModules(Builder $query, array $modules): Builder
    {
        return $query->where(function (Builder $q) use ($modules): void {
            $q->whereRaw('1 = 0');

            foreach ($modules as $module) {
                match ($module) {
                    'outage' => $q->orWhereNull('workflow'),
                    'mobility' => $q->orWhereIn('workflow', OutageType::MOBILITY_WORKFLOWS),
                    'ticket' => $q->orWhere('workflow', OutageType::WORKFLOW_CX_TICKET),
                    'visit' => $q->orWhere('workflow', OutageType::WORKFLOW_SITE_VISIT),
                    default => null,
                };
            }
        });
    }

    private function startOf(OutageQueueEntry $entry): CarbonInterface
    {
        return $entry->eligible_from && $entry->eligible_from->greaterThan($entry->queued_at) ? $entry->eligible_from : $entry->queued_at;
    }

    /** Minutes between two moments on the clock the ticket's SLA is counted on. */
    private function between(Outage $outage, CarbonInterface $from, CarbonInterface $to): int
    {
        return $outage->isTicket() ? $this->clock->minutesBetween($from, $to) : max(0, intdiv($to->getTimestamp() - $from->getTimestamp(), 60));
    }

    /** @return list<int> statuses of tickets that never went ahead (cancelled, duplicate) */
    private function voidStatusIds(): array
    {
        return OutageStatus::query()->whereIn('code', [StatusCode::TicketCancelled->value, StatusCode::TicketDuplicate->value])->pluck('id')->all() ?: [0];
    }
}
