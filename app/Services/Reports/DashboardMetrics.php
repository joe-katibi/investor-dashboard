<?php

namespace App\Services\Reports;

use App\Enums\QueueEntryStatus;
use App\Enums\QueueType;
use App\Enums\SlaState;
use App\Models\MajorOutageType;
use App\Models\Outage;
use App\Models\OutageQueueEntry;
use App\Models\OutageStatus;
use App\Models\OutageType;
use App\Models\Priority;
use App\Models\Vertical;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Aggregates for the command-centre dashboard (spec §1 / §3).
 *
 * Results are cached for a few seconds under a version key that every OMS real-time event
 * bumps, so a wall of NOC screens shares one set of queries yet still refreshes the moment
 * something changes. Only arrays are cached (the cache store refuses serialized objects).
 */
class DashboardMetrics
{
    public const VERSION_KEY = 'oms.dashboard.version';

    public function __construct(private readonly ReportRegistry $reports) {}

    public static function bump(): void
    {
        Cache::add(self::VERSION_KEY, 1, now()->addDay());
        Cache::increment(self::VERSION_KEY);
    }

    /** @return array{0: Carbon, 1: Carbon} */
    public function period(array $filters): array
    {
        $from = ! empty($filters['from']) ? Carbon::parse($filters['from'])->startOfDay() : now()->subDays(6)->startOfDay();
        $to = ! empty($filters['to']) ? Carbon::parse($filters['to'])->endOfDay() : now()->endOfDay();

        return [$from, $to];
    }

    public function kpis(array $filters): array
    {
        return $this->remember('kpis', $filters, function () use ($filters): array {
            [$from, $to] = $this->period($filters);
            $period = fn () => $this->scoped($filters)->whereBetween('start_time', [$from, $to]);
            $active = fn () => $this->scoped($filters)->where('is_closed', false);

            $types = OutageType::query()->pluck('id', 'code');
            $byType = $period()->toBase()->selectRaw('outage_type_id, count(*) as aggregate')->groupBy('outage_type_id')->pluck('aggregate', 'outage_type_id');

            // The top severity of every vertical (FTTH Critical, 4G Critical).
            $criticalIds = Priority::query()->whereIn('code', Priority::CRITICAL_CODES)->pluck('id')->all();

            return [
                'total' => $period()->count(),
                'active' => $active()->count(),
                'resolved' => $this->scoped($filters)->whereBetween('resolved_at', [$from, $to])->count(),
                'critical' => $active()->whereIn('priority_id', $criticalIds ?: [0])->count(),
                'customers' => (int) $active()->sum('customers_affected'),
                'regular' => (int) ($byType[$types['regular'] ?? 0] ?? 0),
                'emergency' => (int) ($byType[$types['emergency'] ?? 0] ?? 0),
                'planned' => (int) ($byType[$types['planned'] ?? 0] ?? 0),
                'trend' => array_column($this->trend($filters), 'outages'),
            ];
        });
    }

    public function sla(array $filters): array
    {
        return $this->remember('sla', $filters, function () use ($filters): array {
            [$from, $to] = $this->period($filters);
            $live = $this->scoped($filters)->where('is_closed', false)->toBase()
                ->selectRaw('sla_state, count(*) as aggregate')->groupBy('sla_state')->pluck('aggregate', 'sla_state');
            $closed = $this->scoped($filters)->whereBetween('resolved_at', [$from, $to])->toBase()
                ->selectRaw('sla_state, count(*) as aggregate')->groupBy('sla_state')->pluck('aggregate', 'sla_state');
            $met = (int) ($closed[SlaState::Met->value] ?? 0);
            $missed = (int) ($closed[SlaState::Missed->value] ?? 0);
            $active = fn () => $this->scoped($filters)->where('is_closed', false);

            return [
                'compliant' => (int) ($live[SlaState::Compliant->value] ?? 0),
                'at_risk' => (int) ($live[SlaState::AtRisk->value] ?? 0),
                'breached' => (int) ($live[SlaState::Breached->value] ?? 0),
                'met' => $met,
                'missed' => $missed,
                'compliance' => $met + $missed > 0 ? round($met / ($met + $missed) * 100, 1) : null,
                'sms_sent' => $active()->where('sms_sent', true)->count(),
                'sms_pending' => $active()->where('sms_sent', false)->count(),
                'credit_shared' => $active()->where('credit_shared', true)->count(),
                'credit_pending' => $active()->where('credit_shared', false)->where('sla_state', SlaState::Breached->value)->count(),
            ];
        });
    }

    public function mobility(): array
    {
        return $this->remember('mobility', [], function (): array {
            $query = fn () => Outage::query()->active()->ofVertical(Vertical::MOBILITY_4G);

            return [
                'active' => $query()->count(),
                'sites_down' => (int) $query()->sum('sites_down'),
                'cells_down' => (int) $query()->sum('cells_down'),
                'power_faults' => (int) $query()->sum('power_faults'),
                'customers' => (int) $query()->sum('customers_affected'),
            ];
        });
    }

    public function liveByMajorType(): array
    {
        return $this->remember('major', [], function (): array {
            $rows = Outage::query()->active()->toBase()
                ->selectRaw('major_outage_type_id, count(*) as outages, sum(customers_affected) as customers')
                ->groupBy('major_outage_type_id')->get()->keyBy('major_outage_type_id');

            return MajorOutageType::query()->orderBy('sort_order')->get()->mapWithKeys(fn ($type) => [$type->code => [
                'name' => $type->name,
                'outages' => (int) ($rows[$type->id]->outages ?? 0),
                'customers' => (int) ($rows[$type->id]->customers ?? 0),
            ]])->all();
        });
    }

    /** 7-day trend (volume, customer impact, resolved) — computed in PHP for DB portability. */
    public function trend(array $filters, int $days = 7): array
    {
        return $this->remember('trend'.$days, $filters, function () use ($filters, $days): array {
            $start = now()->subDays($days - 1)->startOfDay();

            $created = $this->scoped($filters)->where('start_time', '>=', $start)->get(['start_time', 'customers_affected'])
                ->groupBy(fn (Outage $o) => $o->start_time->format('Y-m-d'));
            $resolved = $this->scoped($filters)->where('resolved_at', '>=', $start)->get(['resolved_at'])
                ->groupBy(fn (Outage $o) => $o->resolved_at->format('Y-m-d'));

            $rows = [];
            for ($i = 0; $i < $days; $i++) {
                $day = $start->copy()->addDays($i);
                $key = $day->format('Y-m-d');
                $rows[] = [
                    'label' => $day->format('D d'),
                    'outages' => $created->get($key, collect())->count(),
                    'customers' => (int) $created->get($key, collect())->sum('customers_affected'),
                    'resolved' => $resolved->get($key, collect())->count(),
                ];
            }

            return $rows;
        });
    }

    public function byStatus(array $filters): array
    {
        return $this->remember('status', $filters, function () use ($filters): array {
            $counts = $this->scoped($filters)->where('is_closed', false)->toBase()
                ->selectRaw('status_id, count(*) as aggregate')->groupBy('status_id')->pluck('aggregate', 'status_id');

            return OutageStatus::query()->whereIn('id', $counts->keys())->orderBy('sort_order')->get()
                ->map(fn (OutageStatus $s) => ['label' => $s->name, 'value' => (int) $counts[$s->id], 'color' => $s->color])->all();
        });
    }

    public function byPriority(array $filters): array
    {
        return $this->remember('priority', $filters, function () use ($filters): array {
            [$from, $to] = $this->period($filters);
            $period = $this->scoped($filters)->whereBetween('start_time', [$from, $to])->toBase()
                ->selectRaw('priority_id, count(*) as aggregate')->groupBy('priority_id')->pluck('aggregate', 'priority_id');
            $active = $this->scoped($filters)->where('is_closed', false)->toBase()
                ->selectRaw('priority_id, count(*) as aggregate')->groupBy('priority_id')->pluck('aggregate', 'priority_id');

            return Priority::query()->orderBy('queue_rank')->get()->map(fn (Priority $p) => [
                'label' => $p->name, 'period' => (int) ($period[$p->id] ?? 0), 'active' => (int) ($active[$p->id] ?? 0), 'color' => $p->color,
            ])->all();
        });
    }

    public function teamPerformance(array $filters): array
    {
        return $this->remember('teams', $filters, function () use ($filters): array {
            [$from, $to] = $this->period($filters);

            return $this->reports->run('team_performance', ['from' => $from->toDateTimeString(), 'to' => $to->toDateTimeString()])->rowsArray(10);
        });
    }

    public function topOlts(array $filters): array
    {
        return $this->remember('olts', $filters, function () use ($filters): array {
            [$from, $to] = $this->period($filters);

            return $this->reports->run('olt_fdt_impact', $this->reportFilters($filters, $from, $to))->rowsArray(8);
        });
    }

    public function topServices(array $filters): array
    {
        return $this->remember('services', $filters, function () use ($filters): array {
            [$from, $to] = $this->period($filters);

            return $this->reports->run('service_impact', $this->reportFilters($filters, $from, $to))->rowsArray(8);
        });
    }

    public function queues(): array
    {
        return $this->remember('queues', [], function (): array {
            $rows = [];

            foreach (QueueType::cases() as $type) {
                $open = OutageQueueEntry::query()->open()->where('queue_type', $type->value);
                $oldest = (clone $open)->where('status', QueueEntryStatus::Pending->value)->min('queued_at');

                $rows[] = [
                    'label' => $type->getLabel(),
                    'color' => $type->getColor(),
                    'pending' => (clone $open)->where('status', QueueEntryStatus::Pending->value)->count(),
                    'assigned' => (clone $open)->where('status', QueueEntryStatus::Assigned->value)->count(),
                    'accepted' => (clone $open)->where('status', QueueEntryStatus::Accepted->value)->count(),
                    'oldest_minutes' => $oldest ? (int) Carbon::parse($oldest)->diffInMinutes(now()) : null,
                ];
            }

            return $rows;
        });
    }

    // ------------------------------------------------------------------ internals

    private function scoped(array $filters): Builder
    {
        return Outage::query()
            ->when($filters['vertical_id'] ?? null, fn (Builder $q, $v) => $q->where('vertical_id', $v))
            ->when($filters['major_outage_type_id'] ?? null, fn (Builder $q, $v) => $q->where('major_outage_type_id', $v));
    }

    private function reportFilters(array $filters, Carbon $from, Carbon $to): array
    {
        return array_filter([
            'from' => $from->toDateTimeString(),
            'to' => $to->toDateTimeString(),
            'vertical_ids' => ($filters['vertical_id'] ?? null) ? [$filters['vertical_id']] : null,
            'major_type_ids' => ($filters['major_outage_type_id'] ?? null) ? [$filters['major_outage_type_id']] : null,
        ]);
    }

    private function remember(string $name, array $filters, callable $callback): array
    {
        $version = (int) Cache::get(self::VERSION_KEY, 0);
        $key = 'oms.dashboard.'.$name.'.'.$version.'.'.md5(json_encode($filters)).'.'.now()->format('YmdHi');

        return Cache::remember($key, now()->addSeconds(30), $callback);
    }
}
