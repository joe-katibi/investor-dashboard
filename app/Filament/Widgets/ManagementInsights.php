<?php

namespace App\Filament\Widgets;

use App\Filament\Concerns\RefreshesOnOmsEvents;
use App\Services\Reports\DashboardMetrics;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\Widget;

/** Team performance, top impacted OLTs / services and FIFO queue health. */
class ManagementInsights extends Widget
{
    use InteractsWithPageFilters, RefreshesOnOmsEvents;

    protected static ?int $sort = 20;

    protected string $view = 'filament.widgets.management-insights';

    protected int|string|array $columnSpan = 'full';

    protected function getViewData(): array
    {
        $metrics = app(DashboardMetrics::class);
        $filters = $this->pageFilters ?? [];

        return [
            'teams' => $metrics->teamPerformance($filters),
            'olts' => $metrics->topOlts($filters),
            'services' => $metrics->topServices($filters),
            'queues' => $metrics->queues(),
        ];
    }
}
