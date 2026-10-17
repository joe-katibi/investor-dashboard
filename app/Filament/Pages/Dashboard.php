<?php

namespace App\Filament\Pages;

use App\Filament\Widgets\CommandCentreOverview;
use App\Filament\Widgets\CoreOutagesTable;
use App\Filament\Widgets\ManagementInsights;
use App\Filament\Widgets\NetworkOutagesTable;
use App\Filament\Widgets\OutageKpiOverview;
use App\Filament\Widgets\OutagesByPriorityChart;
use App\Filament\Widgets\OutagesByStatusChart;
use App\Filament\Widgets\RecentOutagesTable;
use App\Filament\Widgets\SevenDayTrendChart;
use App\Filament\Widgets\SlaCustomerOverview;
use App\Filament\Widgets\SystemOutagesTable;
use App\Models\MajorOutageType;
use App\Models\Vertical;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Navigation\NavigationGroup;
use Filament\Navigation\NavigationItem;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Pages\Dashboard\Concerns\HasFiltersForm;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;

/** Executive dashboard / command centre (spec §1 and §3). */
class Dashboard extends BaseDashboard
{
    use HasFiltersForm;

    protected static ?string $title = 'Command Centre';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPresentationChartLine;

    protected static ?int $navigationSort = 0;

    /**
     * The panel's home: everyone signs in here. Whoever may not see the Command Centre (a site visit dispatcher, a
     * technician…) is sent on to the first page of their own menu instead of a 403.
     */
    public static function canAccess(): bool
    {
        return Auth::check();
    }

    public static function shouldRegisterNavigation(): bool
    {
        return self::allowed();
    }

    private static function allowed(): bool
    {
        return (bool) Auth::user()?->can('outage.view') && (bool) Auth::user()?->can('dashboard.view');
    }

    public function mount(): void
    {
        if (self::allowed()) {
            return;
        }

        $first = collect(Filament::getNavigation())
            ->flatMap(fn (NavigationGroup $group) => $group->getItems())
            ->map(fn (NavigationItem $item) => $item->getUrl())
            ->first(fn (?string $url) => filled($url) && $url !== self::getUrl());

        $first ? $this->redirect($first) : abort(403, 'Your account has no pages to open yet. Ask an administrator to give your role its permissions.');
    }

    public function filtersForm(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->schema([
                DatePicker::make('from')->label('From')->default(now()->subDays(6)->toDateString())->maxDate(now()),
                DatePicker::make('to')->label('To')->default(now()->toDateString())->maxDate(now()),
                Select::make('vertical_id')->label('Vertical')->placeholder('All verticals')
                    ->options(fn () => Vertical::query()->active()->pluck('name', 'id')),
                Select::make('major_outage_type_id')->label('Major type')->placeholder('All types')
                    ->options(fn () => MajorOutageType::query()->active()->orderBy('sort_order')->pluck('name', 'id')),
            ])->columns(['default' => 2, 'lg' => 4])->columnSpanFull(),
        ]);
    }

    public function getColumns(): int|array
    {
        return ['md' => 2, 'xl' => 3];
    }

    public function getWidgets(): array
    {
        // Nothing of the Command Centre for someone on their way to their own page.
        if (! self::allowed()) {
            return [];
        }

        return [
            CommandCentreOverview::class,
            OutageKpiOverview::class,
            SlaCustomerOverview::class,
            SevenDayTrendChart::class,
            OutagesByStatusChart::class,
            NetworkOutagesTable::class,
            CoreOutagesTable::class,
            SystemOutagesTable::class,
            OutagesByPriorityChart::class,
            ManagementInsights::class,
            RecentOutagesTable::class,
        ];
    }
}
