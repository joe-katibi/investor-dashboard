<?php

namespace App\Services\Reports;

use App\Models\OutageType;
use App\Models\VisitType;
use App\Support\Settings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Month-on-month figures for the network outages and site visits, from the SLA result the system recorded
 * on each one (the same result every other dashboard and report counts). A row counts in the month it was
 * raised.
 *
 *   within SLA %   restored / completed within the SLA ÷ those with an SLA result (met, missed, breached)
 *   closed %       restored / closed ÷ all raised that month
 */
class MonthlyPerformance
{
    public const KINDS = ['outages', 'visits'];

    /** A month with fewer SLA results than this is listed, but gives no month-on-month change and no chart point. */
    public const MIN_MEASURED = 20;

    /**
     * Every month from the first one with data to the current month.
     *
     * @return list<array{key: string, label: string, short: string, outages: array<string, mixed>, visits: array<string, mixed>}>
     */
    public function months(): array
    {
        $rows = $this->aggregate();
        $first = $rows->min('ym');

        if ($first === null) {
            return [];
        }

        $months = [];
        $previous = ['outages' => null, 'visits' => null];

        for ($month = Carbon::createFromFormat('Y-m-d', "{$first}-01")->startOfMonth(); $month->lte(now()); $month->addMonth()) {
            $key = $month->format('Y-m');
            $row = ['key' => $key, 'label' => $month->format('F Y'), 'short' => $month->format('M Y')];

            foreach (self::KINDS as $kind) {
                $stats = $this->stats($rows->first(fn ($r) => $r->ym === $key && $r->kind === $kind));
                $stats['enough'] = $stats['measured'] >= self::MIN_MEASURED;
                $stats['change'] = $stats['enough'] && $previous[$kind] !== null ? round($stats['pct'] - $previous[$kind], 1) : null;
                $previous[$kind] = $stats['enough'] ? $stats['pct'] : $previous[$kind];
                $row[$kind] = $stats;
            }

            $months[] = $row;
        }

        return $months;
    }

    /** @return array<string, array<string, mixed>> all months together, per kind */
    public function totals(): array
    {
        $rows = $this->aggregate(byMonth: false);

        return collect(self::KINDS)->mapWithKeys(fn (string $kind) => [$kind => $this->stats($rows->firstWhere('kind', $kind))])->all();
    }

    /** @return array{outages: string, visits: string} the SLA each kind is measured against, in words */
    public function rules(): array
    {
        // $outage = app(Settings::class)->int('sla.default_resolution_minutes');
        // $visit = (int) VisitType::query()->where('code_prefix', 'SUP')->value('default_sla_minutes');

         $outage = 360; // 6 hours in minutes
        $visit = 720;  // 12 hours in minutes

        return [
            'outages' => 'restored within '.$this->hours($outage).' hours of the fault',
            'visits' => 'completed within '.$this->hours($visit).' business hours (07:00–18:00)',
        ];
    }

    // ------------------------------------------------------------------ internals

    private function aggregate(bool $byMonth = true): Collection
    {
        $visit = OutageType::WORKFLOW_SITE_VISIT;
        $kind = "CASE WHEN outages.workflow = '{$visit}' THEN 'visits' ELSE 'outages' END";
        $month = $this->monthExpression();

        return DB::table('outages')
            ->where(fn ($q) => $q->whereNull('outages.workflow')->orWhere('outages.workflow', $visit))
            ->where('outages.start_time', '<=', now())
            ->selectRaw("{$kind} as kind".($byMonth ? ", {$month} as ym" : ''))
            ->selectRaw('COUNT(*) as total')
            ->selectRaw('SUM(CASE WHEN outages.resolved_at IS NOT NULL THEN 1 ELSE 0 END) as closed')
            ->selectRaw("SUM(CASE WHEN outages.sla_state = 'met' THEN 1 ELSE 0 END) as met")
            ->selectRaw("SUM(CASE WHEN outages.sla_state IN ('met', 'missed', 'breached') THEN 1 ELSE 0 END) as measured")
            ->selectRaw('SUM(outages.customers_affected) as customers')
            ->selectRaw('AVG(outages.elapsed_hours) as avg_hours')
            ->groupByRaw($kind.($byMonth ? ", {$month}" : ''))
            ->get();
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
    private function stats(?object $r): array
    {
        $total = (int) ($r->total ?? 0);
        $measured = (int) ($r->measured ?? 0);

        return [
            'total' => $total,
            'closed' => (int) ($r->closed ?? 0),
            'met' => (int) ($r->met ?? 0),
            'measured' => $measured,
            'missed' => $measured - (int) ($r->met ?? 0),
            'customers' => (int) ($r->customers ?? 0),
            'avg_hours' => isset($r->avg_hours) ? round((float) $r->avg_hours, 1) : null,
            'pct' => $measured > 0 ? round(100 * (int) $r->met / $measured, 1) : null,
            'closed_pct' => $total > 0 ? round(100 * (int) $r->closed / $total, 1) : null,
        ];
    }

    private function hours(int $minutes): string
    {
        $hours = $minutes / 60;

        return fmod($hours, 1.0) === 0.0 ? (string) (int) $hours : number_format($hours, 1);
    }
}
