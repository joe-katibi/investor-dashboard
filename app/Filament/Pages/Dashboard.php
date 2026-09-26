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
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
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

    public static function canAccess(): bool
    {
        return (bool) Auth::user()?->can('outage.view');
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
