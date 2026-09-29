<?php

namespace App\Filament\Pages;

use App\Filament\Resources\Outages\OutageActions;
use App\Filament\Resources\Outages\OutageForm;
use App\Filament\Resources\Outages\OutageResource;
use App\Models\OutagePhoto;
use App\Models\OutageType;
use App\Models\Vertical;
use App\Services\Outages\OutageWorkflow;
use BackedEnum;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use UnitEnum;

/**
 * Infrastructure technician on site: raise preventive maintenance (needs Dispatcher + Infrastructure
 * Manager approval of the downtime) or corrective maintenance (work starts at once), with photos.
 */
class RaiseFieldMaintenance extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCamera;

    protected static string|UnitEnum|null $navigationGroup = 'Operations';

    protected static ?int $navigationSort = 4;

    protected static ?string $slug = 'maintenance/field';

    protected static ?string $navigationLabel = 'Raise field maintenance';

    protected static ?string $title = 'Raise preventive / corrective maintenance';

    protected string $view = 'filament.pages.raise-field-maintenance';

    public ?array $data = [];

    public static function canAccess(): bool
    {
        return (bool) Auth::user()?->can('field_maintenance.raise');
    }

    public function mount(): void
    {
        $this->form->fill([
            'kind' => OutageType::CORRECTIVE,
            'vertical_id' => Vertical::query()->where('code', Vertical::FTTH)->value('id'),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        $photo = fn (string $name, string $label, string $hint) => OutageActions::photoUpload($name, $label, $hint);

        return $schema->statePath('data')->components([
            Section::make('What')->schema([
                Radio::make('kind')->label('Maintenance type')->required()->live()->options([
                    OutageType::CORRECTIVE => 'Corrective — something is broken (e.g. fallen pole, dropped cable); work starts now',
                    OutageType::PREVENTIVE => 'Preventive — an issue found that needs a planned downtime; Dispatcher and Infrastructure Manager approve first',
                ]),
                TextInput::make('title')->label('Title')->required()->maxLength(255)
                    ->placeholder(fn (Get $get) => $get('kind') === OutageType::PREVENTIVE ? 'e.g. Replace water-damaged splitter in FAT 12' : 'e.g. Pole down on Kabowa road — drop cables cut'),
                Textarea::make('description')->required()->rows(3)->maxLength(5000)
                    ->label(fn (Get $get) => $get('kind') === OutageType::PREVENTIVE ? 'Issue found on site' : 'What happened / work needed'),
            ]),

            Section::make('Where')->schema([
                TextInput::make('site_label')->label('FAT / FDT label')->required()->maxLength(120)->placeholder('As written on the box'),
                TextInput::make('location')->label('Location / area')->maxLength(255),
                Select::make('vertical_id')->label('Vertical')->required()
                    ->options(fn () => Vertical::query()->active()->orderBy('name')->pluck('name', 'id')),
                TextInput::make('customers_affected')->label('Customers affected (estimate)')->numeric()->minValue(0)->maxValue(10_000_000)->default(0),
                ...OutageForm::topologyFields(),
            ])->columns(2),

            Section::make('Proposed downtime')->description('The Dispatcher and the Infrastructure Manager approve this window; everyone is then notified.')
                ->visible(fn (Get $get) => $get('kind') === OutageType::PREVENTIVE)
                ->schema([
                    DateTimePicker::make('downtime_start')->label('Downtime start')->seconds(false)->required()
                        ->minDate(fn () => now()->subMinutes(5)->startOfMinute())->live(),
                    DateTimePicker::make('downtime_end')->label('Downtime end')->seconds(false)->required()->after('downtime_start')
                        ->minDate(fn (Get $get) => filled($get('downtime_start')) ? Carbon::parse($get('downtime_start'))->startOfMinute() : null),
                ])->columns(2),

            Section::make('Photos before the work')->description('Two photos of the FAT / FDT as found.')->schema([
                $photo('photo_outside', 'Outside', OutagePhoto::POSITIONS['outside']),
                $photo('photo_inside', 'Inside', OutagePhoto::POSITIONS['inside']),
            ])->columns(2),

            Section::make('Support technician')->description('Drop cable to the customer premises — worked in parallel on the same ticket.')
                ->visible(fn (Get $get) => $get('kind') === OutageType::CORRECTIVE)
                ->schema([
                    Toggle::make('request_support')->label('Request a support technician')->live(),
                    Textarea::make('support_work')->label('Work required')->rows(2)->maxLength(2000)
                        ->placeholder('e.g. Re-run the dropped cables from the FAT to 6 premises')
                        ->visible(fn (Get $get) => (bool) $get('request_support'))
                        ->required(fn (Get $get) => (bool) $get('request_support')),
                    TextInput::make('support_customers')->label('Customer(s) — name / account / phone')->maxLength(255)
                        ->visible(fn (Get $get) => (bool) $get('request_support')),
                ]),
        ]);
    }

    public function submit(): void
    {
        $data = $this->form->getState();

        $payload = array_merge($data, [
            'photos' => [
                'outside' => $data['photo_outside'] ?? null,
                'inside' => $data['photo_inside'] ?? null,
                'outside_name' => $data['photo_outside_name'] ?? null,
                'inside_name' => $data['photo_inside_name'] ?? null,
            ],
            'support' => ! empty($data['request_support'])
                ? ['work_required' => $data['support_work'] ?? null, 'customer_details' => $data['support_customers'] ?? null]
                : [],
        ]);

        try {
            $outage = app(OutageWorkflow::class)->raiseFieldMaintenance($payload, Auth::user());
        } catch (ValidationException $exception) {
            Notification::make()->title('Not submitted')->body(implode(' ', $exception->validator->errors()->all()))->danger()->send();

            return;
        } catch (AuthorizationException $exception) {
            Notification::make()->title('Not allowed')->body($exception->getMessage())->danger()->send();

            return;
        }

        Notification::make()
            ->title($outage->maintenance_kind === OutageType::PREVENTIVE
                ? "{$outage->ticket_number} sent for Dispatcher and Infrastructure Manager approval"
                : "{$outage->ticket_number} raised — assigned to you")
            ->success()->send();

        $this->redirect(OutageResource::getUrl('view', ['record' => $outage]));
    }
}
