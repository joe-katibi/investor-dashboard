<?php

namespace App\Filament\Widgets;

class VisitPerformanceChart extends PerformanceTrendChart
{
    public function getHeading(): ?string
    {
        return 'Site visits — completed within '.config('performance.visits.window_hours').' business hours, per month';
    }

    protected function kind(): string
    {
        return 'visits';
    }
}
