<?php

namespace App\Services\Reports;

use App\Enums\AvailabilityStatus;
use App\Models\User;
use App\Models\UserAvailability;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * How long each person was Available, Busy, On break and Offline — day by day, week by week or
 * month by month.
 *
 * Every change of availability is kept (user_availabilities: from / to, written by
 * FifoAssignmentEngine::setAvailability, whether the person changed it on the website, in the app
 * or a manager did). The time of a period is the overlap of those stretches with it, up to now.
 * Time before a person's first recorded change is "not recorded": what they were then is not known.
 */
class AvailabilityTimeReport
{
    public const KEY = 'availability_time';

    public const TITLE = 'People: Availability Time (available / busy / on break / offline)';

    public const GROUPS = ['day' => 'Day by day', 'week' => 'Week by week', 'month' => 'Month by month'];

    /** status column => the statuses counted in it */
    private const STATES = [
        'available' => AvailabilityStatus::Available,
        'busy' => AvailabilityStatus::Busy,
        'on_break' => AvailabilityStatus::OnBreak,
        'offline' => AvailabilityStatus::Offline,
    ];

    /**
     * @param  array{from?: mixed, to?: mixed, group?: ?string, user_ids?: array}  $filters
     */
    public function run(array $filters = []): ReportResult
    {
        $group = array_key_exists($filters['group'] ?? '', self::GROUPS) ? $filters['group'] : 'day';
        [$from, $to] = $this->period($filters, $group);
        $rows = $this->rows($from, $to, $group, array_values(array_filter(array_map('intval', (array) ($filters['user_ids'] ?? [])))));

        $total = fn (string $column) => $this->duration((int) $rows->sum($column));

        return new ReportResult(
            self::TITLE,
            [
                'period' => 'Period', 'person' => 'Person',
                'available' => 'Available', 'busy' => 'Busy', 'on_break' => 'On break', 'offline' => 'Offline',
                'available_share' => 'Available %', 'unrecorded' => 'Not recorded',
            ],
            fn (?int $limit) => ($limit ? $rows->take($limit) : $rows)->map(fn (array $row) => [
                'period' => $row['period'],
                'person' => $row['person'],
                'available' => $this->duration($row['available']),
                'busy' => $this->duration($row['busy']),
                'on_break' => $this->duration($row['on_break']),
                'offline' => $this->duration($row['offline']),
                'available_share' => ($recorded = $row['available'] + $row['busy'] + $row['on_break'] + $row['offline']) > 0
                    ? round($row['available'] / $recorded * 100).'%' : '—',
                'unrecorded' => $this->duration($row['unrecorded']),
            ])->all(),
            [
                'People' => $rows->pluck('user_id')->unique()->count(),
                'Available' => $total('available'),
                'Busy' => $total('busy'),
                'On break' => $total('on_break'),
                'Offline' => $total('offline'),
            ],
            $from->format('d M Y').' – '.$to->format('d M Y').' · '.self::GROUPS[$group]
                .'. Hours:minutes, counted up to now. "Not recorded" is time before the person’s first change of availability.',
        );
    }

    /**
     * One row per person and period, in seconds.
     *
     * @param  list<int>  $userIds  empty = everyone whose availability has been recorded
     * @return Collection<int, array{period: string, start: string, user_id: int, person: string, available: int, busy: int, on_break: int, offline: int, unrecorded: int}>
     */
    public function rows(CarbonImmutable $from, CarbonImmutable $to, string $group, array $userIds = []): Collection
    {
        $end = $to->min(CarbonImmutable::now());

        if ($end->lessThanOrEqualTo($from)) {
            return collect();
        }

        $people = User::query()
            ->when($userIds !== [], fn ($query) => $query->whereIn('id', $userIds))
            ->when($userIds === [], fn ($query) => $query->where(fn ($q) => $q->whereHas('availabilityHistory')->orWhereNotNull('availability_changed_at')))
            ->orderBy('name')
            ->get(['id', 'name', 'availability_status', 'availability_changed_at', 'created_at']);

        // Every stretch that touches the period, for all of them at once.
        $stretches = UserAvailability::query()
            ->whereIn('user_id', $people->pluck('id'))
            ->where('effective_from', '<', $end)
            ->where(fn ($query) => $query->whereNull('effective_to')->orWhere('effective_to', '>', $from))
            ->orderBy('effective_from')
            ->get(['user_id', 'availability_status', 'effective_from', 'effective_to'])
            ->groupBy('user_id');

        // When each person's record starts: before it, what they were is not known.
        $firstRecorded = UserAvailability::query()->whereIn('user_id', $people->pluck('id'))
            ->groupBy('user_id')->selectRaw('user_id, min(effective_from) as first_from')->pluck('first_from', 'user_id');

        $periods = $this->periods($from, $end, $group);
        $rows = collect();

        foreach ($people as $person) {
            $spans = $stretches->get($person->id, collect())->map(fn (UserAvailability $stretch) => [
                $stretch->availability_status, CarbonImmutable::parse($stretch->effective_from), CarbonImmutable::parse($stretch->effective_to ?? $end),
            ]);

            $recordedFrom = isset($firstRecorded[$person->id]) ? CarbonImmutable::parse($firstRecorded[$person->id]) : null;

            // Never changed since the history began: what they are now, since the last change we know of.
            if ($recordedFrom === null && $person->availability_status) {
                $recordedFrom = CarbonImmutable::parse($person->availability_changed_at ?? $person->created_at);
                $spans = collect([[$person->availability_status, $recordedFrom, $end]]);
            }

            foreach ($periods as [$label, $start, $stop]) {
                $row = ['period' => $label, 'start' => $start->toDateString(), 'user_id' => $person->id, 'person' => $person->name,
                    'available' => 0, 'busy' => 0, 'on_break' => 0, 'offline' => 0, 'unrecorded' => 0];

                foreach ($spans as [$status, $spanFrom, $spanTo]) {
                    $seconds = $this->overlap($start, $stop, $spanFrom, $spanTo);
                    $column = array_search($status, self::STATES, true);

                    if ($seconds > 0 && $column !== false) {
                        $row[$column] += $seconds;
                    }
                }

                $row['unrecorded'] = $recordedFrom ? $this->overlap($start, $stop, $start, $recordedFrom->max($start)) : $start->diffInSeconds($stop);

                // A period with nothing at all for this person is left out.
                if ($row['available'] + $row['busy'] + $row['on_break'] + $row['offline'] > 0) {
                    $rows->push($row);
                }
            }
        }

        return $rows->sortBy([['start', 'asc'], ['person', 'asc']])->values();
    }

    /** "Today and the 6 days before", "these 4 weeks", "these 3 months" when no dates are given. */
    public function period(array $filters, string $group): array
    {
        $to = filled($filters['to'] ?? null) ? CarbonImmutable::parse($filters['to'])->endOfDay() : CarbonImmutable::now()->endOfDay();
        $from = filled($filters['from'] ?? null) ? CarbonImmutable::parse($filters['from'])->startOfDay() : match ($group) {
            'week' => $to->startOfWeek(CarbonInterface::MONDAY)->subWeeks(3),
            'month' => $to->startOfMonth()->subMonths(2),
            default => $to->subDays(6)->startOfDay(),
        };

        return [$from->min($to), $to];
    }

    /** @return list<array{0: string, 1: CarbonImmutable, 2: CarbonImmutable}> label, start, end */
    private function periods(CarbonImmutable $from, CarbonImmutable $to, string $group): array
    {
        $periods = [];
        $cursor = match ($group) {
            'week' => $from->startOfWeek(CarbonInterface::MONDAY),
            'month' => $from->startOfMonth(),
            default => $from->startOfDay(),
        };

        while ($cursor->lessThan($to)) {
            $next = match ($group) {
                'week' => $cursor->addWeek(),
                'month' => $cursor->addMonthNoOverflow()->startOfMonth(),
                default => $cursor->addDay(),
            };

            $label = match ($group) {
                'week' => 'Week '.$cursor->isoWeek().' · '.$cursor->format('d M').' – '.$next->subDay()->format('d M Y'),
                'month' => $cursor->format('F Y'),
                default => $cursor->format('D d M Y'),
            };

            // Clipped to the dates asked for.
            $periods[] = [$label, $cursor->max($from), $next->min($to)];
            $cursor = $next;
        }

        return $periods;
    }

    private function overlap(CarbonImmutable $start, CarbonImmutable $stop, CarbonImmutable $from, CarbonImmutable $to): int
    {
        $begin = $start->max($from);
        $end = $stop->min($to);

        return $end->greaterThan($begin) ? (int) $begin->diffInSeconds($end) : 0;
    }

    /** "05:20" — hours and minutes, the hours going past 24 when they do. */
    private function duration(int $seconds): string
    {
        $minutes = intdiv($seconds, 60);

        return sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
    }
}
