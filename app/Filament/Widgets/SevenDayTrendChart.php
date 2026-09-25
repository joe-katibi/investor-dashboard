<?php

namespace App\Filament\Widgets;

use App\Filament\Concerns\RefreshesOnOmsEvents;
use App\Services\Reports\DashboardMetrics;
use Filament\Widgets\ChartWidget;
use Filament\Widgets\Concerns\InteractsWithPageFilters;

class SevenDayTrendChart extends ChartWidget
{
    use InteractsWithPageFilters, RefreshesOnOmsEvents;

    protected static ?int $sort = 3;

    protected ?string $heading = '7-day trend';

    protected ?string $pollingInterval = '120s';

    protected ?string $maxHeight = '280px';

    protected int|string|array $columnSpan = ['md' => 2, 'xl' => 2];

    protected function getData(): array
    {
        $rows = app(DashboardMetrics::class)->trend($this->pageFilters ?? []);

        return [
            'datasets' => [
                ['label' => 'Outages', 'data' => array_column($rows, 'outages'), 'borderColor' => '#d97706', 'backgroundColor' => 'rgba(217,119,6,0.15)', 'fill' => true, 'yAxisID' => 'y'],
                ['label' => 'Resolved', 'data' => array_column($rows, 'resolved'), 'borderColor' => '#16a34a', 'backgroundColor' => 'rgba(22,163,74,0.1)', 'yAxisID' => 'y'],
                ['label' => 'Customers affected', 'data' => array_column($rows, 'customers'), 'borderColor' => '#2563eb', 'borderDash' => [6, 4], 'yAxisID' => 'y1'],
            ],
            'labels' => array_column($rows, 'label'),
        ];
    }

    protected function getOptions(): array
    {
        return [
            'scales' => [
                'y' => ['beginAtZero' => true, 'position' => 'left', 'ticks' => ['precision' => 0]],
                'y1' => ['beginAtZero' => true, 'position' => 'right', 'grid' => ['drawOnChartArea' => false]],
            ],
        ];
    }

    protected function getType(): string
    {
        return 'line';
    }
}
