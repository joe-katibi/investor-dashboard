<?php

namespace App\Filament\Pages;

use App\Enums\NotificationEvent;
use App\Filament\Resources\NotificationLogs\NotificationLogResource;
use App\Filament\Resources\NotificationRules\NotificationRuleResource;
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
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\HtmlString;
use Throwable;
use UnitEnum;

/**
 * Alert mailing list: specific e-mail addresses that receive in-system alerts and updates
 * (ticket created, assigned to NOC / Infrastructure, closed by technician, resolved, ...).
 */
class AlertEmails extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAtSymbol;

    protected static string|UnitEnum|null $navigationGroup = 'Configuration';

    protected static ?int $navigationSort = 2;

    protected static ?string $navigationLabel = 'Alert E-mails';

    protected static ?string $title = 'Alert E-mails';

    protected string $view = 'filament.pages.settings';

    public ?array $data = [];

    /** Events offered on this screen, in lifecycle order. */
    public const EVENTS = [
        NotificationEvent::OutageCreated,
        NotificationEvent::NocEscalation,
        NotificationEvent::NocConfirmed,
        NotificationEvent::NocRejected,
        NotificationEvent::InfrastructureEscalation,
        NotificationEvent::Dispatched,
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
        NotificationEvent::CreditDue,
        NotificationEvent::CreditShared,
        NotificationEvent::SmsSent,
    ];

    public static function canAccess(): bool
    {
        return (bool) Auth::user()?->can('notifications.manage');
    }

    public function getSubheading(): ?string
    {
        return 'Specific e-mail addresses that receive alerts and updates from the system, in addition to the in-app notifications users see.';
    }

    public function mount(): void
    {
        $settings = app(Settings::class);

        $this->form->fill([
            'emails' => array_values((array) $settings->get('alerts.emails')),
            'events' => array_values((array) $settings->get('alerts.events')),
            'email_assignee' => $settings->bool('alerts.email_assignee'),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        $mailer = (string) config('mail.default');

        return $schema->statePath('data')->components([
            Section::make('Mail delivery')
                ->schema([
                    TextEntry::make('mailer_status')->hiddenLabel()->state(new HtmlString(in_array($mailer, ['log', 'array'], true)
                        ? '<span style="color:#d97706;font-weight:600;">Mail is currently written to the log ("'.e($mailer).'" mailer), not sent.</span> '
                            .'Set MAIL_MAILER=smtp and the MAIL_HOST / MAIL_PORT / MAIL_USERNAME / MAIL_PASSWORD / MAIL_FROM_ADDRESS values in .env to deliver real e-mail.'
                        : 'Mail is sent through the <strong>'.e($mailer).'</strong> mailer from <strong>'.e((string) config('mail.from.address')).'</strong>.')),
                ])
                ->compact(),

            Section::make('Recipients')
                ->description('Every address below receives an e-mail for each event ticked further down.')
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

            Section::make('Events that send an alert e-mail')
                ->schema([
                    CheckboxList::make('events')
                        ->hiddenLabel()
                        ->options(collect(self::EVENTS)->mapWithKeys(fn (NotificationEvent $event) => [$event->value => $event->getLabel()])->all())
                        ->descriptions([
                            NotificationEvent::OutageCreated->value => 'A new ticket is reported',
                            NotificationEvent::NocEscalation->value => 'Ticket assigned to the NOC queue',
                            NotificationEvent::InfrastructureEscalation->value => 'Ticket assigned to Infrastructure after NOC confirmation',
                            NotificationEvent::Dispatched->value => 'Field team / technician dispatched',
                            NotificationEvent::UpdatePosted->value => 'Any progress update posted on a ticket',
                            NotificationEvent::RestoreReported->value => 'Outage technician closes the job (restoration reported)',
                            NotificationEvent::Resolved->value => 'Ticket resolved and closed',
                        ])
                        ->columns(['default' => 1, 'md' => 2, 'xl' => 3])
                        ->bulkToggleable(),
                ]),

            Section::make('More options')
                ->schema([
                    TextEntry::make('links')->hiddenLabel()->state(new HtmlString(
                        'Role- or team-based rules (e.g. only Critical outages to managers) are configured under '.
                        '<a class="underline" href="'.NotificationRuleResource::getUrl().'">Notification Rules</a>. '.
                        'Delivery results for every e-mail are in the <a class="underline" href="'.NotificationLogResource::getUrl().'">Notification Log</a>.'
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
                ->modalDescription(fn () => 'Sends a test message to: '.(implode(', ', $this->recipients()) ?: 'no addresses yet'))
                ->action(fn () => $this->sendTest()),
            Action::make('save')->label('Save')->icon(Heroicon::OutlinedCheck)->action('save'),
        ];
    }

    public function save(): void
    {
        $state = $this->form->getState();

        app(Settings::class)->set([
            'alerts.emails' => $this->normalise($state['emails'] ?? []),
            'alerts.events' => array_values(array_intersect((array) ($state['events'] ?? []), array_map(fn ($e) => $e->value, self::EVENTS))),
            'alerts.email_assignee' => (bool) ($state['email_assignee'] ?? false),
        ], Auth::id());

        Notification::make()->title('Alert e-mail settings saved')->success()->send();
    }

    private function sendTest(): void
    {
        $recipients = $this->recipients();

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

    /** @return list<string> */
    private function recipients(): array
    {
        return $this->normalise($this->data['emails'] ?? []);
    }

    /** @return list<string> */
    private function normalise(array $emails): array
    {
        return collect($emails)
            ->map(fn ($email) => strtolower(trim((string) $email)))
            ->filter(fn ($email) => filter_var($email, FILTER_VALIDATE_EMAIL))
            ->unique()->values()->all();
    }
}
