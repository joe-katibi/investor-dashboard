<?php

namespace App\Filament\Widgets;

use App\Filament\Concerns\RefreshesOnOmsEvents;
use App\Filament\Resources\Outages\OutageResource;
use App\Services\Reports\DashboardMetrics;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class SlaCustomerOverview extends StatsOverviewWidget
{
    use InteractsWithPageFilters, RefreshesOnOmsEvents;

    protected static ?int $sort = 2;

    protected ?string $pollingInterval = '60s';

    /** Two per row on phones, three on tablets, one row on desktop. */
    protected int|array|null $columns = ['default' => 2, 'md' => 3, 'xl' => 6];

    protected ?string $heading = 'SLA & customer impact';

    protected function getStats(): array
    {
        $s = app(DashboardMetrics::class)->sla($this->pageFilters ?? []);

        return [
            Stat::make('SLA compliant', $s['compliant'])->description('Active, inside SLA')->color('success'),
            Stat::make('Approaching breach', $s['at_risk'])->description('Pre-breach window')->color($s['at_risk'] ? 'warning' : 'gray')
                ->url(OutageResource::getUrl('index', ['tab' => 'breached'])),
            Stat::make('SLA breached', $s['breached'])->description('Active, past SLA')->color($s['breached'] ? 'danger' : 'gray')
                ->url(OutageResource::getUrl('index', ['tab' => 'breached'])),
            Stat::make('Resolved within SLA', $s['compliance'] !== null ? $s['compliance'].'%' : 'n/a')
                ->description("{$s['met']} met · {$s['missed']} missed (period)")->color('info'),
            Stat::make('SMS sent / not sent', "{$s['sms_sent']} / {$s['sms_pending']}")->description('Active outages')->color('primary'),
            Stat::make('Credits shared / due', "{$s['credit_shared']} / {$s['credit_pending']}")->description('Due = breached, not yet shared')
                ->color($s['credit_pending'] ? 'danger' : 'primary'),
        ];
    }
}
