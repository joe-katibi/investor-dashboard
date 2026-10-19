<?php

namespace App\Filament\Pages;

use App\Enums\QueueType;
use App\Filament\Resources\Priorities\PriorityResource;
use App\Filament\Resources\QueueDefinitions\QueueDefinitionResource;
use App\Models\Priority;
use App\Services\Fifo\FifoAssignmentEngine;
use App\Services\Sla\DeskSla;
use App\Support\Settings;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\HtmlString;
use Spatie\Permission\Models\Role;
use UnitEnum;

/** FIFO & SLA configuration (spec §27.8) — no code changes required. */
class FifoSettings extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCog6Tooth;

    protected static string|UnitEnum|null $navigationGroup = 'Configuration';

    protected static ?int $navigationSort = 1;

    protected static ?string $navigationLabel = 'FIFO & SLA Settings';

    protected static ?string $title = 'FIFO & SLA Settings';

    protected string $view = 'filament.pages.settings';

    public ?array $data = [];

    /** Desks as they are grouped on the "SLA per desk" section. */
    public const DESK_GROUPS = [
        'Outages (FTTH / core / system)' => [
            QueueType::NocReview, QueueType::Infrastructure, QueueType::Technician, QueueType::SupportTechnician, QueueType::NocRestore,
            QueueType::CxFollowUp, QueueType::CxNotify, QueueType::ItReview, QueueType::ItConfirmation,
        ],
        '4G NOC' => [QueueType::MobilityNoc, QueueType::MobilityEngineer],
        'Customer tickets' => [
            QueueType::CxCallback, QueueType::CxTier2, QueueType::Billing, QueueType::BillingManager, QueueType::Commercial, QueueType::CxManager,
            QueueType::TicketNoc, QueueType::TicketFollowUp,
        ],
        '4G customer tickets' => [QueueType::Ticket4gNoc, QueueType::TicketIp, QueueType::TicketRf, QueueType::TicketCore],
        'Site visits' => [
            QueueType::VisitDispatch, QueueType::ShiftingDispatch, QueueType::VisitTechnician, QueueType::InfraSupportDispatch, QueueType::InfraSupportTechnician, QueueType::Design,
            QueueType::Access, QueueType::CxFieldSupport, QueueType::VisitAftercare,
        ],
    ];

    public static function canAccess(): bool
    {
        return (bool) Auth::user()?->can('config.admin');
    }

    public function mount(): void
    {
        $settings = app(Settings::class);
        $this->form->fill(collect(Settings::DEFAULTS)->keys()
            ->mapWithKeys(fn (string $key) => [str_replace('.', '__', $key) => $settings->get($key)])
            // Every desk with the target in force (the built-in ones included), not only those changed here.
            ->merge(['desk_sla__minutes' => app(DeskSla::class)->targets()])
            ->all());
    }

    public function form(Schema $schema): Schema
    {
        $key = fn (string $setting) => str_replace('.', '__', $setting);

        return $schema->statePath('data')->components([
            Section::make('Assignment engine')->schema([
                Toggle::make($key('fifo.enabled'))->label('Enable FIFO'),
                Radio::make($key('fifo.assignment_mode'))->label('Assignment mode')->options([
                    'auto' => 'Automatic FIFO',
                    'manual' => 'Manual (managers assign from the queue screen)',
                ])->inline(),
                Toggle::make($key('fifo.priority_aware'))->label('Priority-aware FIFO')
                    ->helperText('Serve higher priorities first; FIFO within each priority.'),
                TextEntry::make('queue_order')->label('Queue priority order')
                    ->state(fn () => new HtmlString(e(Priority::query()->active()->orderBy('queue_rank')->pluck('name')->implode(' → ')).
                        ' &nbsp;<a class="underline" href="'.PriorityResource::getUrl().'">change</a>')),
                Toggle::make($key('fifo.critical_bypass'))->label('Critical outage bypass')
                    ->helperText('Critical tickets may be assigned even when a user is at the workload limit.'),
                Toggle::make($key('fifo.manual_override'))->label('Allow manual manager override'),
                Toggle::make($key('fifo.enforce_assignee'))->label('Only the assignee (or override holders) may work a queued step'),
            ])->columns(2),

            Section::make('Eligibility')->schema([
                Toggle::make($key('fifo.require_availability'))->label('Require "Available" status'),
                Toggle::make($key('fifo.respect_shift'))->label('Respect shifts'),
                Toggle::make($key('fifo.exclude_on_leave'))->label('Exclude users on leave'),
                Toggle::make($key('fifo.skill_based'))->label('Skill-based assignment'),
                Toggle::make($key('fifo.olt_coverage'))->label('Restrict technicians to their teams’ OLT coverage')
                    ->helperText('Off: every technician can take any OLT. On: only teams covering the affected OLT (set on Teams / OLTs).'),
                Toggle::make($key('fifo.dispatcher_region_coverage'))->label('Restrict dispatchers to their regions’ OLT coverage')
                    ->helperText('Off: dispatchers get outages, tickets and site visits anywhere. On: a dispatcher locked to regions (Users → Regions) only gets them on OLTs of those regions (the region is set on the OLT). Dispatchers with no region still get everything.'),
                TextInput::make($key('fifo.max_active_per_user'))->label('Maximum active tickets per user')->numeric()->minValue(0)
                    ->helperText('0 = unlimited. Users may override this individually.'),
            ])->columns(2),

            Section::make('Acknowledgement, rejection & timeout')->schema([
                TextInput::make($key('fifo.ack_timeout_minutes'))->label('Default acknowledgement timeout (minutes)')->numeric()->minValue(0)
                    ->helperText('Overridden per queue and per SLA policy. 0 = no timeout.'),
                TextInput::make($key('fifo.escalate_after_attempts'))->label('Escalate after N rejections / timeouts')->numeric()->minValue(0),
                TextInput::make($key('fifo.reopen_waiting_minutes'))->label('Offer waiting tickets to everyone again after (minutes)')->numeric()->minValue(0)
                    ->helperText('Someone who lets a ticket time out or turns it down is not offered it again — when everyone at the desk has, it waits. After this many minutes it is offered to all of them again. 0 = never.'),
                Toggle::make($key('fifo.requeue_on_rejection'))->label('Requeue on rejection'),
                Toggle::make($key('fifo.requeue_on_timeout'))->label('Requeue on timeout'),
                Radio::make($key('fifo.requeue_on_unavailable'))->label('When an assignee becomes unavailable / leaves shift')->options([
                    'none' => 'Keep assignments',
                    'unaccepted' => 'Requeue tickets not yet accepted',
                    'all' => 'Requeue all active tickets',
                ]),
            ])->columns(2),

            Section::make('SLA')->schema([
                Toggle::make($key('sla.pause_enabled'))->label('Allow SLA pause / resume on flagged statuses'),
                TextInput::make($key('sla.default_resolution_minutes'))->label('Fallback resolution target (minutes)')->numeric()->minValue(1)
                    ->helperText('Used only when no SLA policy matches.'),
                TextInput::make($key('sla.default_pre_breach_minutes'))->label('Fallback pre-breach warning (minutes)')->numeric()->minValue(0),
                Toggle::make($key('notifications.credit_due_on_breach'))->label('Send credit-due notice on breach (fallback)'),
            ])->columns(2),

            Section::make('SLA per desk')
                ->description('How long a ticket may wait at a desk before that desk acts on it (confirms, rejects, dispatches, returns, escalates or closes). '
                    .'The people concerned are warned before the time is up and told when it is; breaches are listed under Reports → “SLA: Breaches by Desk”. '
                    .'0 = no target for that desk. A change applies to tickets arriving at a desk from now on. Customer tickets and site visits count working hours (Ticket Settings); outages and 4G tickets count every minute.')
                ->schema([
                    Toggle::make($key('desk_sla.enabled'))->label('Track SLA per desk'),
                    Toggle::make($key('desk_sla.email_breaches'))->label('E-mail breaches')
                        ->helperText('Warnings are shown in the system only; a breach is also e-mailed.'),
                    TextInput::make($key('desk_sla.warn_percent'))->label('Warn when this share of the target is used (%)')->numeric()->minValue(0)->maxValue(99)
                        ->helperText('75 = a desk with 20 minutes is warned after 15. 0 = no warning.'),
                    TextInput::make($key('desk_sla.field_minutes'))->label('Outage on the field within (minutes)')->numeric()->minValue(0)
                        ->helperText('Reported → dispatched to a field technician. 0 = off.'),
                    ...collect(self::DESK_GROUPS)->map(fn (array $desks, string $group) => Fieldset::make($group)->columnSpanFull()->columns(['default' => 2, 'md' => 3, 'xl' => 4])
                        ->schema(array_map(fn (QueueType $desk) => TextInput::make($key('desk_sla.minutes').'.'.$desk->value)
                            ->label($desk->getLabel())->numeric()->minValue(0)->suffix('min'), $desks)))->values()->all(),
                    Select::make($key('desk_sla.stakeholder_roles'))->label('Stakeholders (roles)')->multiple()->searchable()
                        ->options(fn () => Role::query()->orderBy('name')->pluck('name', 'name')->all())
                        ->helperText('Told about every warning and breach, together with the person holding the ticket and their team leaders. Each person only hears about the kinds of ticket they may view.'),
                    TagsInput::make($key('desk_sla.stakeholder_emails'))->label('Also e-mail breaches to')->placeholder('name@example.com')
                        ->nestedRecursiveRules(['email']),
                ])->columns(2),

            Section::make('Audit trail')
                ->description('Everything that is changed is always recorded. This switch is for reading only.')
                ->schema([
                    Toggle::make($key('audit.ticket_views'))->label('Record who opens a ticket')
                        ->helperText('“Ticket opened” in the audit trail — on the website and in the mobile app, at most one entry per person and ticket every 15 minutes.'),
                ]),

            Section::make('Field photos')
                ->description('Mandatory, or offered but optional. The website and the mobile app follow this at once — in the app the photo fields stop being required, they do not disappear.')
                ->schema([
                    Toggle::make($key('photos.outage_required'))->label('Emergency / Regular outages: the three site photos are mandatory')
                        ->helperText('Findings on ground, after restoration, power readings — needed to report the outage restored. Which outage types have them: Outage Types → “Site photos required”.'),
                    Toggle::make($key('photos.maintenance_required'))->label('Preventive / corrective maintenance: photos are mandatory')
                        ->helperText('Two “before” photos to raise the maintenance and two “after” photos to report the work completed.'),
                    TextEntry::make('visit_photos')->label('Site visits')->columnSpanFull()
                        ->state('The geolocation and speed-test photos of a site visit are switched under Ticket Settings & Alerts → Site visits, and per visit type (“Needs evidence”).'),
                ])->columns(2),

            Section::make('Queues')->schema([
                TextEntry::make('queues_link')->hiddenLabel()->state(new HtmlString(
                    'Routing, department / team restrictions, per-queue timeouts and manual mode are configured on the '.
                    '<a class="underline" href="'.QueueDefinitionResource::getUrl().'">FIFO Queues</a> screen.'
                )),
            ]),
        ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('save')->label('Save settings')->icon(Heroicon::OutlinedCheck)->action('save'),
        ];
    }

    public function save(): void
    {
        $settings = app(Settings::class);
        $state = $this->form->getState();
        $values = [];

        foreach (Settings::DEFAULTS as $setting => $default) {
            $field = str_replace('.', '__', $setting);
            if (! array_key_exists($field, $state)) {
                continue;
            }
            $value = $state[$field];
            $values[$setting] = match (true) {
                $setting === 'desk_sla.minutes' => array_map(fn ($minutes) => max(0, (int) $minutes), (array) $value),
                is_bool($default) => (bool) $value,
                is_int($default) => (int) $value,
                default => $value,
            };
        }

        $settings->set($values, Auth::id());

        // New rules may make waiting tickets assignable right away.
        app(FifoAssignmentEngine::class)->process();

        Notification::make()->title('Settings saved')->success()->send();
    }
}
