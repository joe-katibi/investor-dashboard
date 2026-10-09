<?php

namespace App\Services\Reports;

use App\Enums\AssignmentStatus;
use App\Enums\StatusCode;
use App\Models\OutageStatus;
use App\Models\OutageType;
use App\Models\User;
use App\Services\Tickets\TicketTables;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Staff productivity across every module — outages (FTTH / core / system), 4G outages, customer
 * tickets and site visits — per person, per hour / day / week / month:
 *
 *   created      tickets the person raised
 *   assigned     tickets FIFO or a manager gave them (any queue / desk)
 *   accepted     … they accepted, and how long that took
 *   steps done   queue steps they completed (NOC review, dispatch, technician work, a desk's work…),
 *                with the time to handle it (assigned → done) and its total
 *   rejected     assignments they rejected or let time out
 *   closed       tickets they closed (resolved / verified / closed), and how long those had been open
 *   escalated    tickets they handed on as an escalation (another desk, IT → NOC, 4G "Escalate")
 *   field work   restorations reported / visits completed, and time on the customer premises
 *   calls        customer calls logged · updates: progress updates posted
 *
 * "Tickets worked" counts each ticket once per person and period, whatever they did on it;
 * close rate and escalation rate are closed ÷ worked and escalated ÷ worked.
 */
class ProductivityReport
{
    public const KEY = 'user_productivity';

    public const MODULES = [
        'outage' => 'Outages (FTTH / core / system)',
        'mobility' => '4G outages',
        'ticket' => 'Customer tickets',
        'visit' => 'Site visits',
        'custom' => 'Custom workflows',
    ];

    public const GROUPS = ['hour' => 'Hourly', 'day' => 'Daily', 'week' => 'Weekly', 'month' => 'Monthly', 'period' => 'Whole period'];

    public const COLUMNS = [
        'period' => 'Period', 'person' => 'Person', 'roles' => 'Role(s)', 'module' => 'Module', 'created' => 'Created', 'assigned' => 'Assigned',
        'accepted' => 'Accepted', 'rejected' => 'Rejected / Not Accepted in Time', 'steps' => 'Steps Done', 'worked' => 'Tickets Worked', 'closed' => 'Closed',
        'escalated' => 'Escalated', 'field' => 'Field Work (restored / visits completed)', 'calls' => 'Customer Calls', 'updates' => 'Updates Posted',
        'avg_accept' => 'Avg Minutes to Accept', 'avg_handle' => 'Avg Time to Handle (min, assigned → done)', 'handle_hours' => 'Total Handling (hrs)',
        'avg_close' => 'Avg Age at Closing (hrs)', 'on_site_hours' => 'Time on Premises (hrs)', 'close_rate' => 'Close Rate', 'escalation_rate' => 'Escalation Rate',
    ];

    /** Scheduled e-mails (report type => period). */
    public const EMAIL_TYPES = [
        'productivity' => 'rolling', 'productivity_day' => 'day', 'productivity_week' => 'week', 'productivity_month' => 'month',
    ];

    public const PLACEHOLDERS = [
        'report_time', 'report_date', 'productivity_period', 'productivity_people', 'productivity_worked', 'productivity_closed',
        'productivity_escalated', 'productivity_module_table', 'productivity_table', 'productivity_field_table',
    ];

    /** @var array<int, string> */
    private array $voidStatusIds = [];

    // ------------------------------------------------------------------ report page

    /**
     * @param  array{from?: mixed, to?: mixed, group?: ?string, modules?: array, user_ids?: array}  $filters
     */
    public function run(array $filters = []): ReportResult
    {
        [$from, $to] = OutageQuery::period($filters);
        $from = CarbonImmutable::instance($from ?? now()->subDays(6)->startOfDay());
        $to = CarbonImmutable::instance($to ?? now()->endOfDay());
        $group = array_key_exists($filters['group'] ?? '', self::GROUPS) ? $filters['group'] : 'day';
        $modules = $this->modules($filters['modules'] ?? []);
        $rows = $this->rows($from, $to, $group, $modules, (array) ($filters['user_ids'] ?? []));
        $people = $rows->where('module', '!=', 'All modules');
        $lines = $rows->map(fn (array $row) => array_replace(array_fill_keys(array_keys(self::COLUMNS), null), array_intersect_key($row, self::COLUMNS)))->values();

        return new ReportResult(
            'Staff Productivity (all modules)',
            self::COLUMNS,
            fn (?int $limit) => $limit ? $lines->take($limit)->all() : $lines->all(),
            [
                'People' => $people->pluck('person')->unique()->count(),
                'Tickets created' => $people->sum('created'),
                'Tickets worked' => $people->sum('worked'),
                'Closed' => $people->sum('closed'),
                'Escalated' => $people->sum('escalated'),
                'Field work' => $people->sum('field'),
            ],
            $from->format('d M Y H:i').' – '.$to->format('d M Y H:i').' · '.self::GROUPS[$group].' · '.implode(', ', array_map(fn (string $m) => self::MODULES[$m], $modules))
                .'. Time to handle = assigned → step done (wall clock). Close / escalation rate = closed / escalated ÷ tickets worked. “All modules” rows add up a person\'s modules.',
        );
    }

    // ------------------------------------------------------------------ figures

    /**
     * One row per period, person and module (plus an "All modules" row for people who worked in more than one).
     *
     * @param  list<string>  $modules
     * @param  array<int, int|string>  $userIds
     * @return Collection<int, array<string, mixed>>
     */
    public function rows(CarbonInterface $from, CarbonInterface $to, string $group, array $modules, array $userIds = []): Collection
    {
        $from = CarbonImmutable::instance($from);
        $to = CarbonImmutable::instance($to);
        $userIds = array_values(array_filter(array_map('intval', $userIds)));
        $this->voidStatusIds = OutageStatus::query()->whereIn('code', [
            StatusCode::TicketCancelled->value, StatusCode::TicketDuplicate->value, StatusCode::MobilityCancelled->value, StatusCode::MaintenanceCancelled->value,
        ])->pluck('id')->all();

        $cells = [];
        $in = fn ($moment) => $moment !== null && ($t = strtotime((string) $moment)) >= $from->getTimestamp() && $t <= $to->getTimestamp();
        $cell = function (int $userId, string $module, mixed $moment) use (&$cells, $group, $from, $to): array {
            [$key, $label] = $this->bucket(CarbonImmutable::parse($moment), $group, $from, $to);
            $id = "{$key}|{$userId}|{$module}";

            return [$id, $cells[$id] ??= ['key' => $key, 'period' => $label, 'user_id' => $userId, 'module' => $module] + $this->blank()];
        };
        $add = function (int $userId, string $module, mixed $moment, array $values, ?int $ticketId = null) use (&$cells, $cell): void {
            [$id] = $cell($userId, $module, $moment);

            foreach ($values as $field => $value) {
                $cells[$id][$field] += $value;
            }

            if ($ticketId) {
                $cells[$id]['tickets'][$ticketId] = true;
            }
        };

        // 1. FIFO assignments: assigned, accepted, steps done, rejected / timed out.
        $this->scoped(DB::table('outage_assignments as a')->join('outages as o', 'o.id', '=', 'a.outage_id'), $modules, 'a.user_id', $userIds)
            ->where(fn (Builder $q) => $q->whereBetween('a.assigned_at', [$from, $to])->orWhereBetween('a.accepted_at', [$from, $to])
                ->orWhereBetween('a.completed_at', [$from, $to])->orWhereBetween('a.rejected_at', [$from, $to])->orWhereBetween('a.timed_out_at', [$from, $to]))
            ->orderBy('a.id')
            ->select(['a.user_id', 'a.outage_id', 'a.status', 'a.assigned_at', 'a.accepted_at', 'a.completed_at', 'a.rejected_at', 'a.timed_out_at', 'o.workflow'])
            ->lazy(1000)
            ->each(function (object $a) use ($in, $add): void {
                $module = self::moduleOf($a->workflow);

                if ($in($a->assigned_at)) {
                    $add((int) $a->user_id, $module, $a->assigned_at, ['assigned' => 1]);
                }

                if ($in($a->accepted_at)) {
                    $add((int) $a->user_id, $module, $a->accepted_at, ['accepted' => 1, 'accept_minutes' => $this->minutes($a->assigned_at, $a->accepted_at)], (int) $a->outage_id);
                }

                if ($a->status === AssignmentStatus::Completed->value && $in($a->completed_at)) {
                    $add((int) $a->user_id, $module, $a->completed_at, [
                        'steps' => 1, 'handle_minutes' => $this->minutes($a->assigned_at, $a->completed_at),
                    ], (int) $a->outage_id);
                }

                $refused = $a->status === AssignmentStatus::Rejected->value ? $a->rejected_at : ($a->status === AssignmentStatus::TimedOut->value ? $a->timed_out_at : null);
                if ($in($refused)) {
                    $add((int) $a->user_id, $module, $refused, ['rejected' => 1]);
                }
            });

        // 2. Tickets raised.
        $this->scoped(DB::table('outages as o'), $modules, 'o.created_by', $userIds)
            ->whereNotNull('o.created_by')->whereBetween('o.created_at', [$from, $to])
            ->select(['o.created_by', 'o.created_at', 'o.workflow'])->orderBy('o.id')->lazy(1000)
            ->each(fn (object $o) => $add((int) $o->created_by, self::moduleOf($o->workflow), $o->created_at, ['created' => 1]));

        // 3. Tickets closed (cancelled / duplicates are not work done), with how long they had been open.
        $this->scoped(DB::table('outages as o'), $modules, 'o.resolved_by', $userIds)
            ->whereNotNull('o.resolved_by')->where('o.is_closed', true)->whereBetween('o.resolved_at', [$from, $to])
            ->whereNotIn('o.status_id', $this->voidStatusIds ?: [0])
            ->select(['o.id', 'o.resolved_by', 'o.resolved_at', 'o.start_time', 'o.workflow'])->orderBy('o.id')->lazy(1000)
            ->each(fn (object $o) => $add((int) $o->resolved_by, self::moduleOf($o->workflow), $o->resolved_at, [
                'closed' => 1, 'close_minutes' => $this->minutes($o->start_time, $o->resolved_at),
            ], (int) $o->id));

        // 4. Escalations: hand-offs to another desk, IT → NOC, the 4G "Escalate".
        $this->scoped(DB::table('ticket_desk_moves as m')->join('outages as o', 'o.id', '=', 'm.outage_id'), $modules, 'm.moved_by', $userIds)
            ->where('m.kind', 'route')->whereNotNull('m.from_desk')->whereNotNull('m.moved_by')->whereBetween('m.moved_at', [$from, $to])
            ->select(['m.moved_by', 'm.moved_at', 'm.outage_id', 'o.workflow'])->orderBy('m.id')->lazy(1000)
            ->each(fn (object $m) => $add((int) $m->moved_by, self::moduleOf($m->workflow), $m->moved_at, ['escalated' => 1], (int) $m->outage_id));

        foreach ([['o.escalated_by', 'o.escalated_at'], ['o.it_escalated_by', 'o.it_escalated_to_noc_at']] as [$by, $at]) {
            $this->scoped(DB::table('outages as o'), $modules, $by, $userIds)
                ->whereNotNull($by)->whereBetween($at, [$from, $to])
                ->select(['o.id', 'o.workflow', "{$by} as user_id", "{$at} as moment"])->orderBy('o.id')->lazy(1000)
                ->each(fn (object $o) => $add((int) $o->user_id, self::moduleOf($o->workflow), $o->moment, ['escalated' => 1], (int) $o->id));
        }

        // 5. Field work: restorations reported (outage technicians, 4G engineers) and site visits completed.
        $this->scoped(DB::table('outages as o'), $modules, 'o.restored_by', $userIds)
            ->whereNotNull('o.restored_by')->whereBetween('o.restored_at', [$from, $to])
            ->select(['o.id', 'o.restored_by', 'o.restored_at', 'o.workflow'])->orderBy('o.id')->lazy(1000)
            ->each(fn (object $o) => $add((int) $o->restored_by, self::moduleOf($o->workflow), $o->restored_at, ['field' => 1], (int) $o->id));

        if (in_array('visit', $modules, true)) {
            DB::table('tickets as t')
                ->whereNotNull('t.completed_by')->whereNotNull('t.arrived_at')->whereBetween('t.completed_at', [$from, $to])
                ->when($userIds, fn (Builder $q) => $q->whereIn('t.completed_by', $userIds))
                ->select(['t.id', 't.completed_by', 't.arrived_at', 't.completed_at', 't.paused_seconds'])->orderBy('t.id')->lazy(1000)
                ->each(fn (object $t) => $add((int) $t->completed_by, 'visit', $t->completed_at, [
                    'on_site_minutes' => max(0, $this->minutes($t->arrived_at, $t->completed_at) - intdiv((int) $t->paused_seconds, 60)),
                ]));
        }

        // 6. Customer calls and progress updates.
        if (array_intersect($modules, ['ticket', 'visit']) !== []) {
            $this->scoped(DB::table('ticket_follow_ups as f')->join('outages as o', 'o.id', '=', 'f.outage_id'), $modules, 'f.user_id', $userIds)
                ->whereNotNull('f.user_id')->whereBetween('f.called_at', [$from, $to])
                ->select(['f.user_id', 'f.called_at', 'f.outage_id', 'o.workflow'])->orderBy('f.id')->lazy(1000)
                ->each(fn (object $f) => $add((int) $f->user_id, self::moduleOf($f->workflow), $f->called_at, ['calls' => 1], (int) $f->outage_id));
        }

        $this->scoped(DB::table('outage_updates as u')->join('outages as o', 'o.id', '=', 'u.outage_id'), $modules, 'u.user_id', $userIds)
            ->whereNotNull('u.user_id')->where('u.is_system', false)->whereBetween('u.created_at', [$from, $to])
            ->whereIn('u.type', ['note', 'progress', 'action', 'restoration', 'escalation'])
            ->select(['u.user_id', 'u.created_at', 'o.workflow'])->orderBy('u.id')->lazy(1000)
            ->each(fn (object $u) => $add((int) $u->user_id, self::moduleOf($u->workflow), $u->created_at, ['updates' => 1]));

        return $this->present(collect($cells));
    }

    // ------------------------------------------------------------------ scheduled e-mail

    /** @return array<string, scalar> */
    public function emailData(string $reportType, CarbonInterface $now, int $periodHours): array
    {
        [$from, $to, $label] = $this->emailPeriod($reportType, CarbonImmutable::instance($now), $periodHours);
        $rows = $this->rows($from, $to, 'period', array_keys(self::MODULES));
        $people = $rows->where('module', '!=', 'All modules');
        $columns = ['person' => 'Person', 'roles' => 'Role(s)', 'module' => 'Module', 'created' => 'Created', 'assigned' => 'Assigned', 'steps' => 'Steps done',
            'worked' => 'Worked', 'closed' => 'Closed', 'escalated' => 'Escalated', 'field' => 'Field work', 'calls' => 'Calls', 'avg_handle' => 'Avg time to handle (min)',
            'close_rate' => 'Close rate', 'escalation_rate' => 'Escalation rate'];

        $modules = collect(self::MODULES)->map(function (string $name, string $module) use ($people): array {
            $mine = $people->where('module', $name);

            return [$name, $mine->pluck('person')->unique()->count(), $mine->sum('created'), $mine->sum('worked'), $mine->sum('closed'),
                $mine->sum('escalated'), $mine->sum('field'), $this->rate($mine->sum('closed'), $mine->sum('worked')), $this->rate($mine->sum('escalated'), $mine->sum('worked'))];
        })->values()->all();

        $field = $people->where('field', '>', 0)->sortByDesc('field')->take(30)
            ->map(fn (array $row) => [$row['person'], $row['module'], $row['field'], $row['on_site_hours'] ?? '', $row['avg_handle'] ?? ''])->values()->all();

        return [
            'report_time' => $now->format('H:i'),
            'report_date' => $now->format('d F Y'),
            'productivity_period' => $label,
            'productivity_people' => $people->pluck('person')->unique()->count(),
            'productivity_worked' => $people->sum('worked'),
            'productivity_closed' => $people->sum('closed'),
            'productivity_escalated' => $people->sum('escalated'),
            'productivity_module_table' => TicketTables::table(['Module', 'People', 'Created', 'Worked', 'Closed', 'Escalated', 'Field work', 'Close rate', 'Escalation rate'], $modules),
            'productivity_table' => TicketTables::table(array_values($columns),
                $rows->sortBy([['person', 'asc'], ['module_order', 'asc']])->map(fn (array $row) => array_map(fn (string $key) => $row[$key] ?? '', array_keys($columns)))->values()->all(),
                ['left' => 3, 'empty' => 'Nobody worked a ticket in the period.']),
            'productivity_field_table' => TicketTables::table(['Technician / engineer', 'Module', 'Restored / completed', 'Time on premises (hrs)', 'Avg time to handle (min)'], $field,
                ['left' => 2, 'empty' => 'No field work in the period.']),
        ];
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable, 2: string} */
    public function emailPeriod(string $reportType, CarbonImmutable $now, int $periodHours): array
    {
        return match (self::EMAIL_TYPES[$reportType] ?? 'rolling') {
            // A daily e-mail early in the morning is about yesterday; later in the day, about today so far.
            'day' => $now->hour < 9
                ? [$now->subDay()->startOfDay(), $now->subDay()->endOfDay(), $now->subDay()->format('D d M Y')]
                : [$now->startOfDay(), $now, $now->format('D d M Y').' (to '.$now->format('H:i').')'],
            'week' => [$s = $now->startOfWeek(CarbonInterface::MONDAY)->subWeek(), $e = $s->addWeek()->subSecond(), $s->format('d M').' – '.$e->format('d M Y')],
            'month' => [$m = $now->startOfMonth()->subMonthNoOverflow(), $m->endOfMonth(), $m->format('F Y')],
            default => [$now->subHours(max(1, $periodHours)), $now, $now->subHours(max(1, $periodHours))->format('d M H:i').' – '.$now->format('d M H:i')],
        };
    }

    // ------------------------------------------------------------------ helpers

    /** Modules this user may see (all of them for the scheduler), narrowed to the ones asked for. */
    public function modules(array $requested = []): array
    {
        $user = Auth::user();
        $allowed = array_keys(array_filter([
            'outage' => ! $user || $user->can('outage.view'),
            'mobility' => ! $user || $user->can('mobility.view'),
            'ticket' => ! $user || $user->can('ticket.view'),
            'visit' => ! $user || $user->can('ticket.view'),
            'custom' => ! $user || $user->can('custom.view'),
        ]));

        $requested = array_values(array_intersect(array_map('strval', $requested), array_keys(self::MODULES)));

        return $requested === [] ? $allowed : array_values(array_intersect($allowed, $requested));
    }

    public static function moduleOf(?string $workflow): string
    {
        return match (true) {
            in_array($workflow, OutageType::MOBILITY_WORKFLOWS, true) => 'mobility',
            $workflow === OutageType::WORKFLOW_CX_TICKET => 'ticket',
            $workflow === OutageType::WORKFLOW_SITE_VISIT => 'visit',
            $workflow === OutageType::WORKFLOW_CUSTOM => 'custom',
            default => 'outage',
        };
    }

    /** Keep only the chosen modules (by the outage's workflow) and people. */
    private function scoped(Builder $query, array $modules, string $userColumn, array $userIds): Builder
    {
        return $query
            ->where(function (Builder $q) use ($modules): void {
                $q->whereRaw('1 = 0');

                foreach ($modules as $module) {
                    match ($module) {
                        'outage' => $q->orWhere(fn (Builder $w) => $w->whereNull('o.workflow')->orWhereNotIn('o.workflow', [...OutageType::MOBILITY_WORKFLOWS, ...OutageType::TICKET_WORKFLOWS, OutageType::WORKFLOW_CUSTOM])),
                        'custom' => $q->orWhere('o.workflow', OutageType::WORKFLOW_CUSTOM),
                        'mobility' => $q->orWhereIn('o.workflow', OutageType::MOBILITY_WORKFLOWS),
                        'ticket' => $q->orWhere('o.workflow', OutageType::WORKFLOW_CX_TICKET),
                        'visit' => $q->orWhere('o.workflow', OutageType::WORKFLOW_SITE_VISIT),
                        default => null,
                    };
                }
            })
            ->when($userIds, fn (Builder $q) => $q->whereIn($userColumn, $userIds));
    }

    /** @return array{0: string, 1: string} sortable key, label */
    private function bucket(CarbonImmutable $moment, string $group, CarbonImmutable $from, CarbonImmutable $to): array
    {
        return match ($group) {
            'hour' => [$moment->format('Y-m-d H'), $moment->format('D d M H:00')],
            'week' => [($w = $moment->startOfWeek(CarbonInterface::MONDAY))->format('Y-m-d'), 'Week of '.$w->format('d M Y')],
            'month' => [$moment->format('Y-m'), $moment->format('F Y')],
            'period' => ['all', $from->format('d M Y H:i').' – '.$to->format('d M Y H:i')],
            default => [$moment->format('Y-m-d'), $moment->format('D d M Y')],
        };
    }

    private function blank(): array
    {
        return array_fill_keys([
            'created', 'assigned', 'accepted', 'rejected', 'steps', 'closed', 'escalated', 'field', 'calls', 'updates',
            'accept_minutes', 'handle_minutes', 'close_minutes', 'on_site_minutes',
        ], 0) + ['tickets' => []];
    }

    /** Turn the counters into report rows, adding an "All modules" row per person and period when needed. */
    private function present(Collection $cells): Collection
    {
        if ($cells->isEmpty()) {
            return collect();
        }

        $users = User::query()->with('roles:id,name')->whereIn('id', $cells->pluck('user_id')->unique()->all())->get(['id', 'name'])->keyBy('id');
        $order = array_flip(array_keys(self::MODULES));

        $totals = $cells->groupBy(fn (array $cell) => $cell['key'].'|'.$cell['user_id'])
            ->filter(fn (Collection $group) => $group->count() > 1)
            ->map(function (Collection $group): array {
                $total = $group->first();
                $total['module'] = 'all';

                foreach ($group->skip(1) as $cell) {
                    foreach (array_keys($this->blank()) as $field) {
                        $total[$field] = $field === 'tickets' ? $total['tickets'] + $cell['tickets'] : $total[$field] + $cell[$field];
                    }
                }

                return $total;
            });

        return $cells->values()->merge($totals->values())
            ->map(function (array $c) use ($users, $order): array {
                $user = $users[$c['user_id']] ?? null;
                $worked = count($c['tickets']);

                return [
                    'key' => $c['key'],
                    'module_order' => $order[$c['module']] ?? 9,
                    'period' => $c['period'],
                    'person' => $user?->name ?? "User #{$c['user_id']}",
                    'roles' => $user?->roles->pluck('name')->implode(', '),
                    'module' => self::MODULES[$c['module']] ?? 'All modules',
                    'created' => $c['created'],
                    'assigned' => $c['assigned'],
                    'accepted' => $c['accepted'],
                    'rejected' => $c['rejected'],
                    'steps' => $c['steps'],
                    'worked' => $worked,
                    'closed' => $c['closed'],
                    'escalated' => $c['escalated'],
                    'field' => $c['field'],
                    'calls' => $c['calls'],
                    'updates' => $c['updates'],
                    'avg_accept' => $c['accepted'] ? (int) round($c['accept_minutes'] / $c['accepted']) : null,
                    'avg_handle' => $c['steps'] ? (int) round($c['handle_minutes'] / $c['steps']) : null,
                    'handle_hours' => $c['steps'] ? round($c['handle_minutes'] / 60, 1) : null,
                    'avg_close' => $c['closed'] ? round($c['close_minutes'] / $c['closed'] / 60, 1) : null,
                    'on_site_hours' => $c['on_site_minutes'] ? round($c['on_site_minutes'] / 60, 1) : null,
                    'close_rate' => $this->rate($c['closed'], $worked),
                    'escalation_rate' => $this->rate($c['escalated'], $worked),
                ];
            })
            ->sortBy([['key', 'asc'], ['person', 'asc'], ['module_order', 'asc']])
            ->values();
    }

    private function minutes(mixed $from, mixed $to): int
    {
        return $from && $to ? max(0, intdiv(strtotime((string) $to) - strtotime((string) $from), 60)) : 0;
    }

    private function rate(int $part, int $whole): string
    {
        return $whole > 0 ? rtrim(rtrim(number_format($part / $whole * 100, 1), '0'), '.').'%' : '—';
    }
}
