<?php

namespace App\Filament\Widgets;

class OutagePerformanceChart extends PerformanceTrendChart
{
    public function getHeading(): ?string
    {
        return 'Network outages — restored within '.config('performance.outages.window_hours').' h, per month';
    }

    protected function kind(): string
    {
        return 'outages';
    }
}
