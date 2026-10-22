<?php

namespace App\Filament\Widgets;

use App\Services\Performance\PerformanceMetrics;
use Filament\Widgets\ChartWidget;

/**
 * Six months of one kind (outages / site visits): the share finished within the service window, real and
 * demo as separate lines, the target as a dashed line, the internal SLA as a thin line, and the volume as bars.
 * Shown on the Performance page only.
 */
abstract class PerformanceTrendChart extends ChartWidget
{
    protected static bool $isDiscovered = false;

    /** Months with fewer measured rows than this get no percentage point. */
    public const MIN_MEASURED = 20;

    protected ?string $pollingInterval = '60s';

    protected ?string $maxHeight = '320px';

    protected int|string|array $columnSpan = 'full';

    /** outages | visits */
    abstract protected function kind(): string;

    protected function getData(): array
    {
        $kind = $this->kind();
        $config = config("performance.{$kind}");
        $months = app(PerformanceMetrics::class)->months();
        // A percentage of a handful of rows (the tracker's one ticket of 31 August) is not plotted.
        $col = fn (string $origin, string $field) => array_map(fn (array $m) => str_ends_with($field, '_pct') && $m[$kind][$origin]['measured'] < self::MIN_MEASURED
            ? null : $m[$kind][$origin][$field], $months);
        $window = rtrim(rtrim(number_format((float) $config['window_hours'], 1), '0'), '.');

        return [
            'labels' => array_map(fn (array $m) => $m[$kind]['real']['total'] === 0 && $m[$kind]['demo']['total'] > 0 ? $m['label'].' (demo)' : $m['label'], $months),
            'datasets' => [
                ['type' => 'line', 'label' => "Within {$window} h — real", 'data' => $col('real', 'within_pct'), 'borderColor' => '#16a34a', 'backgroundColor' => '#16a34a', 'borderWidth' => 3, 'pointRadius' => 4, 'spanGaps' => false, 'yAxisID' => 'y', 'order' => 1],
                ['type' => 'line', 'label' => "Within {$window} h — DEMO data", 'data' => $col('demo', 'within_pct'), 'borderColor' => '#d97706', 'backgroundColor' => '#d97706', 'borderWidth' => 2, 'borderDash' => [5, 4], 'pointStyle' => 'triangle', 'pointRadius' => 5, 'spanGaps' => false, 'yAxisID' => 'y', 'order' => 2],
                ['type' => 'line', 'label' => 'Target '.$config['target'].'%', 'data' => array_fill(0, count($months), $config['target']), 'borderColor' => '#dc2626', 'borderWidth' => 2, 'borderDash' => [8, 6], 'pointRadius' => 0, 'fill' => false, 'yAxisID' => 'y', 'order' => 0],
                ['type' => 'line', 'label' => 'Internal SLA — real', 'data' => $col('real', 'internal_pct'), 'borderColor' => '#64748b', 'backgroundColor' => '#64748b', 'borderWidth' => 1, 'borderDash' => [2, 3], 'pointRadius' => 2, 'spanGaps' => false, 'yAxisID' => 'y', 'order' => 3],
                ['type' => 'bar', 'label' => 'Volume — real', 'data' => $col('real', 'total'), 'backgroundColor' => 'rgba(37,99,235,0.18)', 'borderColor' => 'rgba(37,99,235,0.4)', 'borderWidth' => 1, 'stack' => 'volume', 'yAxisID' => 'y1', 'order' => 9],
                ['type' => 'bar', 'label' => 'Volume — DEMO data', 'data' => $col('demo', 'total'), 'backgroundColor' => 'rgba(217,119,6,0.18)', 'borderColor' => 'rgba(217,119,6,0.45)', 'borderWidth' => 1, 'stack' => 'volume', 'yAxisID' => 'y1', 'order' => 9],
            ],
        ];
    }

    protected function getOptions(): array
    {
        return [
            'interaction' => ['mode' => 'index', 'intersect' => false],
            'plugins' => ['legend' => ['position' => 'bottom']],
            'scales' => [
                'y' => ['min' => 0, 'max' => 100, 'position' => 'left', 'title' => ['display' => true, 'text' => '%'], 'ticks' => ['stepSize' => 10]],
                'y1' => ['beginAtZero' => true, 'stacked' => true, 'position' => 'right', 'grid' => ['drawOnChartArea' => false], 'title' => ['display' => true, 'text' => 'Volume'], 'ticks' => ['precision' => 0]],
                'x' => ['stacked' => true],
            ],
        ];
    }

    protected function getType(): string
    {
        return 'bar';
    }
}
