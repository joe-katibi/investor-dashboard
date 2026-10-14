<?php

namespace App\Filament\Pages;

use App\Models\User;
use App\Services\Reports\AvailabilityTimeReport;
use App\Services\Reports\ReportResult;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use UnitEnum;

/**
 * How long people were Available, Busy, On break and Offline — day by day, week by week or month
 * by month — next to My Work. Each person sees their own time; whoever reads reports sees
 * everyone's (and can export it from Reports).
 */
class AvailabilityTime extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClock;

    protected static string|UnitEnum|null $navigationGroup = 'Operations';

    protected static ?int $navigationSort = 2;

    protected static ?string $slug = 'availability-time';

    protected static ?string $title = 'Availability Time';

    /** The same table as the Reports page. */
    protected string $view = 'filament.pages.reports';

    /** @var array<string, mixed> */
    public ?array $data = [];

    public static function canAccess(): bool
    {
        // Everyone who works tickets — the same people as My Work.
        return (bool) Auth::user()?->canAny(['outage.view', 'mobility.view', 'ticket.view']);
    }

    public static function seesEveryone(): bool
    {
        return (bool) Auth::user()?->can('reports.view');
    }

    public function getSubheading(): ?string
    {
        return self::seesEveryone()
            ? 'How long each person was available, busy, on break and offline. Choose the people, or leave it on everyone.'
            : 'How long you were available, busy, on break and offline.';
    }

    public function mount(): void
    {
        $this->form->fill([
            'group' => 'day',
            'from' => now()->subDays(6)->toDateString(),
            'to' => now()->toDateString(),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Section::make()->schema([
                    Select::make('group')->label('Show')->options(AvailabilityTimeReport::GROUPS)->default('day')->selectablePlaceholder(false)->live(),
                    DatePicker::make('from')->label('From')->live()->maxDate(now()),
                    DatePicker::make('to')->label('To')->live(),
                    Select::make('user_ids')->label('People')->multiple()->searchable()->live()->placeholder('Everyone')
                        ->options(fn () => User::query()->active()->orderBy('name')->pluck('name', 'id'))
                        ->visible(fn () => self::seesEveryone()),
                ])->columns(['default' => 1, 'md' => 4]),
            ]);
    }

    public function getReport(): ?ReportResult
    {
        $filters = array_filter($this->data ?? [], fn ($value) => $value !== null && $value !== '' && $value !== []);

        // Without the right to read reports, a person sees their own time only — whatever the form says.
        if (! self::seesEveryone()) {
            $filters['user_ids'] = [Auth::id()];
        }

        return app(AvailabilityTimeReport::class)->run($filters);
    }

    public function screenLimit(): int
    {
        return (int) config('oms.report_screen_limit', 500);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('export')->label('Export')->icon(Heroicon::OutlinedArrowDownTray)->color('gray')
                ->visible(fn () => (bool) Auth::user()?->can('reports.export'))
                ->url(fn () => Reports::getUrl(['report' => AvailabilityTimeReport::KEY])),
        ];
    }
}
