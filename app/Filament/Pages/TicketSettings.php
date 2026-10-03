<?php

namespace App\Filament\Pages;

use App\Enums\NotificationEvent;
use App\Filament\Resources\EmailTemplates\EmailTemplateResource;
use App\Filament\Resources\NotificationRules\NotificationRuleResource;
use App\Filament\Resources\QueueDefinitions\QueueDefinitionResource;
use App\Services\Notifications\Sms\PhoneNumber;
use App\Services\Tickets\BusinessClock;
use App\Support\Settings;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
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
 * Customer ticket settings: the SLA clock (working hours), targets and TAT brackets, the
 * follow-up / credit trigger, site visit rules, the SMS sent to customers, and the alert lists
 * of the module (separate from the outage and 4G alert lists).
 */
class TicketSettings extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCog6Tooth;

    protected static string|UnitEnum|null $navigationGroup = 'Customer Care';

    protected static ?int $navigationSort = 30;

    protected static ?string $slug = 'customer-care/settings';

    protected static ?string $navigationLabel = 'Ticket Settings & Alerts';

    protected static ?string $title = 'Customer ticket settings';

    protected string $view = 'filament.pages.settings';

    public ?array $data = [];

    public const DAYS = [1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday'];

    public const DESCRIPTIONS = [
        'outage_created' => 'A customer ticket or site visit is raised',
        'assigned' => 'A ticket is assigned to a person at a desk',
        'ticket_routed' => 'A ticket is escalated / handed to another desk',
        'ticket_returned' => 'A ticket comes back from the desk it was handed to',
        'ticket_approval' => 'A refund / credit request is approved or declined',
        'visit_scheduled' => 'A technician is assigned to a site visit',
        'visit_rescheduled' => 'A site visit is moved to another date / time',
        'visit_completed' => 'The technician completed the visit — dispatcher to verify',
        'ticket_follow_up_due' => 'A ticket is pending past the follow-up time',
        'ticket_credit_ready' => 'A ticket became ready for credit',
        'ticket_intervention' => 'A team leader took a ticket over',
        'resolved' => 'A ticket or visit is closed',
        'ticket_reopened' => 'A closed ticket is reopened, or the customer says it is not resolved',
    ];

    public static function canAccess(): bool
    {
        return (bool) Auth::user()?->can('ticket.configure');
    }

    public function getSubheading(): ?string
    {
        return 'SLA clock now: '.app(BusinessClock::class)->describe().'.';
    }

    public function mount(): void
    {
        $s = app(Settings::class);

        $this->form->fill([
            'sla_clock' => (string) $s->get('ticketing.sla_clock'),
            'day_start' => (string) $s->get('ticketing.day_start'),
            'day_end' => (string) $s->get('ticketing.day_end'),
            'working_days' => array_values(array_map('intval', (array) $s->get('ticketing.working_days'))),
            'sla_minutes' => $s->int('ticketing.sla_minutes'),
            'at_risk_percent' => $s->int('ticketing.at_risk_percent'),
            'dispatch_target_minutes' => $s->int('ticketing.dispatch_target_minutes'),
            'escalation_target_minutes' => $s->int('ticketing.escalation_target_minutes'),
            'sla_tiers' => array_values(array_map('strval', (array) $s->get('ticketing.sla_tiers'))),
            'followup_enabled' => $s->bool('ticketing.followup_enabled'),
            'followup_minutes' => $s->int('ticketing.followup_minutes'),
            'credit_notify' => $s->bool('ticketing.credit_notify'),
            'credit_roles' => array_values((array) $s->get('ticketing.credit_roles')),
            'credit_emails' => array_values((array) $s->get('ticketing.credit_emails')),
            'confirm_closure' => $s->bool('ticketing.confirm_closure'),
            'require_evidence' => $s->bool('ticketing.require_evidence'),
            'max_daily_visits' => $s->int('ticketing.max_daily_visits'),
            'company_name' => (string) $s->get('ticketing.company_name'),
            'sms_customer' => $s->bool('ticketing.sms_customer'),
            'sms_assigned' => (string) $s->get('ticketing.sms_assigned'),
            'sms_rescheduled' => (string) $s->get('ticketing.sms_rescheduled'),
            'sms_closed' => (string) $s->get('ticketing.sms_closed'),
            'alert_emails' => array_values((array) $s->get('ticketing.alert_emails')),
            'alert_events' => array_values((array) $s->get('ticketing.alert_events')),
            'alert_phones' => array_values((array) $s->get('ticketing.alert_phones')),
            'alert_sms_events' => array_values((array) $s->get('ticketing.alert_sms_events')),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        $time = fn (string $name, string $label) => TextInput::make($name)->label($label)->required()->placeholder('07:00')
            ->regex('/^([01]?\d|2[0-4]):[0-5]\d$/')->validationMessages(['regex' => 'Use 24-hour time, for example 07:00 or 18:30.']);

        return $schema->statePath('data')->components([
            Tabs::make('settings')->tabs([
                Tab::make('SLA')->icon(Heroicon::OutlinedClock)->schema([
                    Section::make('SLA clock')
                        ->description('Business hours: only the working day counts. A ticket raised after the day ends starts counting when the next working day begins; one raised at 17:00 with a 3-hour target is due at 09:00 the next working day. Changing the clock applies to tickets raised from now on.')
                        ->schema([
                            Select::make('sla_clock')->label('Clock')->required()
                                ->options(['business' => 'Business hours (working day only)', 'wall' => 'Wall clock (every hour counts)']),
                            $time('day_start', 'Working day starts'),
                            $time('day_end', 'Working day ends'),
                            CheckboxList::make('working_days')->label('Working days')->options(self::DAYS)->columns(4)->required()->columnSpanFull(),
                        ])->columns(3),
                    Section::make('Targets')
                        ->description('The SLA target of a ticket comes from its subcategory, then its visit type, then an SLA policy set for the “Customer Ticket” / “Site Visit” type, then the default here.')
                        ->schema([
                            TextInput::make('sla_minutes')->label('Default SLA target (minutes)')->numeric()->minValue(1)->maxValue(100000)->required()->helperText('180 = 3 hours.'),
                            TextInput::make('at_risk_percent')->label('“At risk” after (% of the SLA used)')->numeric()->minValue(1)->maxValue(99)->required(),
                            TextInput::make('dispatch_target_minutes')->label('Dispatch target (minutes, reported → assigned)')->numeric()->minValue(1)->maxValue(100000)->required(),
                            TextInput::make('escalation_target_minutes')->label('Escalation turnaround target (minutes)')->numeric()->minValue(1)->maxValue(100000)->required()
                                ->helperText('Handed to another desk → returned. 540 = 9 hours.'),
                            TagsInput::make('sla_tiers')->label('TAT brackets (hours)')->placeholder('3')->columnSpanFull()
                                ->nestedRecursiveRules(['integer', 'min:1', 'max:10000'])
                                ->helperText('Used by the TAT report and the “SLA per department / OLT” tables: 3, 6, 9, 12, 15, 18, 21, 24, 48, 72 and “above”.'),
                        ])->columns(2),
                ]),

                Tab::make('Follow-up & credit')->icon(Heroicon::OutlinedPhone)->schema([
                    Section::make('Tickets pending too long')
                        ->description('A ticket open longer than this (SLA time) enters the “Pending Ticket Follow-up” FIFO queue so the follow-up team calls the customer, and is marked ready for credit.')
                        ->schema([
                            Toggle::make('followup_enabled')->label('Follow up tickets pending too long'),
                            TextInput::make('followup_minutes')->label('Follow up after (minutes of SLA time)')->numeric()->minValue(1)->maxValue(100000)->required()->helperText('540 = 9 hours.'),
                        ])->columns(2),
                    Section::make('Ready for credit')
                        ->description('One in-app notification and one e-mail list the tickets that became ready for credit, with the list attached as a spreadsheet.')
                        ->schema([
                            Toggle::make('credit_notify')->label('Send the ready-for-credit list')->columnSpanFull(),
                            Select::make('credit_roles')->label('Roles')->multiple()->searchable()->options(fn () => Role::query()->orderBy('name')->pluck('name', 'name')),
                            TagsInput::make('credit_emails')->label('Extra e-mail addresses')->nestedRecursiveRules(['email'])->placeholder('credits@company.com'),
                        ])->columns(2),
                ]),

                Tab::make('Site visits & customer SMS')->icon(Heroicon::OutlinedMapPin)->schema([
                    Section::make('Site visits')->schema([
                        Toggle::make('confirm_closure')->label('CX Field Support confirms with the customer before a visit is closed')
                            ->helperText('Off: the visit closes when the dispatcher verifies it.'),
                        Toggle::make('require_evidence')->label('FAT number, geolocation photo and speed-test photo are required to complete a visit')
                            ->helperText('Per visit type under Visit Types (“Needs evidence”).'),
                        TextInput::make('max_daily_visits')->label('Most visits per technician per day')->numeric()->minValue(0)->maxValue(100)->required()->helperText('0 = no limit.'),
                    ])->columns(1),
                    Section::make('SMS to the customer')
                        ->description('Sent to the calling, primary and alternative numbers of the ticket. Placeholders: {{ticket}}, {{technician}}, {{technician_phone}}, {{visit_day}}, {{visit_time}}, {{customer}}, {{account}}, {{company}}.')
                        ->schema([
                            Toggle::make('sms_customer')->label('Text customers'),
                            TextInput::make('company_name')->label('Company name ({{company}})')->maxLength(100),
                            Textarea::make('sms_assigned')->label('When a technician is assigned')->rows(3)->maxLength(450)->columnSpanFull(),
                            Textarea::make('sms_rescheduled')->label('When the visit is rescheduled')->rows(3)->maxLength(450)->columnSpanFull(),
                            Textarea::make('sms_closed')->label('When the ticket is closed (empty = no SMS)')->rows(2)->maxLength(450)->columnSpanFull(),
                        ])->columns(2),
                ]),

                Tab::make('Alert e-mails')->icon(Heroicon::OutlinedEnvelope)->schema([
                    Section::make('Recipients')
                        ->description('Every address receives an e-mail for each ticket event ticked below. Customer tickets never use the outage or 4G alert lists.')
                        ->schema([
                            TagsInput::make('alert_emails')->label('Ticket alert e-mail addresses')->placeholder('cx-alerts@company.com')
                                ->nestedRecursiveRules(['email:rfc', 'max:255'])->splitKeys(['Tab', ',', ' ']),
                        ]),
                    Section::make('Ticket events that send an alert e-mail')
                        ->description('Tickets are many: tick only what must reach a mailbox for every single ticket.')
                        ->schema([$this->eventsList('alert_events')]),
                ]),

                Tab::make('Alert SMS')->icon(Heroicon::OutlinedDevicePhoneMobile)->schema([
                    Section::make('Recipients')->schema([
                        TagsInput::make('alert_phones')->label('Ticket alert phone numbers')->placeholder('+256700000000')
                            ->helperText('International format or local — local numbers use country code +'.config('oms.sms.default_country_code').'.')
                            ->nestedRecursiveRules([fn () => function (string $attribute, mixed $value, \Closure $fail): void {
                                if (! PhoneNumber::isValid((string) $value)) {
                                    $fail("\"{$value}\" is not a valid phone number.");
                                }
                            }])
                            ->splitKeys(['Tab', ',']),
                    ]),
                    Section::make('Ticket events that send an alert SMS')->schema([$this->eventsList('alert_sms_events')]),
                ]),
            ])->persistTabInQueryString()->columnSpanFull(),

            Section::make('More ticket configuration')->schema([
                TextEntry::make('links')->hiddenLabel()->state(fn () => new HtmlString(implode(' &nbsp;·&nbsp; ', array_filter([
                    $this->link(EmailTemplateResource::class, 'Scheduled ticket e-mails (3-hourly, daily, weekly, monthly: times & recipients)'),
                    $this->link(NotificationRuleResource::class, 'Notification rules (role-based; set Outage type = Customer Ticket / Site Visit)'),
                    $this->link(QueueDefinitionResource::class, 'Desk queues (acceptance time, manual / automatic)'),
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
        $allowed = array_map(fn (NotificationEvent $event) => $event->value, NotificationEvent::ticketing());
        $emails = fn (array $list) => collect($list)->map(fn ($email) => Str::lower(trim((string) $email)))
            ->filter(fn (string $email) => (bool) filter_var($email, FILTER_VALIDATE_EMAIL))->unique()->values()->all();
        $phones = collect((array) ($state['alert_phones'] ?? []))->map(fn ($phone) => PhoneNumber::normalise((string) $phone))->filter()->unique()->values()->all();
        $tiers = collect((array) ($state['sla_tiers'] ?? []))->map(fn ($hours) => (int) $hours)->filter(fn (int $hours) => $hours > 0)->unique()->sort()->values()->all();
        $minutes = fn (string $time) => (int) explode(':', $time)[0] * 60 + (int) explode(':', $time)[1];

        if ($state['sla_clock'] === 'business' && $minutes($state['day_end']) <= $minutes($state['day_start'])) {
            Notification::make()->title('The working day must end after it starts')->danger()->send();

            return;
        }

        app(Settings::class)->set([
            'ticketing.sla_clock' => $state['sla_clock'] === 'wall' ? 'wall' : 'business',
            'ticketing.day_start' => $state['day_start'],
            'ticketing.day_end' => $state['day_end'],
            'ticketing.working_days' => array_values(array_intersect(array_keys(self::DAYS), array_map('intval', (array) $state['working_days']))),
            'ticketing.sla_minutes' => (int) $state['sla_minutes'],
            'ticketing.at_risk_percent' => (int) $state['at_risk_percent'],
            'ticketing.dispatch_target_minutes' => (int) $state['dispatch_target_minutes'],
            'ticketing.escalation_target_minutes' => (int) $state['escalation_target_minutes'],
            'ticketing.sla_tiers' => $tiers ?: Settings::DEFAULTS['ticketing.sla_tiers'],
            'ticketing.followup_enabled' => (bool) ($state['followup_enabled'] ?? false),
            'ticketing.followup_minutes' => (int) $state['followup_minutes'],
            'ticketing.credit_notify' => (bool) ($state['credit_notify'] ?? false),
            'ticketing.credit_roles' => array_values((array) ($state['credit_roles'] ?? [])),
            'ticketing.credit_emails' => $emails((array) ($state['credit_emails'] ?? [])),
            'ticketing.confirm_closure' => (bool) ($state['confirm_closure'] ?? false),
            'ticketing.require_evidence' => (bool) ($state['require_evidence'] ?? false),
            'ticketing.max_daily_visits' => (int) $state['max_daily_visits'],
            'ticketing.company_name' => trim((string) ($state['company_name'] ?? '')),
            'ticketing.sms_customer' => (bool) ($state['sms_customer'] ?? false),
            'ticketing.sms_assigned' => trim((string) ($state['sms_assigned'] ?? '')),
            'ticketing.sms_rescheduled' => trim((string) ($state['sms_rescheduled'] ?? '')),
            'ticketing.sms_closed' => trim((string) ($state['sms_closed'] ?? '')),
            'ticketing.alert_emails' => $emails((array) ($state['alert_emails'] ?? [])),
            'ticketing.alert_events' => array_values(array_intersect((array) ($state['alert_events'] ?? []), $allowed)),
            'ticketing.alert_phones' => $phones,
            'ticketing.alert_sms_events' => array_values(array_intersect((array) ($state['alert_sms_events'] ?? []), $allowed)),
        ], Auth::id());

        $this->data['alert_phones'] = $phones;
        $this->data['sla_tiers'] = array_map('strval', $tiers);

        Notification::make()->title('Ticket settings saved')->success()->send();
    }

    private function eventsList(string $name): CheckboxList
    {
        return CheckboxList::make($name)
            ->hiddenLabel()
            ->options(collect(NotificationEvent::ticketing())->mapWithKeys(fn (NotificationEvent $event) => [$event->value => $event->getLabel()])->all())
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
