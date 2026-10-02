<?php

namespace App\Filament\Widgets;

use App\Filament\Concerns\RefreshesOnOmsEvents;
use App\Filament\Resources\Outages\OutageResource;
use App\Services\Reports\DashboardMetrics;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class OutageKpiOverview extends StatsOverviewWidget
{
    use InteractsWithPageFilters, RefreshesOnOmsEvents;

    protected static ?int $sort = 1;

    protected ?string $pollingInterval = '60s';

    /** Two per row on phones, three on tablets, one row on desktop. */
    protected int|array|null $columns = ['default' => 2, 'md' => 3, 'xl' => 5];

    protected ?string $heading = 'Outage overview';

    protected function getStats(): array
    {
        $k = app(DashboardMetrics::class)->kpis($this->pageFilters ?? []);

        return [
            Stat::make('Total outages (period)', number_format($k['total']))
                ->description("Regular {$k['regular']} · Emergency {$k['emergency']} · Planned {$k['planned']}")
                ->chart($k['trend'])->color('gray'),
            Stat::make('Active outages', number_format($k['active']))
                ->description('Not in a closed / resolved state')->descriptionIcon('heroicon-m-bolt')->color($k['active'] > 0 ? 'warning' : 'success')
                ->url(OutageResource::getUrl('index', ['tab' => 'active'])),
            Stat::make('Critical (active)', number_format($k['critical']))
                ->descriptionIcon('heroicon-m-exclamation-triangle')->description('Critical priority, still open')
                ->color($k['critical'] > 0 ? 'danger' : 'success')
                ->url(OutageResource::getUrl('index', ['tab' => 'critical'])),
            Stat::make('Customers affected (active)', number_format($k['customers']))->descriptionIcon('heroicon-m-users')->color('info'),
            Stat::make('Resolved (period)', number_format($k['resolved']))->descriptionIcon('heroicon-m-check-circle')->color('success'),
        ];
    }
}
