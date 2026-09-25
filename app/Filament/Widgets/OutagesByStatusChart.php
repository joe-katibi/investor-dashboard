<?php

namespace App\Filament\Widgets;

use App\Filament\Concerns\RefreshesOnOmsEvents;
use App\Services\Reports\DashboardMetrics;
use Filament\Widgets\ChartWidget;
use Filament\Widgets\Concerns\InteractsWithPageFilters;

class OutagesByStatusChart extends ChartWidget
{
    use InteractsWithPageFilters, RefreshesOnOmsEvents;

    public const PALETTE = [
        'gray' => '#9ca3af', 'info' => '#3b82f6', 'primary' => '#d97706', 'success' => '#16a34a', 'warning' => '#f97316', 'danger' => '#dc2626',
    ];

    protected static ?int $sort = 4;

    protected ?string $heading = 'Active outages by status';

    protected ?string $pollingInterval = '120s';

    protected ?string $maxHeight = '280px';

    protected function getData(): array
    {
        $rows = app(DashboardMetrics::class)->byStatus($this->pageFilters ?? []);

        return [
            'datasets' => [[
                'data' => array_column($rows, 'value'),
                'backgroundColor' => array_map(fn ($c) => self::PALETTE[$c] ?? '#9ca3af', array_column($rows, 'color')),
            ]],
            'labels' => array_column($rows, 'label'),
        ];
    }

    protected function getOptions(): array
    {
        return ['plugins' => ['legend' => ['position' => 'bottom']], 'scales' => ['x' => ['display' => false], 'y' => ['display' => false]]];
    }

    protected function getType(): string
    {
        return 'doughnut';
    }
}
