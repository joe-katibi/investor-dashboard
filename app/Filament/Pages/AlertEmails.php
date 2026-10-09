<?php

namespace App\Filament\Pages;

use App\Enums\NotificationEvent;
use App\Filament\Resources\NotificationLogs\NotificationLogResource;
use App\Filament\Resources\NotificationRules\NotificationRuleResource;
use App\Services\Notifications\Sms\PhoneNumber;
use App\Services\Notifications\Sms\SmsGateway;
use App\Support\Settings;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\TagsInput;
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
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\HtmlString;
use Throwable;
use UnitEnum;

/**
 * Alert recipients: specific e-mail addresses and phone numbers that receive in-system alerts and
 * updates (ticket created, assigned to NOC / Infrastructure, closed by technician, resolved, ...).
 */
class AlertEmails extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBellAlert;

    protected static string|UnitEnum|null $navigationGroup = 'Configuration';

    protected static ?int $navigationSort = 2;

    protected static ?string $slug = 'alert-emails';

    protected static ?string $navigationLabel = 'Alert E-mails & SMS';

    protected static ?string $title = 'Alert E-mails & SMS';

    protected string $view = 'filament.pages.settings';

    public ?array $data = [];

    /** Events offered on this screen, in lifecycle order. */
    public const EVENTS = [
        NotificationEvent::OutageCreated,
        NotificationEvent::NocEscalation,
        NotificationEvent::NocConfirmed,
        NotificationEvent::NocRejected,
        NotificationEvent::CxFollowUp,
        NotificationEvent::SiteVisitRaised,
        NotificationEvent::InfrastructureEscalation,
        NotificationEvent::Dispatched,
        NotificationEvent::SupportRequested,
        NotificationEvent::SupportCompleted,
        NotificationEvent::ItEscalation,
        NotificationEvent::ReturnedToIt,
        NotificationEvent::Assigned,
        NotificationEvent::UpdatePosted,
        NotificationEvent::StatusChanged,
        NotificationEvent::PriorityChanged,
        NotificationEvent::RestoreReported,
        NotificationEvent::RestoreConfirmed,
        NotificationEvent::Resolved,
        NotificationEvent::AssignmentRejected,
        NotificationEvent::AssignmentTimeout,
        NotificationEvent::QueueEscalation,
        NotificationEvent::SlaPreBreach,
        NotificationEvent::SlaBreach,
        NotificationEvent::SlaEscalation,
        NotificationEvent::DeskSlaPreBreach,
        NotificationEvent::DeskSlaBreach,
        NotificationEvent::FieldSlaPreBreach,
        NotificationEvent::FieldSlaBreach,
        NotificationEvent::CreditDue,
        NotificationEvent::CreditShared,
        NotificationEvent::SmsSent,
    ];

    public const DESCRIPTIONS = [
        'outage_created' => 'A new ticket is reported',
        'noc_escalation' => 'Ticket assigned to the NOC queue',
        'infrastructure_escalation' => 'Ticket assigned to Infrastructure after NOC confirmation',
        'dispatched' => 'Field team / technician dispatched',
        'update_posted' => 'Any progress update posted on a ticket',
        'restore_reported' => 'Outage technician closes the job (restoration reported)',
        'resolved' => 'Ticket resolved and closed',
        'noc_rejected' => 'NOC rejected — returned to CX Tier II for customer follow-up',
        'cx_follow_up' => 'CX recorded the customer callback',
        'site_visit_raised' => 'CX raised a site visit for the customer',
        'support_requested' => 'Field team requested a support technician (drop cable / premises)',
        'support_completed' => 'Support technician finished the drop cable / premises work',
        'it_escalation' => 'System outage assigned to IT for review',
        'returned_to_it' => 'NOC fixed an IT-escalated issue and returned it to IT to confirm',
        'desk_sla_pre_breach' => 'A ticket has almost used up the time its desk has to act on it',
        'desk_sla_breach' => 'A ticket waited at a desk (NOC, dispatch, CX…) past that desk\'s target',
        'field_sla_pre_breach' => 'An outage is close to the time by which it must be with a field technician',
        'field_sla_breach' => 'An outage was not dispatched to a field technician within the target',
    ];

    public static function canAccess(): bool
    {
        return (bool) Auth::user()?->can('notifications.manage');
    }

    public function getSubheading(): ?string
    {
        return 'Specific e-mail addresses and phone numbers that receive alerts and updates from the system, in addition to the in-app notifications users see.';
    }

    public function mount(): void
    {
        $settings = app(Settings::class);

        $this->form->fill([
            'emails' => array_values((array) $settings->get('alerts.emails')),
            'events' => array_values((array) $settings->get('alerts.events')),
            'email_assignee' => $settings->bool('alerts.email_assignee'),
            'phones' => array_values((array) $settings->get('alerts.phones')),
            'sms_events' => array_values((array) $settings->get('alerts.sms_events')),
            'sms_assignee' => $settings->bool('alerts.sms_assignee'),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->statePath('data')->components([
            Tabs::make('channels')->tabs([
                Tab::make('E-mail')->icon(Heroicon::OutlinedEnvelope)->schema([
                    $this->statusEntry('mail_status', $this->mailStatus()),
                    Section::make('Recipients')
                        ->description('Every address receives an e-mail for each event ticked below.')
                        ->schema([
                            TagsInput::make('emails')
                                ->label('Alert e-mail addresses')
                                ->placeholder('noc-alerts@company.com')
                                ->helperText('Press Enter after each address. Distribution lists work too.')
                                ->nestedRecursiveRules(['email:rfc', 'max:255'])
                                ->splitKeys(['Tab', ',', ' ']),
                            Toggle::make('email_assignee')
                                ->label('Also e-mail the person a ticket is assigned to')
                                ->helperText('NOC, Infrastructure and technician assignees get an e-mail as well as the in-app alert.'),
                        ]),
                    Section::make('Events that send an alert e-mail')->schema([$this->eventsList('events')]),
                ]),

                Tab::make('SMS')->icon(Heroicon::OutlinedDevicePhoneMobile)->schema([
                    $this->statusEntry('sms_status', $this->smsStatus()),
                    Section::make('Recipients')
                        ->description('Every number receives an SMS for each event ticked below.')
                        ->schema([
                            TagsInput::make('phones')
                                ->label('Alert phone numbers')
                                ->placeholder('+256772123456')
                                ->helperText('International format (+256…) or local (0772…) — local numbers use country code +'.config('oms.sms.default_country_code').'. Press Enter after each number.')
                                ->nestedRecursiveRules([fn () => function (string $attribute, mixed $value, \Closure $fail): void {
                                    if (! PhoneNumber::isValid((string) $value)) {
                                        $fail("\"{$value}\" is not a valid phone number.");
                                    }
                                }])
                                ->splitKeys(['Tab', ',']),
                            Toggle::make('sms_assignee')
                                ->label('Also SMS the person a ticket is assigned to')
                                ->helperText('Uses the phone number on the user\'s profile (People → Users). Users without a number are skipped.'),
                        ]),
                    Section::make('Events that send an alert SMS')
                        ->description('SMS is short and may cost money per message — untick events you only want by e-mail.')
                        ->schema([$this->eventsList('sms_events')]),
                ]),
            ])->persistTabInQueryString()->columnSpanFull(),

            Section::make('More options')
                ->schema([
                    TextEntry::make('links')->hiddenLabel()->state(new HtmlString(
                        'Role- or team-based rules (e.g. only Critical outages to managers, SMS to NOC leads) are configured under '.
                        '<a class="underline" href="'.NotificationRuleResource::getUrl().'">Notification Rules</a>. '.
                        'Delivery results for every e-mail and SMS are in the <a class="underline" href="'.NotificationLogResource::getUrl().'">Notification Log</a>.'
                    )),
                ])
                ->compact(),
        ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('sendTest')
                ->label('Send test e-mail')
                ->icon(Heroicon::OutlinedPaperAirplane)
                ->color('gray')
                ->requiresConfirmation()
                ->modalDescription(fn () => 'Sends a test message to: '.(implode(', ', $this->emailRecipients()) ?: 'no addresses yet'))
                ->action(fn () => $this->sendTestEmail()),
            Action::make('sendTestSms')
                ->label('Send test SMS')
                ->icon(Heroicon::OutlinedDevicePhoneMobile)
                ->color('gray')
                ->requiresConfirmation()
                ->modalDescription(fn () => 'Sends a test SMS to: '.(implode(', ', $this->phoneRecipients()) ?: 'no numbers yet'))
                ->action(fn () => $this->sendTestSms()),
            Action::make('save')->label('Save')->icon(Heroicon::OutlinedCheck)->action('save'),
        ];
    }

    public function save(): void
    {
        $state = $this->form->getState();
        $allowed = array_map(fn (NotificationEvent $e) => $e->value, self::EVENTS);

        app(Settings::class)->set([
            'alerts.emails' => $this->normaliseEmails($state['emails'] ?? []),
            'alerts.events' => array_values(array_intersect((array) ($state['events'] ?? []), $allowed)),
            'alerts.email_assignee' => (bool) ($state['email_assignee'] ?? false),
            'alerts.phones' => $this->normalisePhones($state['phones'] ?? []),
            'alerts.sms_events' => array_values(array_intersect((array) ($state['sms_events'] ?? []), $allowed)),
            'alerts.sms_assignee' => (bool) ($state['sms_assignee'] ?? false),
        ], Auth::id());

        // Show the stored (normalised) numbers back to the user.
        $this->data['phones'] = $this->normalisePhones($state['phones'] ?? []);

        Notification::make()->title('Alert settings saved')->success()->send();
    }

    private function sendTestEmail(): void
    {
        $recipients = $this->emailRecipients();

        if ($recipients === []) {
            Notification::make()->title('Add at least one e-mail address first')->warning()->send();

            return;
        }

        try {
            Mail::raw(
                'This is a test alert from the '.config('app.name').".\n\nIf you received it, alert e-mails are configured correctly.\n\nSent by ".Auth::user()?->name.' at '.now()->format('Y-m-d H:i').'.',
                fn ($message) => $message->to($recipients)->subject(config('app.name').' | Test alert'),
            );

            Notification::make()
                ->title('Test e-mail sent')
                ->body(in_array(config('mail.default'), ['log', 'array'], true) ? 'Mailer is "'.config('mail.default').'": the message was written to the log, not delivered.' : 'Sent to '.implode(', ', $recipients).'.')
                ->success()->send();
        } catch (Throwable $exception) {
            Notification::make()->title('Test e-mail failed')->body(str($exception->getMessage())->limit(300))->danger()->send();
        }
    }

    private function sendTestSms(): void
    {
        $recipients = $this->phoneRecipients();

        if ($recipients === []) {
            Notification::make()->title('Add at least one valid phone number first')->warning()->send();

            return;
        }

        $gateway = app(SmsGateway::class);
        $failed = [];

        foreach ($recipients as $phone) {
            try {
                $gateway->send($phone, 'OMS test alert: SMS notifications are working. Sent by '.Auth::user()?->name.' at '.now()->format('H:i d/m').'.');
            } catch (Throwable $exception) {
                $failed[] = $phone.': '.str($exception->getMessage())->limit(120);
            }
        }

        $failed === []
            ? Notification::make()->title('Test SMS sent via '.$gateway->name())
                ->body(config('oms.sms.driver') === 'log' ? 'SMS driver is "log": messages were written to the log, not delivered.' : 'Sent to '.implode(', ', $recipients).'.')
                ->success()->send()
            : Notification::make()->title('Some test SMS failed')->body(implode("\n", $failed))->danger()->persistent()->send();
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

    private function statusEntry(string $name, string $html): Section
    {
        return Section::make('Delivery')->schema([TextEntry::make($name)->hiddenLabel()->state(new HtmlString($html))])->compact();
    }

    private function mailStatus(): string
    {
        $mailer = (string) config('mail.default');

        return in_array($mailer, ['log', 'array'], true)
            ? '<span style="color:#d97706;font-weight:600;">Mail is currently written to the log ("'.e($mailer).'" mailer), not sent.</span> '
                .'Set MAIL_MAILER=smtp and the MAIL_HOST / MAIL_PORT / MAIL_USERNAME / MAIL_PASSWORD / MAIL_FROM_ADDRESS values in .env to deliver real e-mail.'
            : 'Mail is sent through the <strong>'.e($mailer).'</strong> mailer from <strong>'.e((string) config('mail.from.address')).'</strong>.';
    }

    private function smsStatus(): string
    {
        $gateway = app(SmsGateway::class)->name();

        return config('oms.sms.driver') === 'log'
            ? '<span style="color:#d97706;font-weight:600;">SMS is currently written to the log, not sent.</span> '
                .'Set SMS_DRIVER=africastalking (AFRICASTALKING_USERNAME / AFRICASTALKING_API_KEY / AFRICASTALKING_SANDBOX=false), '
                .'twilio (TWILIO_SID / TWILIO_TOKEN / TWILIO_FROM) or http (SMS_URL / SMS_TOKEN) in .env to deliver real SMS.'
            : 'SMS is sent through <strong>'.e($gateway).'</strong>'.(filled(config('oms.sms.sender')) ? ' as <strong>'.e((string) config('oms.sms.sender')).'</strong>' : '').'.';
    }

    /** @return list<string> */
    private function emailRecipients(): array
    {
        return $this->normaliseEmails($this->data['emails'] ?? []);
    }

    /** @return list<string> */
    private function phoneRecipients(): array
    {
        return $this->normalisePhones($this->data['phones'] ?? []);
    }

    /** @return list<string> */
    private function normaliseEmails(array $emails): array
    {
        return collect($emails)
            ->map(fn ($email) => strtolower(trim((string) $email)))
            ->filter(fn ($email) => filter_var($email, FILTER_VALIDATE_EMAIL))
            ->unique()->values()->all();
    }

    /** @return list<string> */
    private function normalisePhones(array $phones): array
    {
        return collect($phones)
            ->map(fn ($phone) => PhoneNumber::normalise((string) $phone))
            ->filter()
            ->unique()->values()->all();
    }
}
