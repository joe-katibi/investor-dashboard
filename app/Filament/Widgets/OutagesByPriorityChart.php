<?php

namespace App\Filament\Widgets;

use App\Filament\Concerns\RefreshesOnOmsEvents;
use App\Services\Reports\DashboardMetrics;
use Filament\Widgets\ChartWidget;
use Filament\Widgets\Concerns\InteractsWithPageFilters;

class OutagesByPriorityChart extends ChartWidget
{
    use InteractsWithPageFilters, RefreshesOnOmsEvents;

    protected static ?int $sort = 5;

    protected ?string $heading = 'Outages by priority';

    protected ?string $pollingInterval = '120s';

    protected ?string $maxHeight = '280px';

    protected function getData(): array
    {
        $rows = app(DashboardMetrics::class)->byPriority($this->pageFilters ?? []);
        $colors = array_map(fn ($c) => OutagesByStatusChart::PALETTE[$c] ?? '#9ca3af', array_column($rows, 'color'));

        return [
            'datasets' => [
                ['label' => 'Period', 'data' => array_column($rows, 'period'), 'backgroundColor' => $colors],
                ['label' => 'Active now', 'data' => array_column($rows, 'active'), 'backgroundColor' => '#6b7280'],
            ],
            'labels' => array_column($rows, 'label'),
        ];
    }

    protected function getOptions(): array
    {
        return ['scales' => ['y' => ['beginAtZero' => true, 'ticks' => ['precision' => 0]]]];
    }

    protected function getType(): string
    {
        return 'bar';
    }
}
