<?php

namespace App\Filament\Widgets;

class SiteVisitMonthOnMonthChart extends MonthOnMonthChart
{
    protected ?string $heading = 'Site visits completed within the SLA, per month';

    protected function kind(): string
    {
        return 'visits';
    }
}
