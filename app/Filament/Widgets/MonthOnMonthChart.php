<?php

namespace App\Filament\Widgets;

use App\Services\Reports\MonthlyPerformance;
use Filament\Widgets\ChartWidget;

/**
 * One kind of work per month: within / outside the SLA as stacked bars (count), and the share within the
 * SLA as a line (%). Used on the month-on-month pages only.
 */
abstract class MonthOnMonthChart extends ChartWidget
{
    protected static bool $isDiscovered = false;

    protected ?string $pollingInterval = '300s';

    protected ?string $maxHeight = '340px';

    protected int|string|array $columnSpan = 'full';

    /** outages | visits */
    abstract protected function kind(): string;

    protected function getData(): array
    {
        $kind = $this->kind();
        $months = collect(app(MonthlyPerformance::class)->months())->skipUntil(fn (array $m) => $m[$kind]['total'] > 0)->values();
        $stat = fn (string $field) => $months->map(fn (array $m) => $m[$kind][$field])->all();
        // The % line skips months with too few SLA results to mean anything (the bars still show them).
        $pct = $months->map(fn (array $m) => $m[$kind]['enough'] ? $m[$kind]['pct'] : null)->all();

        return [
            'labels' => $months->pluck('short')->all(),
            'datasets' => [
                ['type' => 'line', 'label' => 'Within SLA %', 'data' => $pct, 'borderColor' => '#2563eb', 'backgroundColor' => '#2563eb', 'borderWidth' => 3, 'pointRadius' => 4, 'spanGaps' => false, 'yAxisID' => 'pct', 'order' => 0],
                ['type' => 'bar', 'label' => 'Within SLA', 'data' => $stat('met'), 'backgroundColor' => 'rgba(22,163,74,0.7)', 'stack' => 'sla', 'yAxisID' => 'count', 'order' => 1],
                ['type' => 'bar', 'label' => 'Outside SLA', 'data' => $stat('missed'), 'backgroundColor' => 'rgba(220,38,38,0.6)', 'stack' => 'sla', 'yAxisID' => 'count', 'order' => 1],
            ],
        ];
    }

    protected function getOptions(): array
    {
        return [
            'interaction' => ['mode' => 'index', 'intersect' => false],
            'plugins' => ['legend' => ['position' => 'bottom']],
            'scales' => [
                'count' => ['beginAtZero' => true, 'stacked' => true, 'position' => 'left', 'title' => ['display' => true, 'text' => 'Count'], 'ticks' => ['precision' => 0]],
                'pct' => ['min' => 0, 'max' => 100, 'position' => 'right', 'grid' => ['drawOnChartArea' => false], 'title' => ['display' => true, 'text' => 'Within SLA %']],
                'x' => ['stacked' => true],
                // Chart.js adds a default "y" axis next to the two named ones; nothing is plotted on it.
                'y' => ['display' => false],
            ],
        ];
    }

    protected function getType(): string
    {
        return 'bar';
    }
}
