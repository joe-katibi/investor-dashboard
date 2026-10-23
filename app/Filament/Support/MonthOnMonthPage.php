<?php

namespace App\Filament\Support;

use App\Services\Reports\MonthlyPerformance;
use Filament\Pages\Page;

/**
 * Month-on-month SLA page for one kind of work (network outages or site visits): headline tiles, the monthly
 * chart and the month-by-month table. The figures come from MonthlyPerformance.
 */
abstract class MonthOnMonthPage extends Page
{
    protected string $view = 'filament.pages.month-on-month';

    /** outages | visits */
    abstract protected static function kind(): string;

    /** @return class-string the chart widget of this page */
    abstract protected function chart(): string;

    public function getSubheading(): ?string
    {
        return 'Within SLA = '.app(MonthlyPerformance::class)->rules()[static::kind()].'. A '.static::noun(singular: true).' counts in the month it was raised.';
    }

    protected static function noun(bool $singular = false): string
    {
        return static::kind() === 'outages' ? ($singular ? 'outage' : 'outages') : ($singular ? 'site visit' : 'site visits');
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        $performance = app(MonthlyPerformance::class);
        $kind = static::kind();

        // From the first month this kind has data, newest first in the table.
        $months = collect($performance->months())->skipUntil(fn (array $m) => $m[$kind]['total'] > 0)->values();
        // Latest / best month only among months with enough SLA results to compare.
        $measured = $months->filter(fn (array $m) => $m[$kind]['enough']);
        $latest = $measured->last();
        $best = $measured->sortByDesc(fn (array $m) => $m[$kind]['pct'])->first();

        return [
            'kind' => $kind,
            'noun' => static::noun(),
            'rule' => $performance->rules()[$kind],
            'months' => $months->reverse()->values()->all(),
            'latest' => $latest,
            'best' => $best,
            'total' => $performance->totals()[$kind],
            'chart' => $this->chart(),
        ];
    }
}
