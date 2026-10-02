<?php

namespace App\Filament\Pages;

use App\Enums\NotificationEvent;
use App\Filament\Resources\EmailTemplates\EmailTemplateResource;
use App\Filament\Resources\ImpactRules\ImpactRuleResource;
use App\Filament\Resources\NotificationRules\NotificationRuleResource;
use App\Filament\Resources\SlaPolicies\SlaPolicyResource;
use App\Filament\Resources\VendorTemplates\VendorTemplateResource;
use App\Services\Notifications\Sms\PhoneNumber;
use App\Support\Settings;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use UnitEnum;

/**
 * 4G NOC settings: vendor escalation times, who is told when a ticket escalates, and the 4G alert
 * e-mail / SMS lists. 4G tickets use these lists and the 4G notification rules only — the FTTH
 * alert lists never receive 4G tickets, and the other way round.
 */
class MobilitySettings extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCog6Tooth;

    protected static string|UnitEnum|null $navigationGroup = '4G NOC';

    protected static ?int $navigationSort = 11;

    protected static ?string $slug = '4g/settings';

    protected static ?string $navigationLabel = '4G Settings & Alerts';

    protected static ?string $title = '4G NOC settings';

    protected string $view = 'filament.pages.settings';

    public ?array $data = [];

    /** Events offered for the 4G alert lists, in lifecycle order. */
    public const EVENTS = [
        NotificationEvent::OutageCreated,
        NotificationEvent::Assigned,
        NotificationEvent::UpdatePosted,
        NotificationEvent::StatusChanged,
        NotificationEvent::PriorityChanged,
        NotificationEvent::MobilityVendorNotified,
        NotificationEvent::MobilityVendorMailFailed,
        NotificationEvent::MobilityVendorUpdate,
        NotificationEvent::MobilityVendorSilent,
        NotificationEvent::MobilityVendorEscalation,
        NotificationEvent::MobilityResolvedPending,
        NotificationEvent::MobilityVerificationFailed,
        NotificationEvent::Resolved,
        NotificationEvent::MobilityReopened,
        NotificationEvent::MobilityCancelled,
        NotificationEvent::MobilityEscalated,
        NotificationEvent::AssignmentRejected,
        NotificationEvent::AssignmentTimeout,
        NotificationEvent::SlaPreBreach,
        NotificationEvent::SlaBreach,
        NotificationEvent::SlaEscalation,
    ];

    public const DESCRIPTIONS = [
        'outage_created' => 'A 4G ticket is created',
        'assigned' => 'A ticket is assigned to an engineer / technician or a NOC user',
        'update_posted' => 'Any progress update posted on a ticket',
        'resolved' => 'NOC verified the restoration and closed the ticket',
        'mobility_vendor_notified' => 'The automatic e-mail to the vendor was delivered',
        'mobility_vendor_mail_failed' => 'The vendor e-mail could not be sent',
        'mobility_vendor_update' => 'NOC recorded a vendor update',
        'mobility_vendor_silent' => 'No vendor update within the first escalation time',
        'mobility_vendor_escalation' => 'Still no vendor update — management escalation',
        'mobility_resolved_pending' => 'Engineer or vendor reports the fault fixed — NOC to verify',
        'mobility_verification_failed' => 'NOC checked and the service is still down',
    ];

    public static function canAccess(): bool
    {
        return (bool) Auth::user()?->can('mobility.configure');
    }

    public function getSubheading(): ?string
    {
        return 'Escalation, alert recipients and contact details for 4G tickets. Separate from the FTTH alert settings.';
    }

    public function mount(): void
    {
        $settings = app(Settings::class);

        $this->form->fill([
            'noc_contact' => (string) $settings->get('mobility.noc_contact'),
            'vendor_silent_minutes' => $settings->int('mobility.vendor_silent_minutes'),
            'vendor_escalation_minutes' => $settings->int('mobility.vendor_escalation_minutes'),
            'vendor_reminder_mail' => $settings->bool('mobility.vendor_reminder_mail'),
            'escalation_roles' => array_values((array) $settings->get('mobility.escalation_roles')),
            'escalation_emails' => array_values((array) $settings->get('mobility.escalation_emails')),
            'alert_emails' => array_values((array) $settings->get('mobility.alert_emails')),
            'alert_events' => array_values((array) $settings->get('mobility.alert_events')),
            'alert_phones' => array_values((array) $settings->get('mobility.alert_phones')),
            'alert_sms_events' => array_values((array) $settings->get('mobility.alert_sms_events')),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->statePath('data')->components([
            Tabs::make('settings')->tabs([
                Tab::make('Vendors & escalation')->icon(Heroicon::OutlinedArrowUpCircle)->schema([
                    Section::make('NOC contact shown to vendors')->schema([
                        TextInput::make('noc_contact')->label('4G NOC contact')->maxLength(255)
                            ->placeholder('e.g. 4G NOC — +256 700 000 000 / noc4g@company.com')
                            ->helperText('Inserted as {{noc_contact}} in the vendor e-mails.'),
                    ]),
                    Section::make('When a vendor goes quiet')
                        ->description('Ticket created → vendor notified → no update within the first time → the NOC is reminded → still nothing by the second time → management is told. The clock restarts with every vendor update recorded, and a “next update expected” time on a vendor update replaces the first time.')
                        ->schema([
                            TextInput::make('vendor_silent_minutes')->label('Remind the NOC after (minutes without a vendor update)')
                                ->numeric()->minValue(0)->maxValue(10080)->required()->helperText('0 = no reminder.'),
                            TextInput::make('vendor_escalation_minutes')->label('Escalate to management after (minutes)')
                                ->numeric()->minValue(0)->maxValue(10080)->required()->helperText('0 = no management escalation.'),
                            Toggle::make('vendor_reminder_mail')->label('Also e-mail the vendor a reminder at the management escalation')
                                ->helperText('Uses the “No vendor update” vendor template.')->columnSpanFull(),
                        ])->columns(2),
                    Section::make('Who is told when a ticket escalates')
                        ->description('Used by the management escalation above and by “Escalate” on a ticket. In-app and e-mail.')
                        ->schema([
                            Select::make('escalation_roles')->label('Roles')->multiple()->searchable()
                                ->options(fn () => Role::query()->orderBy('name')->pluck('name', 'name')),
                            TagsInput::make('escalation_emails')->label('E-mail addresses')->nestedRecursiveRules(['email'])->placeholder('cto@company.com'),
                        ])->columns(2),
                ]),

                Tab::make('Alert e-mails')->icon(Heroicon::OutlinedEnvelope)->schema([
                    Section::make('Recipients')
                        ->description('Every address receives an e-mail for each 4G event ticked below.')
                        ->schema([
                            TagsInput::make('alert_emails')->label('4G alert e-mail addresses')->placeholder('noc4g-alerts@company.com')
                                ->nestedRecursiveRules(['email:rfc', 'max:255'])->splitKeys(['Tab', ',', ' ']),
                        ]),
                    Section::make('4G events that send an alert e-mail')->schema([$this->eventsList('alert_events')]),
                ]),

                Tab::make('Alert SMS')->icon(Heroicon::OutlinedDevicePhoneMobile)->schema([
                    Section::make('Recipients')
                        ->description('Every number receives an SMS for each 4G event ticked below.')
                        ->schema([
                            TagsInput::make('alert_phones')->label('4G alert phone numbers')->placeholder('+256700000000')
                                ->helperText('International format or local — local numbers use country code +'.config('oms.sms.default_country_code').'.')
                                ->nestedRecursiveRules([fn () => function (string $attribute, mixed $value, \Closure $fail): void {
                                    if (! PhoneNumber::isValid((string) $value)) {
                                        $fail("\"{$value}\" is not a valid phone number.");
                                    }
                                }])
                                ->splitKeys(['Tab', ',']),
                        ]),
                    Section::make('4G events that send an alert SMS')->schema([$this->eventsList('alert_sms_events')]),
                ]),
            ])->persistTabInQueryString()->columnSpanFull(),

            Section::make('More 4G configuration')->schema([
                TextEntry::make('links')->hiddenLabel()->state(fn () => new HtmlString(implode(' &nbsp;·&nbsp; ', array_filter([
                    $this->link(NotificationRuleResource::class, 'Notification rules (role-based; set Vertical = 4G)'),
                    $this->link(VendorTemplateResource::class, 'Vendor e-mail templates'),
                    $this->link(EmailTemplateResource::class, 'Hourly 4G update e-mail (schedule & recipients)'),
                    $this->link(ImpactRuleResource::class, 'Severity rules'),
                    $this->link(SlaPolicyResource::class, '4G SLA targets'),
                ])))),
            ])->compact(),
        ]);
    }

    protected function getHeaderActions(): array
    {
        return [Action::make('save')->label('Save settings')->icon(Heroicon::OutlinedCheck)->action('save')];
    }

    public function save(): void
    {
        $state = $this->form->getState();
        $allowed = array_map(fn (NotificationEvent $event) => $event->value, self::EVENTS);
        $emails = fn (array $list) => collect($list)->map(fn ($email) => Str::lower(trim((string) $email)))
            ->filter(fn (string $email) => (bool) filter_var($email, FILTER_VALIDATE_EMAIL))->unique()->values()->all();
        $phones = collect((array) ($state['alert_phones'] ?? []))->map(fn ($phone) => PhoneNumber::normalise((string) $phone))->filter()->unique()->values()->all();

        app(Settings::class)->set([
            'mobility.noc_contact' => trim((string) ($state['noc_contact'] ?? '')),
            'mobility.vendor_silent_minutes' => (int) ($state['vendor_silent_minutes'] ?? 0),
            'mobility.vendor_escalation_minutes' => (int) ($state['vendor_escalation_minutes'] ?? 0),
            'mobility.vendor_reminder_mail' => (bool) ($state['vendor_reminder_mail'] ?? false),
            'mobility.escalation_roles' => array_values((array) ($state['escalation_roles'] ?? [])),
            'mobility.escalation_emails' => $emails((array) ($state['escalation_emails'] ?? [])),
            'mobility.alert_emails' => $emails((array) ($state['alert_emails'] ?? [])),
            'mobility.alert_events' => array_values(array_intersect((array) ($state['alert_events'] ?? []), $allowed)),
            'mobility.alert_phones' => $phones,
            'mobility.alert_sms_events' => array_values(array_intersect((array) ($state['alert_sms_events'] ?? []), $allowed)),
        ], Auth::id());

        $this->data['alert_phones'] = $phones;

        Notification::make()->title('4G settings saved')->success()->send();
    }

    private function eventsList(string $name): CheckboxList
    {
        return CheckboxList::make($name)
            ->hiddenLabel()
            ->options(collect(self::EVENTS)->mapWithKeys(fn (NotificationEvent $event) => [$event->value => $event->getLabel()])->all())
            ->descriptions(self::DESCRIPTIONS)
            ->columns(['default' => 1, 'md' => 2, 'xl' => 3])
            ->bulkToggleable();
    }

    /** @param class-string $resource */
    private function link(string $resource, string $label): ?string
    {
        return $resource::canViewAny() ? '<a class="underline" href="'.e($resource::getUrl()).'">'.e($label).'</a>' : null;
    }
}
