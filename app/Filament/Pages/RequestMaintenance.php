<?php

namespace App\Filament\Pages;

use App\Filament\Resources\Outages\OutageForm;
use App\Filament\Resources\Outages\OutageResource;
use App\Models\ChangeRequest;
use App\Models\Service;
use App\Models\Vertical;
use App\Services\Outages\OutageWorkflow;
use BackedEnum;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use UnitEnum;

/**
 * Infrastructure raises a planned maintenance (upgrade, migration, …). NOC then confirms the
 * affected customers, completes the CRF and collects the sign-offs.
 */
class RequestMaintenance extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static string|UnitEnum|null $navigationGroup = 'Operations';

    protected static ?int $navigationSort = 3;

    protected static ?string $slug = 'maintenance/request';

    protected static ?string $navigationLabel = 'Request maintenance';

    protected static ?string $title = 'Request planned maintenance';

    protected string $view = 'filament.pages.request-maintenance';

    public ?array $data = [];

    public static function canAccess(): bool
    {
        return (bool) Auth::user()?->can('maintenance.request');
    }

    public function getSubheading(): ?string
    {
        return 'Goes to NOC to confirm the affected customers and issue the Maintenance & Change Request Form (CRF) for sign-off.';
    }

    public function mount(): void
    {
        $this->form->fill([
            'initiator' => (Auth::user()?->department?->name ?? 'Infrastructure').' Team',
            'department' => 'NOC',
            'risk_tier' => 'medium',
            'vertical_id' => Vertical::query()->where('code', Vertical::FTTH)->value('id'),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->statePath('data')->components([
            Section::make('1. General maintenance details')->icon(Heroicon::OutlinedCalendarDays)->schema([
                TextInput::make('title')->label('Title / subject')->required()->maxLength(255)
                    ->placeholder('e.g. Kabowa FDT 17 migration to Seguku')->columnSpanFull(),
                DateTimePicker::make('scheduled_start')->label('Scheduled start')->required()->seconds(false)->minDate(now()->subMinutes(5))->live(),
                DateTimePicker::make('scheduled_end')->label('Scheduled end')->required()->seconds(false)
                    ->after('scheduled_start')->minDate(fn (Get $get) => $get('scheduled_start')),
                TextInput::make('initiator')->maxLength(150),
                TextInput::make('department')->maxLength(150),
                TextInput::make('in_charge')->label('In-charge / lead')->maxLength(150),
                TextInput::make('emergency_contact')->label('Emergency contact')->tel()->maxLength(100),
                TextInput::make('escalation_time')->label('Escalation time')->maxLength(100)->placeholder('optional'),
                TextInput::make('rollback_time')->label('Rollback time')->maxLength(100)->placeholder('optional'),
            ])->columns(2),

            Section::make('Affected area')->icon(Heroicon::OutlinedShare)
                ->description('OLT → FDT → Slot → Port. Pick "ALL" to select every item at that level.')
                ->schema([
                    Select::make('vertical_id')->label('Vertical')->required()->live()
                        ->options(fn () => Vertical::query()->active()->orderBy('name')->pluck('name', 'id'))
                        ->afterStateUpdated(fn (Set $set) => $set('service_ids', [])),
                    TextInput::make('location')->label('Location / area')->maxLength(255),
                    ...OutageForm::topologyFields(),
                    TextInput::make('customers_affected')->label('Estimated customers affected')->numeric()->minValue(0)->maxValue(10_000_000)->default(0)
                        ->helperText('NOC confirms the final number before issuing the CRF.'),
                    Select::make('service_ids')->label('Affected services')->multiple()->searchable()
                        ->options(fn (Get $get) => Service::query()->active()
                            ->where(fn ($q) => $q->whereNull('vertical_id')->when($get('vertical_id'), fn ($q2, $v) => $q2->orWhere('vertical_id', $v)))
                            ->orderBy('name')->pluck('name', 'id')),
                ])->columns(2),

            Section::make('2–3. Risk, technical scope & fallback planning')->icon(Heroicon::OutlinedWrenchScrewdriver)->schema([
                Radio::make('risk_tier')->label('Risk tier')->required()->inline()
                    ->options(collect(ChangeRequest::RISK_TIERS)->map(fn (array $tier) => $tier[0])->all())
                    ->descriptions(collect(ChangeRequest::RISK_TIERS)->map(fn (array $tier) => $tier[1])->all()),
                Textarea::make('work_description')->label('Detail / description of work')->required()->rows(3)->maxLength(5000),
                Textarea::make('fallback_plan')->label('Recovery & fallback plan')->rows(2)->maxLength(5000),
                Textarea::make('prior_tests')->label('Prior test details')->rows(2)->maxLength(5000),
                Textarea::make('risks_mitigation')->label('Identified risks & mitigation')->rows(2)->maxLength(5000),
            ]),

            Section::make('Customer list')->icon(Heroicon::OutlinedPaperClip)->schema([
                FileUpload::make('customer_list_path')->label('Affected customer list (optional)')
                    ->disk('local')->directory('crf-customer-lists')->visibility('private')
                    ->acceptedFileTypes([
                        'text/csv', 'application/pdf', 'application/vnd.ms-excel',
                        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                    ])
                    ->maxSize(10240)
                    ->storeFileNamesIn('customer_list_name')
                    ->helperText('Excel, CSV or PDF (max 10 MB). Attached to the CRF e-mails.'),
            ]),
        ]);
    }

    public function submit(): void
    {
        $data = $this->form->getState();

        try {
            $outage = app(OutageWorkflow::class)->requestMaintenance($data, Auth::user());
        } catch (ValidationException $exception) {
            Notification::make()->title('Request not submitted')->body(implode(' ', $exception->validator->errors()->all()))->danger()->send();

            return;
        } catch (AuthorizationException $exception) {
            Notification::make()->title('Not allowed')->body($exception->getMessage())->danger()->send();

            return;
        }

        Notification::make()->title("Planned maintenance {$outage->ticket_number} sent to NOC")->success()->send();
        $this->redirect(OutageResource::getUrl('view', ['record' => $outage]));
    }
}
