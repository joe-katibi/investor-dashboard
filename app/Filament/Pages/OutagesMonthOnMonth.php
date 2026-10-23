<?php

namespace App\Filament\Pages;

use App\Filament\Support\MonthOnMonthPage;
use App\Filament\Widgets\OutageMonthOnMonthChart;
use BackedEnum;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use UnitEnum;

/** Network outages month on month: restored within the SLA, per month. */
class OutagesMonthOnMonth extends MonthOnMonthPage
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    protected static string|UnitEnum|null $navigationGroup = 'Operations';

    protected static ?int $navigationSort = 3;

    protected static ?string $slug = 'outages/month-on-month';

    protected static ?string $navigationLabel = 'Outages month on month';

    protected static ?string $title = 'Outages — month on month';

    public static function canAccess(): bool
    {
        return (bool) Auth::user()?->can('outage.view') && (bool) Auth::user()?->can('dashboard.view');
    }

    protected static function kind(): string
    {
        return 'outages';
    }

    protected function chart(): string
    {
        return OutageMonthOnMonthChart::class;
    }
}
