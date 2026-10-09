<?php

namespace App\Services\Reports;

use App\Enums\QueueType;
use App\Models\Outage;
use App\Models\OutageQueueEntry;
use App\Models\SlaEvent;
use App\Models\User;
use App\Services\Tickets\BusinessClock;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * SLA per desk — the escalation points of every kind of ticket (outages, 4G, customer tickets,
 * site visits), for the modules the person running the report may view:
 *
 *   desk_sla_performance  per desk: how many tickets it handled, how many within its target, how long they waited
 *   desk_sla_breaches     every breach with the desk it happened at: a desk's own target, the ticket's
 *                         SLA running out while it sat at a desk, and outages not on the field in time
 */
class DeskSlaReports
{
    public const REPORTS = [
        'desk_sla_performance' => 'SLA: Desk Response Times',
        'desk_sla_breaches' => 'SLA: Breaches by Desk (escalation points)',
    ];

    public const KIND_DESK = 'Desk target';

    public const KIND_TICKET = 'Ticket SLA';

    public const KIND_FIELD = 'Time to field';

    public function __construct(private readonly BusinessClock $clock, private readonly ProductivityReport $productivity) {}

    public function run(string $key, array $filters = []): ReportResult
    {
        [$from, $to] = OutageQuery::period($filters);
        $from = CarbonImmutable::instance($from ?? now()->subDays(6)->startOfDay());
        $to = CarbonImmutable::instance($to ?? now()->endOfDay());
        $modules = $this->productivity->modules((array) ($filters['modules'] ?? []));
        $period = $from->format('d M Y H:i').' – '.$to->format('d M Y H:i').' · '.implode(', ', array_map(fn (string $m) => ProductivityReport::MODULES[$m], $modules));

        return match ($key) {
            'desk_sla_performance' => $this->performance($from, $to, $modules, $period),
            'desk_sla_breaches' => $this->breachReport($from, $to, $modules, $period),
            default => throw new InvalidArgumentException("Unknown report [{$key}]."),
        };
    }

    // ------------------------------------------------------------------ desk response times

    /** @param list<string> $modules */
    private function performance(CarbonImmutable $from, CarbonImmutable $to, array $modules, string $period): ReportResult
    {
        $groups = [];
        $now = CarbonImmutable::now();

        foreach ($this->stays($from, $to, $modules) as $entry) {
            $key = $entry->queue_type->value;
            $groups[$key] ??= ['desk' => $entry->queue_type->getLabel(), 'target' => [], 'arrived' => 0, 'handled' => 0, 'waiting' => 0, 'within' => 0, 'breached' => 0, 'waiting_breached' => 0, 'minutes' => []];
            $g = &$groups[$key];
            $end = $entry->completed_at ?? $now;
            $late = $end->greaterThan($entry->sla_due_at);

            $g['target'][$entry->sla_minutes] = true;
            $g['arrived']++;
            $g[$entry->completed_at ? 'handled' : 'waiting']++;
            $g[$late ? 'breached' : 'within'] += $entry->completed_at || $late ? 1 : 0;
            $g['waiting_breached'] += ! $entry->completed_at && $late ? 1 : 0;

            if ($entry->completed_at) {
                $g['minutes'][] = $this->minutes($entry, $end);
            }
            unset($g);
        }

        $rows = collect($groups)->map(function (array $g): array {
            $judged = $g['within'] + $g['breached'];
            sort($g['minutes']);

            return [
                'desk' => $g['desk'],
                'target' => implode(' / ', array_keys($g['target'])),
                'arrived' => $g['arrived'],
                'handled' => $g['handled'],
                'within' => $g['within'],
                'breached' => $g['breached'],
                'compliance' => $judged > 0 ? round($g['within'] / $judged * 100, 1).'%' : 'n/a',
                'average' => $g['minutes'] ? (int) round(array_sum($g['minutes']) / count($g['minutes'])) : null,
                'longest' => $g['minutes'] ? end($g['minutes']) : null,
                'waiting' => $g['waiting'],
                'waiting_breached' => $g['waiting_breached'],
            ];
        })->sortByDesc('breached')->values();

        $within = $rows->sum('within');
        $breached = $rows->sum('breached');

        return new ReportResult(
            self::REPORTS['desk_sla_performance'],
            [
                'desk' => 'Desk', 'target' => 'Target (min)', 'arrived' => 'Tickets Received', 'handled' => 'Handled', 'within' => 'Within Target',
                'breached' => 'Breached', 'compliance' => 'Within Target %', 'average' => 'Avg Minutes at Desk', 'longest' => 'Longest (min)',
                'waiting' => 'Waiting Now', 'waiting_breached' => 'Waiting – Past Target',
            ],
            fn (?int $limit) => $limit ? $rows->take($limit)->all() : $rows->all(),
            [
                'Desks with a target' => $rows->count(),
                'Within target' => $within,
                'Breached' => $breached,
                'Within target %' => $within + $breached > 0 ? round($within / ($within + $breached) * 100, 1).'%' : 'n/a',
                'Waiting past target now' => $rows->sum('waiting_breached'),
            ],
            $period.'. Tickets that arrived at a desk in the period. Time at a desk = arrival → the desk acted (confirmed, dispatched, returned, escalated, closed); '
                .'customer tickets and site visits count working hours. A ticket still waiting counts as breached once it is past the target. Targets: Configuration → FIFO & SLA Settings.',
        );
    }

    // ------------------------------------------------------------------ breaches

    /** @param list<string> $modules */
    private function breachReport(CarbonImmutable $from, CarbonImmutable $to, array $modules, string $period): ReportResult
    {
        $rows = $this->breaches($from, $to, $modules);
        $worst = $rows->whereNotNull('desk')->countBy('desk')->sortDesc();

        $columns = [
            'when' => 'Breached at', 'ticket' => 'Ticket', 'module' => 'Module', 'kind' => 'What Was Breached', 'desk' => 'Desk (escalation point)',
            'held_by' => 'With', 'target' => 'Target (min)', 'taken' => 'Time Taken (min)', 'over' => 'Over by (min)', 'state' => 'Now', 'status' => 'Ticket Status',
        ];

        return new ReportResult(
            self::REPORTS['desk_sla_breaches'],
            $columns,
            fn (?int $limit) => ($limit ? $rows->take($limit) : $rows)->map(fn (array $row) => array_intersect_key($row, $columns))->all(),
            [
                'Breaches' => $rows->count(),
                self::KIND_DESK => $rows->where('kind', self::KIND_DESK)->count(),
                self::KIND_TICKET => $rows->where('kind', self::KIND_TICKET)->count(),
                self::KIND_FIELD => $rows->where('kind', self::KIND_FIELD)->count(),
                'Most breaches at' => $worst->isEmpty() ? '—' : $worst->keys()->first().' ('.$worst->first().')',
            ],
            $period.'. “Desk target”: the ticket waited at that desk past the desk\'s own target. “Ticket SLA”: the ticket\'s SLA ran out while it was at that desk. '
                .'“Time to field”: an outage not dispatched to a field technician in time.',
        );
    }

    /**
     * Every breach of the period, newest first.
     *
     * @param  list<string>  $modules
     * @return Collection<int, array<string, mixed>>
     */
    public function breaches(CarbonInterface $from, CarbonInterface $to, array $modules): Collection
    {
        $from = CarbonImmutable::instance($from);
        $to = CarbonImmutable::instance($to);
        $now = CarbonImmutable::now();
        $rows = collect();

        // 1. A desk's own target.
        foreach ($this->stays($from, $to, $modules, breachedOnly: true) as $entry) {
            $end = $entry->completed_at ?? $now;

            if (! $end->greaterThan($entry->sla_due_at)) {
                continue;
            }

            $taken = $this->minutes($entry, $end);
            $rows->push($this->row($entry->outage, $entry->sla_due_at, self::KIND_DESK, $entry->queue_type,
                $entry->assignments->sortByDesc('id')->first()?->user?->name, $entry->sla_minutes, $taken, max(0, $taken - $entry->sla_minutes),
                $entry->completed_at ? 'Handled late' : 'Still waiting'));
        }

        // 2. The ticket's SLA ran out: reported against the desk it was at.
        $events = SlaEvent::query()->where('event', 'breached')->whereBetween('occurred_at', [$from, $to])
            ->with('outage.status:id,name')->orderByDesc('id')->limit(5000)->get()
            ->filter(fn (SlaEvent $event) => $event->outage && in_array(ProductivityReport::moduleOf($event->outage->workflow), $modules, true));
        $holders = User::query()->whereIn('id', $events->map(fn (SlaEvent $event) => $event->meta['held_by_id'] ?? null)->filter()->unique())->pluck('name', 'id');

        foreach ($events as $event) {
            $outage = $event->outage;
            $end = $outage->end_time ?? $outage->resolved_at ?? $now;
            $target = $outage->sla_due_at ? (int) round($outage->start_time->diffInMinutes($outage->sla_due_at)) : null;

            $rows->push($this->row($outage, $event->occurred_at, self::KIND_TICKET, QueueType::tryFrom((string) ($event->meta['desk'] ?? '')),
                $holders[$event->meta['held_by_id'] ?? 0] ?? null, $target, (int) round($outage->start_time->diffInMinutes($end)),
                $outage->sla_due_at ? max(0, (int) round($outage->sla_due_at->diffInMinutes($end, false))) : null,
                $outage->is_closed ? 'Closed' : 'Still open'));
        }

        // 3. Outages not on the field in time.
        if (in_array('outage', $modules, true)) {
            $late = Outage::query()->standard()->whereNotNull('field_due_at')->whereBetween('field_due_at', [$from, $to])
                ->where(fn ($q) => $q->whereNotNull('field_breached_at')->orWhereColumn('dispatched_at', '>', 'field_due_at'))
                ->with(['status:id,name', 'technician:id,name'])->orderByDesc('field_due_at')->limit(5000)->get();

            foreach ($late as $outage) {
                $end = $outage->dispatched_at ?? ($outage->is_closed ? ($outage->end_time ?? $outage->resolved_at ?? $now) : $now);
                $target = (int) round($outage->created_at->diffInMinutes($outage->field_due_at));
                $taken = (int) round($outage->created_at->diffInMinutes($end));

                $rows->push($this->row($outage, $outage->field_due_at, self::KIND_FIELD, $outage->dispatched_at ? QueueType::Infrastructure : $outage->current_queue,
                    $outage->technician?->name, $target, $taken, max(0, $taken - $target),
                    $outage->dispatched_at ? 'Dispatched late' : ($outage->is_closed ? 'Closed, never dispatched' : 'Not dispatched yet')));
            }
        }

        return $rows->sortByDesc('sort')->values();
    }

    // ------------------------------------------------------------------ helpers

    /**
     * Stays at a desk that carry a target and began in the period.
     *
     * @param  list<string>  $modules
     * @return iterable<OutageQueueEntry>
     */
    private function stays(CarbonImmutable $from, CarbonImmutable $to, array $modules, bool $breachedOnly = false): iterable
    {
        $query = OutageQueueEntry::query()
            ->whereNotNull('sla_due_at')
            ->whereBetween('queued_at', [$from, $to])
            ->when($breachedOnly, fn ($q) => $q->where(fn ($late) => $late->whereNotNull('sla_breached_at')
                ->orWhereColumn('completed_at', '>', 'sla_due_at')
                ->orWhere(fn ($open) => $open->whereNull('completed_at')->where('sla_due_at', '<', now()))))
            ->with(['outage.status:id,name', 'assignments' => fn ($q) => $q->with('user:id,name')]);

        foreach ($query->lazyById(500) as $entry) {
            if ($entry->outage && in_array(ProductivityReport::moduleOf($entry->outage->workflow), $modules, true)) {
                yield $entry;
            }
        }
    }

    /** Minutes the ticket spent at the desk, on the clock its target is counted on. */
    private function minutes(OutageQueueEntry $entry, CarbonInterface $end): int
    {
        $start = $entry->eligible_from && $entry->eligible_from->greaterThan($entry->queued_at) ? $entry->eligible_from : $entry->queued_at;

        return $entry->outage->isTicket()
            ? $this->clock->minutesBetween($start, $end)
            : max(0, intdiv($end->getTimestamp() - $start->getTimestamp(), 60));
    }

    /** @return array<string, mixed> */
    private function row(Outage $outage, CarbonInterface $when, string $kind, ?QueueType $desk, ?string $heldBy, ?int $target, ?int $taken, ?int $over, string $state): array
    {
        return [
            'sort' => $when->getTimestamp(),
            'when' => $when->format('Y-m-d H:i'),
            'ticket' => $outage->ticket_number,
            'module' => ProductivityReport::MODULES[ProductivityReport::moduleOf($outage->workflow)],
            'kind' => $kind,
            'desk' => $desk?->getLabel(),
            'held_by' => $heldBy,
            'target' => $target,
            'taken' => $taken,
            'over' => $over,
            'state' => $state,
            'status' => $outage->status?->name,
        ];
    }
}
