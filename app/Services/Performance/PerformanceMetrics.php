<?php

namespace App\Services\Performance;

use App\Enums\DataSource;
use App\Enums\EngagementType;
use App\Enums\SlaState;
use App\Enums\TechnicianCompany;
use App\Enums\TechnicianRole;
use App\Models\OutageType;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The figures of the investor dashboard: outages and site visits per month over the last six months,
 * real and demo rows always counted apart.
 *
 *   closed %          outages: rows with a restoration time / all rows
 *   within window %   rows finished within the window (24 h) / rows with a measured time
 *   internal SLA %    rows recorded as within the internal SLA / rows with an SLA result
 *
 * Real = imported from the incident log / ticket tracker, or raised in the system. Demo = generated.
 */
class PerformanceMetrics
{
    public const KINDS = ['outages', 'visits'];

    public function from(): Carbon
    {
        return now()->subMonthsNoOverflow((int) config('performance.months', 6))->startOfDay();
    }

    /**
     * @return list<array{key: string, label: string, from: Carbon, to: Carbon, outages: array{real: array<string, mixed>, demo: array<string, mixed>}, visits: array{real: array<string, mixed>, demo: array<string, mixed>}}>
     */
    public function months(): array
    {
        $from = $this->from();
        $rows = $this->aggregate($from)->groupBy(fn ($r) => $r->ym);
        $months = [];

        for ($month = $from->copy()->startOfMonth(); $month->lte(now()); $month->addMonth()) {
            $start = $month->copy()->max($from);
            $end = $month->copy()->endOfMonth()->min(now());
            $key = $month->format('Y-m');
            $bucket = ['key' => $key, 'from' => $start, 'to' => $end,
                'label' => $start->day === 1 && $end->isSameDay($month->copy()->endOfMonth()) ? $month->format('M Y') : $start->format('j').'–'.$end->format('j M Y')];

            foreach (self::KINDS as $kind) {
                foreach (['real', 'demo'] as $origin) {
                    $bucket[$kind][$origin] = $this->stats($rows->get($key, collect())->where('kind', $kind)->where('origin', $origin)->first(), $kind);
                }
            }
            $months[] = $bucket;
        }

        return $months;
    }

    /** @return array{real: array<string, mixed>, demo: array<string, mixed>, first: ?Carbon, last: ?Carbon} for the whole window */
    public function totals(string $kind): array
    {
        $from = $this->from();
        $rows = $this->aggregate($from, byMonth: false)->where('kind', $kind);
        $real = $this->query($from, $kind)->where(fn ($q) => $q->whereNull('data_source')->orWhere('data_source', '!=', DataSource::Demo->value));

        return [
            'real' => $this->stats($rows->firstWhere('origin', 'real'), $kind),
            'demo' => $this->stats($rows->firstWhere('origin', 'demo'), $kind),
            'first' => ($v = (clone $real)->min('start_time')) ? Carbon::parse($v) : null,
            'last' => ($v = (clone $real)->max('start_time')) ? Carbon::parse($v) : null,
        ];
    }

    /** Field crews on real site visits in the window: visits, completed within the window, internal SLA. */
    public function crews(int $limit = 15): array
    {
        $window = (float) config('performance.visits.window_hours');

        return $this->query($this->from(), 'visits')
            ->where(fn ($q) => $q->whereNull('outages.data_source')->orWhere('outages.data_source', '!=', DataSource::Demo->value))
            ->join('teams', 'teams.id', '=', 'outages.team_id')
            ->groupBy('teams.name')
            ->selectRaw('teams.name as crew, COUNT(*) as visits')
            ->selectRaw('SUM(CASE WHEN '.$this->elapsed().' IS NOT NULL THEN 1 ELSE 0 END) as measured')
            ->selectRaw('SUM(CASE WHEN '.$this->elapsed().' <= ? THEN 1 ELSE 0 END) as within', [$window])
            ->selectRaw("SUM(CASE WHEN outages.sla_state = 'met' THEN 1 ELSE 0 END) as met")
            ->selectRaw("SUM(CASE WHEN outages.sla_state IN ('met', 'missed', 'breached') THEN 1 ELSE 0 END) as sla_measured")
            ->orderByDesc('visits')->limit($limit)->get()
            ->map(fn ($r) => [
                'crew' => $r->crew,
                'visits' => (int) $r->visits,
                'within' => $this->pct($r->within, $r->measured),
                'internal' => $this->pct($r->met, $r->sla_measured),
            ])->all();
    }

    /** Technician accounts by team and role (from the technician list). */
    public function technicians(): array
    {
        $rows = DB::table('users')
            ->join('team_user', 'team_user.user_id', '=', 'users.id')
            ->join('teams', 'teams.id', '=', 'team_user.team_id')
            ->join('model_has_roles', fn ($j) => $j->on('model_has_roles.model_id', '=', 'users.id')->where('model_has_roles.model_type', (new \App\Models\User)->getMorphClass()))
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->whereIn('roles.name', array_map(fn (TechnicianRole $r) => $r->value, TechnicianRole::cases()))
            ->whereIn('teams.name', array_map(fn (TechnicianCompany $c) => $c->getLabel(), TechnicianCompany::cases()))
            ->where('users.is_active', true)
            ->groupBy('teams.name', 'roles.name')
            ->selectRaw('teams.name as team, roles.name as role, COUNT(DISTINCT users.id) as people')
            ->get();

        $teams = [];
        foreach (TechnicianCompany::cases() as $company) {
            $mine = $rows->where('team', $company->getLabel());
            if ($mine->isEmpty()) {
                continue;
            }
            $teams[] = [
                'team' => $company->getLabel(),
                'engagement' => $company->engagement()->getLabel(),
                'roles' => collect(TechnicianRole::cases())->mapWithKeys(fn (TechnicianRole $r) => [$r->value => (int) $mine->firstWhere('role', $r->value)?->people])->all(),
                'total' => (int) $mine->sum('people'),
            ];
        }
        usort($teams, fn ($a, $b) => $b['total'] <=> $a['total']);

        return [
            'teams' => $teams,
            'roles' => collect(TechnicianRole::cases())->mapWithKeys(fn (TechnicianRole $r) => [$r->value => (int) $rows->where('role', $r->value)->sum('people')])->all(),
            'engagement' => collect(EngagementType::cases())->mapWithKeys(fn (EngagementType $e) => [$e->getLabel() => DB::table('users')->where('is_active', true)->where('engagement_type', $e->value)->count()])->all(),
            'total' => (int) $rows->sum('people'),
        ];
    }

    /** The live demo trickle: today's demo site visits and the latest arrivals. */
    public function live(int $latest = 8): array
    {
        $today = now()->startOfDay();
        $demo = fn () => DB::table('outages')->where('data_source', DataSource::Demo->value)->where('workflow', OutageType::WORKFLOW_SITE_VISIT);

        return [
            'raised_today' => $demo()->where('start_time', '>=', $today)->count(),
            'last_hour' => $demo()->where('start_time', '>=', now()->subHour())->count(),
            'open' => $demo()->where('is_closed', false)->where('start_time', '>=', now()->subDays(7))->count(),
            'closed_today' => $demo()->where('is_closed', true)->where('resolved_at', '>=', $today)->count(),
            'latest' => $demo()->leftJoin('teams', 'teams.id', '=', 'outages.team_id')
                ->orderByDesc('start_time')->limit($latest)
                ->get(['outages.ticket_number', 'outages.title', 'outages.location', 'outages.start_time', 'outages.is_closed', 'outages.sla_state', 'teams.name as crew'])
                ->map(fn ($r) => (array) $r + ['state' => SlaState::tryFrom((string) $r->sla_state)?->getLabel()])->all(),
        ];
    }

    // ------------------------------------------------------------------ internals

    private function query(Carbon $from, string $kind)
    {
        return DB::table('outages')
            ->where('outages.start_time', '>=', $from)
            ->where('outages.start_time', '<=', now())
            ->when($kind === 'visits', fn ($q) => $q->where('outages.workflow', OutageType::WORKFLOW_SITE_VISIT))
            ->when($kind === 'outages', fn ($q) => $q->whereNull('outages.workflow'));
    }

    private function aggregate(Carbon $from, bool $byMonth = true)
    {
        $kind = "CASE WHEN outages.workflow = '".OutageType::WORKFLOW_SITE_VISIT."' THEN 'visits' ELSE 'outages' END";
        $origin = "CASE WHEN outages.data_source = '".DataSource::Demo->value."' THEN 'demo' ELSE 'real' END";
        $month = $this->monthExpression();
        $outageWindow = (float) config('performance.outages.window_hours');
        $visitWindow = (float) config('performance.visits.window_hours');

        $query = DB::table('outages')
            ->where('outages.start_time', '>=', $from)
            ->where('outages.start_time', '<=', now())
            ->where(fn ($q) => $q->whereNull('outages.workflow')->orWhere('outages.workflow', OutageType::WORKFLOW_SITE_VISIT))
            ->selectRaw("{$kind} as kind, {$origin} as origin".($byMonth ? ", {$month} as ym" : ''))
            ->selectRaw('COUNT(*) as total')
            ->selectRaw('SUM(CASE WHEN outages.resolved_at IS NOT NULL THEN 1 ELSE 0 END) as closed')
            ->selectRaw('SUM(CASE WHEN outages.is_closed THEN 0 ELSE 1 END) as still_open')
            ->selectRaw('SUM(CASE WHEN '.$this->elapsed().' IS NOT NULL THEN 1 ELSE 0 END) as measured')
            ->selectRaw("SUM(CASE WHEN {$this->elapsed()} <= (CASE WHEN outages.workflow = '".OutageType::WORKFLOW_SITE_VISIT."' THEN ? ELSE ? END) THEN 1 ELSE 0 END) as within", [$visitWindow, $outageWindow])
            ->selectRaw("SUM(CASE WHEN outages.sla_state = 'met' THEN 1 ELSE 0 END) as met")
            ->selectRaw("SUM(CASE WHEN outages.sla_state IN ('met', 'missed', 'breached') THEN 1 ELSE 0 END) as sla_measured")
            ->selectRaw('SUM(outages.customers_affected) as customers')
            ->groupByRaw($kind.', '.$origin.($byMonth ? ", {$month}" : ''));

        return $query->get();
    }

    /**
     * Hours to finish: as measured by the source (null when the source's times do not add up — such rows are
     * not measured), or for rows raised in the system, resolved − started.
     */
    private function elapsed(): string
    {
        $diff = match (DB::connection()->getDriverName()) {
            'pgsql' => 'EXTRACT(EPOCH FROM (outages.resolved_at - outages.start_time)) / 3600.0',
            'sqlite' => '(julianday(outages.resolved_at) - julianday(outages.start_time)) * 24',
            default => 'TIMESTAMPDIFF(SECOND, outages.start_time, outages.resolved_at) / 3600.0',
        };

        return "(CASE WHEN outages.data_source IS NULL THEN {$diff} ELSE outages.elapsed_hours END)";
    }

    private function monthExpression(): string
    {
        return match (DB::connection()->getDriverName()) {
            'pgsql' => "to_char(outages.start_time, 'YYYY-MM')",
            'sqlite' => "strftime('%Y-%m', outages.start_time)",
            default => "DATE_FORMAT(outages.start_time, '%Y-%m')",
        };
    }

    /** @return array<string, mixed> */
    private function stats(?object $r, string $kind): array
    {
        $total = (int) ($r->total ?? 0);

        return [
            'total' => $total,
            'closed' => (int) ($r->closed ?? 0),
            'open' => (int) ($r->still_open ?? 0),
            'measured' => (int) ($r->measured ?? 0),
            'within' => (int) ($r->within ?? 0),
            'customers' => (int) ($r->customers ?? 0),
            'closed_pct' => $this->pct($r->closed ?? 0, $total),
            'within_pct' => $this->pct($r->within ?? 0, $r->measured ?? 0),
            'internal_pct' => $this->pct($r->met ?? 0, $r->sla_measured ?? 0),
        ];
    }

    private function pct(mixed $part, mixed $whole): ?float
    {
        return (int) $whole > 0 ? round(100 * (int) $part / (int) $whole, 1) : null;
    }
}
