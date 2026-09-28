<?php

namespace App\Filament\Pages;

use App\Filament\Resources\MaintenanceTemplates\MaintenanceTemplateResource;
use App\Filament\Resources\SlaPolicies\SlaPolicyResource;
use App\Models\SlaPolicy;
use App\Models\User;
use App\Services\Maintenance\MaintenanceNotifier;
use App\Support\Settings;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use UnitEnum;

/** Planned maintenance: reminder times, stakeholders, default CRF signatories and re-approval rule. */
class MaintenanceSettings extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static string|UnitEnum|null $navigationGroup = 'Configuration';

    protected static ?int $navigationSort = 3;

    protected static ?string $slug = 'maintenance-settings';

    protected static ?string $navigationLabel = 'Maintenance Settings';

    protected static ?string $title = 'Planned Maintenance Settings';

    protected string $view = 'filament.pages.settings';

    /** Preset reminder times (minutes before the start). */
    public const PRESETS = [1440, 720, 360, 180, 120, 60, 30, 15, 10, 5];

    public ?array $data = [];

    public static function canAccess(): bool
    {
        return (bool) Auth::user()?->can('maintenance.configure');
    }

    public function mount(): void
    {
        $settings = app(Settings::class);
        $offsets = array_map('intval', (array) $settings->get('maintenance.reminder_offsets'));

        $this->form->fill([
            'reminder_presets' => array_values(array_map('strval', array_intersect($offsets, self::PRESETS))),
            'reminder_custom' => array_values(array_map('strval', array_diff($offsets, self::PRESETS))),
            'stakeholder_emails' => (array) $settings->get('maintenance.stakeholder_emails'),
            'stakeholder_roles' => (array) $settings->get('maintenance.stakeholder_roles'),
            'default_signatories' => array_values((array) $settings->get('maintenance.default_signatories')),
            'reapprove_on_reschedule' => $settings->bool('maintenance.reapprove_on_reschedule'),
            'sign_link_days' => $settings->int('maintenance.sign_link_days'),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->statePath('data')->components([
            Section::make('Reminders before the start')
                ->description('In-app notification + the “Maintenance reminder” e-mail at each time. The “maintenance started” alert is always sent when the window opens.')
                ->schema([
                    CheckboxList::make('reminder_presets')->label('Remind')->columns(['default' => 2, 'md' => 5])
                        ->options(collect(self::PRESETS)->mapWithKeys(fn (int $m) => [(string) $m => MaintenanceNotifier::duration($m).' before'])->all()),
                    TagsInput::make('reminder_custom')->label('Other times (minutes before the start)')->placeholder('e.g. 90')
                        ->nestedRecursiveRules(['integer', 'min:1', 'max:43200']),
                ]),

            Section::make('Stakeholders')
                ->description('Receive the CRF notice, reminders, start, reschedule, completion and cancellation (per template settings).')
                ->schema([
                    Select::make('stakeholder_roles')->label('Roles (in-app + e-mail)')->multiple()->searchable()
                        ->options(fn () => Role::query()->orderBy('name')->pluck('name', 'name')),
                    TagsInput::make('stakeholder_emails')->label('E-mail addresses / distribution lists')
                        ->nestedRecursiveRules(['email'])->placeholder('noc@company.com'),
                ])->columns(2),

            Section::make('Default CRF signatories')
                ->description('Pre-filled when NOC sends a CRF for sign-off (NOC can change them per CRF). Leave the person empty to choose each time.')
                ->schema([
                    Repeater::make('default_signatories')->hiddenLabel()->defaultItems(0)->reorderable()->addActionLabel('Add signatory')
                        ->schema([
                            TextInput::make('role')->label('Role / authority')->required()->maxLength(150),
                            Select::make('user_id')->label('User')->searchable()->live()
                                ->options(fn () => User::query()->active()->orderBy('name')->pluck('name', 'id')),
                            TextInput::make('email')->label('…or e-mail')->email()->maxLength(255)
                                ->hidden(fn (Get $get) => filled($get('user_id'))),
                        ])->columns(3),
                ]),

            Section::make('Sign-off & SLA')->schema([
                Toggle::make('reapprove_on_reschedule')->label('Collect signatures again when maintenance is rescheduled')
                    ->helperText('Only before the work is dispatched. Off: signatories are just notified of the new window.'),
                TextInput::make('sign_link_days')->label('Sign links valid for (days)')->numeric()->minValue(1)->maxValue(90)->required(),
                TextEntry::make('sla')->label('Planned maintenance SLA')->columnSpanFull()
                    ->state(function () {
                        $policy = SlaPolicy::query()->where('name', 'Planned Maintenance – window')->first();

                        return new HtmlString(e($policy ? MaintenanceNotifier::duration((int) $policy->resolution_minutes).' from the scheduled start until CX has notified customers' : 'Not configured')
                            .($policy ? ' &nbsp;<a class="underline" href="'.SlaPolicyResource::getUrl().'">change</a>' : ''));
                    }),
                TextEntry::make('templates')->label('E-mail wording')->columnSpanFull()->state(new HtmlString(
                    'Edit the wording, recipients and timing of each e-mail under <a class="underline" href="'.MaintenanceTemplateResource::getUrl().'">Maintenance E-mails</a>.'
                )),
            ])->columns(2),
        ]);
    }

    protected function getHeaderActions(): array
    {
        return [Action::make('save')->label('Save settings')->icon(Heroicon::OutlinedCheck)->action('save')];
    }

    public function save(): void
    {
        $state = $this->form->getState();

        $offsets = collect([...(array) ($state['reminder_presets'] ?? []), ...(array) ($state['reminder_custom'] ?? [])])
            ->map(fn ($m) => (int) $m)->filter(fn (int $m) => $m > 0)->unique()->sortDesc()->values()->all();

        $signatories = collect((array) ($state['default_signatories'] ?? []))
            ->map(fn (array $row) => [
                'role' => trim((string) ($row['role'] ?? '')),
                'user_id' => ! empty($row['user_id']) ? (int) $row['user_id'] : null,
                'email' => ! empty($row['user_id']) ? null : (Str::lower(trim((string) ($row['email'] ?? ''))) ?: null),
            ])
            ->filter(fn (array $row) => $row['role'] !== '')->values()->all();

        app(Settings::class)->set([
            'maintenance.reminder_offsets' => $offsets,
            'maintenance.stakeholder_roles' => array_values((array) ($state['stakeholder_roles'] ?? [])),
            'maintenance.stakeholder_emails' => array_values(array_unique(array_map(fn ($e) => Str::lower(trim($e)), (array) ($state['stakeholder_emails'] ?? [])))),
            'maintenance.default_signatories' => $signatories,
            'maintenance.reapprove_on_reschedule' => (bool) ($state['reapprove_on_reschedule'] ?? false),
            'maintenance.sign_link_days' => (int) ($state['sign_link_days'] ?? 14),
        ], Auth::id());

        Notification::make()->title('Maintenance settings saved')->success()->send();
    }
}
