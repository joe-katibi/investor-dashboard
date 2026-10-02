<?php

namespace App\Filament\Pages;

use App\Enums\SlaState;
use App\Filament\Resources\Outages\OutageForm;
use App\Models\MajorOutageType;
use App\Models\Olt;
use App\Models\OutageStatus;
use App\Models\OutageType;
use App\Models\Priority;
use App\Models\Service;
use App\Models\Team;
use App\Models\User;
use App\Models\Vertical;
use App\Services\Reports\ReportExporter;
use App\Services\Reports\ReportRegistry;
use App\Services\Reports\ReportResult;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;
use UnitEnum;

/**
 * Reports (spec §20): 17 reports, shared filters, on-screen view and CSV / Excel / PDF export.
 */
class Reports extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentChartBar;

    protected static string|UnitEnum|null $navigationGroup = 'Reporting';

    protected static ?int $navigationSort = 1;

    protected string $view = 'filament.pages.reports';

    /** @var array<string, mixed> */
    public ?array $data = [];

    public static function canAccess(): bool
    {
        return (bool) Auth::user()?->can('reports.view');
    }

    public function mount(): void
    {
        $this->form->fill([
            // The first report this user may run (a 4G-only user starts on the 4G outage report).
            'report' => array_key_first(ReportRegistry::availableTo(Auth::user())),
            'from' => now()->subDays(6)->toDateString(),
            'to' => now()->toDateString(),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        $multi = fn (string $name, string $label, \Closure $options) => Select::make($name)->label($label)->multiple()->searchable()->options($options)->live();

        return $schema
            ->statePath('data')
            ->components([
                Section::make()->schema([
                    Select::make('report')->label('Report')->options(fn () => ReportRegistry::availableTo(Auth::user()))->required()->live()->columnSpan(2),
                    DatePicker::make('from')->label('From')->live(),
                    DatePicker::make('to')->label('To')->live(),
                ])->columns(4),
                Section::make('Filters')->collapsible()->collapsed()->schema([
                    Select::make('state')->label('State')->options(['active' => 'Active only', 'closed' => 'Closed only'])->placeholder('All')->live(),
                    $multi('status_ids', 'Status', fn () => OutageStatus::query()->orderBy('sort_order')->pluck('name', 'id')),
                    $multi('priority_ids', 'Priority', fn () => Priority::query()->orderBy('queue_rank')->pluck('name', 'id')),
                    $multi('major_type_ids', 'Major type', fn () => MajorOutageType::query()->orderBy('sort_order')->pluck('name', 'id')),
                    $multi('outage_type_ids', 'Outage type', fn () => OutageType::query()->orderBy('sort_order')->pluck('name', 'id')),
                    $multi('vertical_ids', 'Vertical', fn () => Vertical::query()->pluck('name', 'id')),
                    $multi('olt_ids', 'OLT', fn () => Olt::query()->orderBy('name')->pluck('name', 'id')),
                    Select::make('fdt_ids')->label('FDT')->multiple()->searchable()->live()
                        ->options(fn (Get $get) => OutageForm::fdtOptions($get('olt_ids') ?? []))
                        ->disabled(fn (Get $get) => empty($get('olt_ids'))),
                    $multi('service_ids', 'Service', fn () => Service::query()->orderBy('name')->pluck('name', 'id')),
                    $multi('team_ids', 'Team / sub-team', fn () => Team::query()->orderBy('name')->pluck('name', 'id')),
                    $multi('technician_ids', 'Technician', fn () => User::permission('queue.work_technician')->orderBy('name')->pluck('name', 'id')),
                    $multi('sla_states', 'SLA state', fn () => collect(SlaState::cases())->mapWithKeys(fn (SlaState $s) => [$s->value => $s->getLabel()])),
                ])->columns(4),
            ]);
    }

    public function getReport(): ?ReportResult
    {
        $key = $this->data['report'] ?? null;

        return $key && isset(ReportRegistry::availableTo(Auth::user())[$key]) ? app(ReportRegistry::class)->run($key, $this->filters()) : null;
    }

    public function screenLimit(): int
    {
        return (int) config('oms.report_screen_limit', 500);
    }

    protected function getHeaderActions(): array
    {
        return [
            ActionGroup::make([
                Action::make('csv')->label('CSV')->icon(Heroicon::OutlinedDocumentText)->action(fn () => $this->export('csv')),
                Action::make('xlsx')->label('Excel (.xlsx)')->icon(Heroicon::OutlinedTableCells)->action(fn () => $this->export('xlsx')),
                Action::make('pdf')->label('PDF')->icon(Heroicon::OutlinedDocument)->action(fn () => $this->export('pdf')),
            ])
                ->label('Export')
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->button()
                ->visible(fn () => Auth::user()?->can('reports.export')),
        ];
    }

    public function export(string $format): ?Response
    {
        abort_unless(Auth::user()?->can('reports.export'), 403);

        $key = 'report-export:'.Auth::id();
        if (RateLimiter::tooManyAttempts($key, 10)) {
            Notification::make()->title('Too many exports — try again in a minute')->warning()->send();

            return null;
        }
        RateLimiter::hit($key, 60);

        $report = $this->getReport();

        return $report ? app(ReportExporter::class)->download($report, $format, $this->filters()) : null;
    }

    private function filters(): array
    {
        return collect($this->data ?? [])->except('report')->filter(fn ($v) => $v !== null && $v !== '' && $v !== [])->all();
    }
}
