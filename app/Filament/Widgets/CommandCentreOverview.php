<?php

namespace App\Filament\Widgets;

use App\Filament\Concerns\RefreshesOnOmsEvents;
use App\Filament\Pages\MobilityDashboard;
use App\Filament\Resources\Outages\OutageResource;
use App\Models\MajorOutageType;
use App\Services\Reports\DashboardMetrics;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/** Live section tiles: Network, Core, System and 4G Mobility (spec §1 command centre). */
class CommandCentreOverview extends StatsOverviewWidget
{
    use RefreshesOnOmsEvents;

    protected static ?int $sort = 0;

    protected ?string $pollingInterval = '60s';

    /** 2 × 2 grid on phones and tablets, one row of four on desktop. */
    protected int|array|null $columns = ['default' => 2, 'xl' => 4];

    protected ?string $heading = 'Live command centre';

    protected function getStats(): array
    {
        $metrics = app(DashboardMetrics::class);
        $major = $metrics->liveByMajorType();
        $mobility = $metrics->mobility();

        $tile = fn (string $code, string $tab) => Stat::make(($major[$code]['name'] ?? ucfirst($code)).' — live', $major[$code]['outages'] ?? 0)
            ->description(number_format($major[$code]['customers'] ?? 0).' customers affected')
            ->color(($major[$code]['outages'] ?? 0) > 0 ? 'danger' : 'success')
            ->url(OutageResource::getUrl('index', ['tab' => $tab]));

        return [
            $tile(MajorOutageType::NETWORK, 'network'),
            $tile(MajorOutageType::CORE, 'core'),
            $tile(MajorOutageType::SYSTEM, 'system'),
            Stat::make('4G Mobility — live', $mobility['active'])
                ->description("Sites {$mobility['sites_down']} · Cells {$mobility['cells_down']} · Power {$mobility['power_faults']}")
                ->color($mobility['active'] > 0 ? 'warning' : 'success')
                ->url(MobilityDashboard::canAccess() ? MobilityDashboard::getUrl() : null),
        ];
    }
}
