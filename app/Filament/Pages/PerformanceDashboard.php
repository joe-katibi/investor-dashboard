<?php

namespace App\Filament\Pages;

use App\Filament\Widgets\OutagePerformanceChart;
use App\Filament\Widgets\VisitPerformanceChart;
use App\Services\Performance\PerformanceMetrics;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;

/**
 * Investor dashboard: six months of outages and site visits against the targets (93 % / 90 %), measured on
 * the real records. Months the real sources do not cover are filled with demo data, always labelled as such
 * and never mixed into the real figures. The technicians and the live demo trickle are shown below.
 */
class PerformanceDashboard extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowTrendingUp;

    protected static ?int $navigationSort = -1;

    protected static ?string $slug = 'performance';

    protected static ?string $navigationLabel = 'Investor dashboard';

    protected static ?string $title = 'Performance — last 6 months';

    protected string $view = 'filament.pages.performance-dashboard';

    public static function canAccess(): bool
    {
        return (bool) Auth::user()?->can('outage.view') && (bool) Auth::user()?->can('dashboard.view');
    }

    public function getSubheading(): ?string
    {
        $m = app(PerformanceMetrics::class);

        return $m->from()->format('j M Y').' – '.now()->format('j M Y, H:i').'. Real records unless marked DEMO.';
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        $m = app(PerformanceMetrics::class);

        return [
            'config' => config('performance'),
            'months' => $m->months(),
            'outages' => $m->totals('outages'),
            'visits' => $m->totals('visits'),
            'crews' => $m->crews(),
            'technicians' => $m->technicians(),
            'live' => $m->live(),
            'charts' => [OutagePerformanceChart::class, VisitPerformanceChart::class],
        ];
    }
}
