<?php

namespace App\Filament\Pages;

use App\Filament\Support\MonthOnMonthPage;
use App\Filament\Widgets\SiteVisitMonthOnMonthChart;
use BackedEnum;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use UnitEnum;

/** Site visits month on month: completed within the SLA, per month. */
class SiteVisitsMonthOnMonth extends MonthOnMonthPage
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    protected static string|UnitEnum|null $navigationGroup = 'Customer Care';

    protected static ?int $navigationSort = 2;

    protected static ?string $slug = 'customer-care/site-visits-month-on-month';

    protected static ?string $navigationLabel = 'Site visits month on month';

    protected static ?string $title = 'Site visits — month on month';

    public static function canAccess(): bool
    {
        return (bool) Auth::user()?->can('ticket.view') && (bool) Auth::user()?->can('ticket.dashboard');
    }

    protected static function kind(): string
    {
        return 'visits';
    }

    protected function chart(): string
    {
        return SiteVisitMonthOnMonthChart::class;
    }
}
