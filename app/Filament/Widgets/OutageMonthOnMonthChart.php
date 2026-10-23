<?php

namespace App\Filament\Widgets;

class OutageMonthOnMonthChart extends MonthOnMonthChart
{
    protected ?string $heading = 'Outages restored within the SLA, per month';

    protected function kind(): string
    {
        return 'outages';
    }
}
