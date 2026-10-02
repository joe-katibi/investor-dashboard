<?php

namespace App\Filament\Pages;

use App\Filament\Concerns\RefreshesOnOmsEvents;
use App\Filament\Resources\MobilityOutages\MobilityOutageResource;
use App\Models\Outage;
use App\Services\Mobility\MobilityMetrics;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Url;
use UnitEnum;

/**
 * 4G NOC dashboard: live picture of 4G outages (by category, severity, SLA), plus vendor and
 * engineer performance and MTTR for the selected period.
 */
class MobilityDashboard extends Page
{
    use RefreshesOnOmsEvents;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPresentationChartLine;

    protected static string|UnitEnum|null $navigationGroup = '4G NOC';

    protected static ?int $navigationSort = 1;

    protected static ?string $slug = '4g/dashboard';

    protected static ?string $navigationLabel = 'Dashboard';

    protected static ?string $title = '4G NOC dashboard';

    protected string $view = 'filament.pages.mobility-dashboard';

    /** Period for the performance figures, in days back from today. */
    #[Url]
    public int $days = 30;

    public const PERIODS = [1 => 'Today', 7 => '7 days', 30 => '30 days', 90 => '90 days'];

    public static function canAccess(): bool
    {
        return (bool) Auth::user()?->can('mobility.view');
    }

    public function getSubheading(): ?string
    {
        return 'Live 4G outages. Performance figures cover '.strtolower(self::PERIODS[$this->period()] ?? $this->period().' days').'.';
    }

    protected function getHeaderActions(): array
    {
        return [
            ...collect(self::PERIODS)->map(fn (string $label, int $days) => Action::make('period_'.$days)
                ->label($label)
                ->color(fn () => $this->period() === $days ? 'primary' : 'gray')
                ->outlined(fn () => $this->period() !== $days)
                ->action(fn () => $this->days = $days))->values()->all(),
            Action::make('report')->label('Report 4G outage')->icon(Heroicon::OutlinedPlus)
                ->visible(fn () => Auth::user()?->can('createMobility', Outage::class))
                ->url(fn () => MobilityOutageResource::getUrl('create')),
        ];
    }

    protected function getViewData(): array
    {
        $metrics = app(MobilityMetrics::class);
        [$from, $to] = $this->range();

        return [
            'k' => $metrics->overview($from, $to),
            'vendors' => $metrics->vendors($from, $to),
            'engineers' => $metrics->engineers($from, $to),
            'open' => Outage::query()->mobility()->active()
                ->with(['priority:id,name,color,queue_rank', 'status:id,name,color', 'vendor:id,name', 'technician:id,name', 'currentAssignee:id,name', 'enodebs:id,site_id,name', 'transmissionNodes:id,code,name'])
                ->orderBy('sla_due_at')->limit(25)->get(),
            'tab' => fn (string $tab) => MobilityOutageResource::getUrl('index', ['tab' => $tab]),
            'human' => fn (?int $minutes) => MobilityMetrics::human($minutes),
        ];
    }

    private function period(): int
    {
        return array_key_exists($this->days, self::PERIODS) ? $this->days : 30;
    }

    /** @return array{0: Carbon, 1: Carbon} */
    private function range(): array
    {
        return [now()->subDays($this->period() - 1)->startOfDay(), now()->endOfDay()];
    }
}
