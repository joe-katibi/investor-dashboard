<?php

namespace App\Filament\Pages;

use App\Enums\SlaState;
use App\Filament\Resources\Outages\OutageForm;
use App\Jobs\GenerateReportExportJob;
use App\Models\MajorOutageType;
use App\Models\Olt;
use App\Models\OutageStatus;
use App\Models\OutageType;
use App\Models\Priority;
use App\Models\Region;
use App\Models\ReportExport;
use App\Models\Service;
use App\Models\Team;
use App\Models\User;
use App\Models\Vertical;
use App\Models\VisitType;
use App\Services\Reports\AvailabilityTimeReport;
use App\Services\Reports\DeskSlaReports;
use App\Services\Reports\ProductivityReport;
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
use UnitEnum;

/**
 * Reports (spec §20): 17 reports, shared filters, on-screen view and CSV / Excel / PDF export (prepared in the
 * background, collected from Report Downloads).
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
        $available = ReportRegistry::availableTo(Auth::user());
        $asked = (string) request()->query('report', '');

        $this->form->fill([
            // The report a link asked for (?report=…), when this user may run it; otherwise the first one they may
            // (a 4G-only user starts on the 4G outage report).
            'report' => isset($available[$asked]) ? $asked : array_key_first($available),
            'group' => 'day',
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
                    // The list keeps growing: type part of a name to find a report.
                    Select::make('report')->label('Report')->options(fn () => ReportRegistry::availableTo(Auth::user()))->required()->live()->columnSpan(2)
                        ->searchable()->native(false)->optionsLimit(200)->searchPrompt('Type part of the report name')->noSearchResultsMessage('No report has that in its name.'),
                    DatePicker::make('from')->label('From')->live(),
                    DatePicker::make('to')->label('To')->live(),
                    // Staff productivity: how to group it, which modules and people.
                    Select::make('group')->label('Group by')->default('day')->selectablePlaceholder(false)->live()
                        ->options(fn (Get $get) => $get('report') === AvailabilityTimeReport::KEY ? AvailabilityTimeReport::GROUPS : ProductivityReport::GROUPS)
                        ->visible(fn (Get $get) => in_array($get('report'), [ProductivityReport::KEY, AvailabilityTimeReport::KEY], true)),
                    Select::make('modules')->label('Modules')->multiple()->live()->placeholder('All modules you can see')
                        ->options(fn () => array_intersect_key(ProductivityReport::MODULES, array_flip(app(ProductivityReport::class)->modules())))
                        ->visible(fn (Get $get) => $get('report') === ProductivityReport::KEY || isset(DeskSlaReports::REPORTS[$get('report')])
                            || in_array($get('report'), ['ticket_lifecycle', 'olt_performance'], true)),
                    Select::make('user_ids')->label('People')->multiple()->searchable()->live()->placeholder('Everyone')
                        ->options(fn () => User::query()->orderBy('name')->pluck('name', 'id'))
                        ->visible(fn (Get $get) => in_array($get('report'), [ProductivityReport::KEY, AvailabilityTimeReport::KEY], true))->columnSpan(2),
                ])->columns(4),
                Section::make('Filters')->collapsible()->collapsed()->schema([
                    Select::make('state')->label('State')->options(['active' => 'Active only', 'closed' => 'Closed only'])->placeholder('All')->live(),
                    $multi('status_ids', 'Status', fn () => OutageStatus::query()->orderBy('sort_order')->pluck('name', 'id')),
                    $multi('priority_ids', 'Priority', fn () => Priority::query()->orderBy('queue_rank')->pluck('name', 'id')),
                    $multi('major_type_ids', 'Major type', fn () => MajorOutageType::query()->orderBy('sort_order')->pluck('name', 'id')),
                    $multi('outage_type_ids', 'Outage type', fn () => OutageType::query()->orderBy('sort_order')->pluck('name', 'id')),
                    $multi('vertical_ids', 'Vertical', fn () => Vertical::query()->pluck('name', 'id')),
                    $multi('olt_ids', 'OLT', fn () => Olt::query()->orderBy('name')->pluck('name', 'id')),
                    $multi('region_ids', 'Region (ticket reports)', fn () => Region::query()->orderBy('name')->pluck('name', 'id')),
                    $multi('visit_type_ids', 'Visit type (ticket reports)', fn () => VisitType::query()->orderBy('sort_order')->pluck('name', 'id')),
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

    /**
     * Ask for an export. Nothing is written while the page waits: a queue worker prepares the file
     * (GenerateReportExportJob) and it is collected from Report Downloads, where it is kept six hours.
     */
    public function export(string $format): void
    {
        $user = Auth::user();
        abort_unless($user?->can('reports.export') && isset(ReportExport::FORMATS[$format]), 403);

        $key = 'report-export:'.$user->id;
        if (RateLimiter::tooManyAttempts($key, 10)) {
            Notification::make()->title('Too many exports — try again in a minute')->warning()->send();

            return;
        }
        RateLimiter::hit($key, 60);

        $report = $this->data['report'] ?? null;
        $title = ReportRegistry::availableTo($user)[$report] ?? null;

        if ($title === null) {
            return;
        }

        $filters = $this->filters();
        $export = ReportExport::query()->create([
            'user_id' => $user->id,
            'report_key' => $report,
            'title' => $title,
            'format' => $format,
            'filters' => $filters,
            'date_from' => $filters['from'] ?? null,
            'date_to' => $filters['to'] ?? null,
            'expires_at' => now()->addHours(ReportExport::KEEP_HOURS),
        ]);

        // Who took which report out of the system, in which form and for which period.
        $user->audit('report_exported', array_filter([
            'report' => $title, 'format' => strtoupper($format), 'from' => $filters['from'] ?? null, 'to' => $filters['to'] ?? null,
        ]));

        GenerateReportExportJob::dispatch($export->id);

        Notification::make()
            ->title('Your report is being prepared')
            ->body('You can keep working. It will be under Report Downloads when it is ready, and stays there for '.ReportExport::KEEP_HOURS.' hours.')
            ->success()
            ->actions([Action::make('downloads')->label('Open Report Downloads')->button()->url(ReportDownloads::getUrl())])
            ->send();
    }

    private function filters(): array
    {
        return collect($this->data ?? [])->except('report')->filter(fn ($v) => $v !== null && $v !== '' && $v !== [])->all();
    }
}
