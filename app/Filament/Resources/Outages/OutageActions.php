<?php

namespace App\Filament\Resources\Outages;

use App\Enums\AssignmentStatus;
use App\Enums\QueueType;
use App\Enums\StatusCode;
use App\Enums\TeamType;
use App\Filament\Resources\MobilityOutages\MobilityActions;
use App\Filament\Resources\Tickets\TicketActions;
use App\Models\ChangeRequest;
use App\Models\Department;
use App\Models\MaintenanceApproval;
use App\Models\Outage;
use App\Models\OutageAssignment;
use App\Models\OutageFollowUp;
use App\Models\OutagePhoto;
use App\Models\OutageStatus;
use App\Models\OutageUpdate;
use App\Models\Team;
use App\Models\User;
use App\Services\Fifo\EligibilityEvaluator;
use App\Services\Fifo\FifoAssignmentEngine;
use App\Services\Outages\OutageWorkflow;
use App\Support\Settings;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Support\Icons\Heroicon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * Workflow actions shared by the outage page, FIFO queue and My Work screens. Every action
 * delegates to OutageWorkflow / FifoAssignmentEngine, so permissions and FIFO ownership are
 * enforced identically everywhere.
 */
final class OutageActions
{
    /**
     * Every action a ticket can show, FTTH and 4G alike (My Work and the FIFO queue list both kinds).
     * Each action is hidden unless the policy allows it for that ticket, so only the right ones appear.
     *
     * @return list<Action>
     */
    public static function all(): array
    {
        return [
            ...self::primary(), ...MobilityActions::primary(), ...TicketActions::primary(),
            ...self::secondary(), ...MobilityActions::secondary(), ...TicketActions::secondary(),
        ];
    }

    /** @return list<Action> */
    public static function primary(): array
    {
        return [
            self::accept(), self::rejectAssignment(), self::nocConfirm(), self::nocReject(), self::cxFollowUp(),
            self::itEscalateToNoc(), self::itResolve(), self::nocReturnToIt(), self::itReturnToNoc(),
            self::prepareCrf(), self::viewCrf(), self::approvePreventive(), self::rejectPreventive(),
            self::dispatch(), self::requestSupport(), self::completeSupport(), self::addSitePhotos(),
            self::reportRestored(), self::confirmRestoration(), self::rejectRestoration(), self::notifyCustomers(), self::resolve(),
        ];
    }

    /** @return list<Action> */
    public static function secondary(): array
    {
        return [
            self::addUpdate(), self::setStatus(), self::customerCommunication(), self::cancelSupport(),
            self::downloadCrf(), self::resendSignoff(), self::reschedule(), self::cancelMaintenance(), self::reassign(),
        ];
    }

    // ------------------------------------------------------------------ planned maintenance (CRF)

    public static function prepareCrf(): Action
    {
        return Action::make('prepareCrf')
            ->label('Complete CRF & send for sign-off')
            ->icon(Heroicon::OutlinedDocumentCheck)
            ->color('primary')
            ->visible(fn (Outage $record) => Auth::user()?->can('prepareCrf', $record))
            ->modalHeading('Maintenance & Change Request Form (CRF)')
            ->modalDescription('Confirm the affected customers and complete the CRF. Stakeholders receive the notice with the CRF attached and each signatory gets a personal “Sign CRF” link.')
            ->modalWidth('5xl')
            ->modalSubmitActionLabel('Send for sign-off')
            ->fillForm(function (Outage $record): array {
                $cr = $record->changeRequest;
                $previous = $cr?->signatures()->get();

                return [
                    'customers_affected' => $record->customers_affected,
                    'scheduled_start' => $cr?->scheduled_start?->toDateTimeString(),
                    'scheduled_end' => $cr?->scheduled_end?->toDateTimeString(),
                    ...Arr::only($cr?->toArray() ?? [], [
                        'initiator', 'department', 'in_charge', 'escalation_time', 'rollback_time', 'emergency_contact', 'risk_tier',
                        'work_description', 'fallback_plan', 'prior_tests', 'risks_mitigation', 'stakeholder_emails',
                    ]),
                    'checklist' => array_keys(array_filter((array) ($cr?->checklist ?? []))),
                    // Re-issue keeps the previous signatories; a first issue starts from the configured defaults.
                    'signatories' => $previous?->isNotEmpty()
                        ? $previous->map(fn ($s) => ['role' => $s->role_label, 'user_id' => $s->user_id, 'email' => $s->user_id ? null : $s->email])->all()
                        : array_values((array) app(Settings::class)->get('maintenance.default_signatories')),
                ];
            })
            ->schema([
                Section::make('1. General details')->schema([
                    TextInput::make('customers_affected')->label('Confirmed customers affected')->numeric()->minValue(0)->required(),
                    DateTimePicker::make('scheduled_start')->label('Scheduled start')->seconds(false)->required(),
                    DateTimePicker::make('scheduled_end')->label('Scheduled end')->seconds(false)->required()->after('scheduled_start'),
                    TextInput::make('initiator')->maxLength(150),
                    TextInput::make('department')->maxLength(150),
                    TextInput::make('in_charge')->label('In-charge / lead')->maxLength(150),
                    TextInput::make('emergency_contact')->maxLength(100),
                    TextInput::make('escalation_time')->maxLength(100),
                    TextInput::make('rollback_time')->maxLength(100),
                ])->columns(3),
                Section::make('2–3. Risk, scope & fallback')->schema([
                    Radio::make('risk_tier')->label('Risk tier')->required()->inline()
                        ->options(collect(ChangeRequest::RISK_TIERS)->map(fn (array $tier) => $tier[0])->all()),
                    Textarea::make('work_description')->label('Detail / description of work')->required()->rows(3)->maxLength(5000),
                    Textarea::make('fallback_plan')->label('Recovery & fallback plan')->rows(2)->maxLength(5000),
                    Textarea::make('prior_tests')->label('Prior test details')->rows(2)->maxLength(5000),
                    Textarea::make('risks_mitigation')->label('Identified risks & mitigation')->rows(2)->maxLength(5000),
                ]),
                Section::make('4. Operational checklist')->schema([
                    CheckboxList::make('checklist')->hiddenLabel()
                        ->options(collect(ChangeRequest::CHECKLIST)->map(fn (array $item) => $item[0])->all())
                        ->descriptions(collect(ChangeRequest::CHECKLIST)->map(fn (array $item) => $item[1])->all())
                        ->columns(2),
                ]),
                Section::make('6. Signatories')->description('Everyone listed must approve before the work is handed to Infrastructure.')->schema([
                    Repeater::make('signatories')->hiddenLabel()->minItems(1)->defaultItems(0)->reorderable()
                        ->addActionLabel('Add signatory')
                        ->schema([
                            TextInput::make('role')->label('Role / authority')->required()->maxLength(150),
                            Select::make('user_id')->label('User')->searchable()
                                ->options(fn () => User::query()->active()->orderBy('name')->pluck('name', 'id'))
                                ->live(),
                            TextInput::make('email')->label('…or e-mail')->email()->maxLength(255)
                                ->required(fn (Get $get) => blank($get('user_id')))
                                ->hidden(fn (Get $get) => filled($get('user_id'))),
                        ])->columns(3),
                ]),
                Section::make('Stakeholders')->schema([
                    TagsInput::make('stakeholder_emails')->label('Extra stakeholder e-mails for this maintenance')
                        ->nestedRecursiveRules(['email'])->placeholder('name@company.com')
                        ->helperText('Added to the stakeholders set in Maintenance Settings.'),
                ])->collapsed(),
            ])
            ->action(fn (Outage $record, array $data) => self::run($record, fn () => app(OutageWorkflow::class)->sendCrfForSignoff($record, Auth::user(), array_merge($data, [
                'checklist' => array_fill_keys((array) ($data['checklist'] ?? []), true),
            ])), 'CRF sent for sign-off'));
    }

    public static function viewCrf(): Action
    {
        return Action::make('viewCrf')
            ->label('View CRF')
            ->icon(Heroicon::OutlinedDocumentText)
            ->color('gray')
            ->visible(fn (Outage $record) => Auth::user()?->can('viewCrf', $record))
            ->url(fn (Outage $record) => route('crf.pdf', $record), shouldOpenInNewTab: true);
    }

    public static function downloadCrf(): Action
    {
        return Action::make('downloadCrf')
            ->label('Download CRF (PDF)')
            ->icon(Heroicon::OutlinedArrowDownTray)
            ->visible(fn (Outage $record) => Auth::user()?->can('viewCrf', $record))
            ->url(fn (Outage $record) => route('crf.pdf', ['outage' => $record, 'download' => 1]));
    }

    public static function resendSignoff(): Action
    {
        return Action::make('resendSignoff')
            ->label('Re-send sign-off links')
            ->icon(Heroicon::OutlinedEnvelope)
            ->visible(fn (Outage $record) => Auth::user()?->can('resendSignoff', $record))
            ->requiresConfirmation()
            ->modalDescription(fn (Outage $record) => 'New personal links go to everyone who has not signed: '
                .($record->changeRequest?->signatures->where('status', 'pending')->pluck('role_label')->implode(', ') ?: '—').'. Older links stop working.')
            ->action(fn (Outage $record) => self::run($record, fn () => app(OutageWorkflow::class)->resendSignoffRequests($record, Auth::user()), 'Sign-off links re-sent'));
    }

    public static function reschedule(): Action
    {
        return Action::make('rescheduleMaintenance')
            ->label('Reschedule')
            ->icon(Heroicon::OutlinedCalendarDays)
            ->visible(fn (Outage $record) => Auth::user()?->can('rescheduleMaintenance', $record))
            ->modalDescription('Stakeholders and signatories are notified with the updated CRF; reminders follow the new window.')
            ->fillForm(fn (Outage $record) => [
                'scheduled_start' => $record->changeRequest?->scheduled_start?->toDateTimeString(),
                'scheduled_end' => $record->changeRequest?->scheduled_end?->toDateTimeString(),
            ])
            ->schema([
                DateTimePicker::make('scheduled_start')->label('New start')->seconds(false)->required(),
                DateTimePicker::make('scheduled_end')->label('New end')->seconds(false)->required()->after('scheduled_start'),
                Textarea::make('reason')->label('Reason')->required()->maxLength(2000),
            ])
            ->action(fn (Outage $record, array $data) => self::run($record, fn () => app(OutageWorkflow::class)->rescheduleMaintenance(
                $record, Auth::user(), $data['scheduled_start'], $data['scheduled_end'], $data['reason'],
            ), 'Maintenance rescheduled'));
    }

    public static function cancelMaintenance(): Action
    {
        return Action::make('cancelMaintenance')
            ->label('Cancel maintenance')
            ->icon(Heroicon::OutlinedNoSymbol)
            ->color('danger')
            ->visible(fn (Outage $record) => Auth::user()?->can('cancelMaintenance', $record))
            ->schema([Textarea::make('reason')->label('Reason')->required()->maxLength(2000)])
            ->action(fn (Outage $record, array $data) => self::run($record, fn () => app(OutageWorkflow::class)->cancelMaintenance($record, Auth::user(), $data['reason']), 'Maintenance cancelled'));
    }

    public static function notifyCustomers(): Action
    {
        return Action::make('notifyCustomers')
            ->label('Customers notified — close')
            ->icon(Heroicon::OutlinedMegaphone)
            ->color('success')
            ->visible(fn (Outage $record) => Auth::user()?->can('notifyCustomers', $record))
            ->modalDescription(fn (Outage $record) => 'Outcome: '.(ChangeRequest::FINAL_STATUSES[$record->changeRequest?->final_status] ?? '—')
                .($record->changeRequest?->outcome_details ? ' — '.$record->changeRequest->outcome_details : ''))
            ->schema([
                CheckboxList::make('channels')->label('How were customers told?')->required()->columns(2)
                    ->options(['SMS' => 'SMS', 'E-mail' => 'E-mail', 'Phone call' => 'Phone call', 'WhatsApp' => 'WhatsApp', 'Social media' => 'Social media']),
                Textarea::make('note')->label('Message / note')->required()->rows(3)->maxLength(2000)
                    ->default('Dear customer, the planned maintenance in your area was completed successfully and all services are restored. Thank you for your patience.'),
            ])
            ->action(fn (Outage $record, array $data) => self::run($record, fn () => app(OutageWorkflow::class)->notifyCustomersMaintenanceDone($record, Auth::user(), $data), 'Maintenance completed — ticket closed'));
    }

    public static function isMaintenance(Outage $record): bool
    {
        return $record->changeRequest !== null;
    }

    // ------------------------------------------------------------------ NOC reject → CX Tier II follow-up

    public static function cxFollowUp(): Action
    {
        return Action::make('cxFollowUp')
            ->label('Record customer callback')
            ->icon(Heroicon::OutlinedPhoneArrowUpRight)
            ->color('danger')
            ->visible(fn (Outage $record) => Auth::user()?->can('cxFollowUp', $record))
            ->modalHeading('Customer follow-up (NOC rejected)')
            ->modalDescription(fn (Outage $record) => 'NOC reason: '.($record->rejection_reason ?: '—'))
            ->modalWidth('2xl')
            ->schema([
                Grid::make(['default' => 1, 'sm' => 2])->schema([
                    DateTimePicker::make('called_at')->label('Called at')->default(fn () => now()->startOfMinute())->seconds(false)->maxDate(fn () => now()->addMinutes(5)->startOfMinute())->required(),
                    Select::make('call_outcome')->label('Call outcome')->options(OutageFollowUp::OUTCOMES)->required(),
                ]),
                Textarea::make('callback_notes')->label('Callback notes')->required()->rows(3)->maxLength(5000),
                Toggle::make('site_visit_required')->label('Raise a site visit')->live(),
                Grid::make(['default' => 1, 'sm' => 2])->schema([
                    DateTimePicker::make('site_visit_at')->label('Site visit scheduled for')->seconds(false)->required()->minDate(fn () => now()->subDay()->startOfMinute()),
                    TextInput::make('site_visit_reference')->label('Site visit reference / job no.')->maxLength(100),
                    Textarea::make('site_visit_notes')->label('Site visit action raised')->rows(2)->maxLength(2000)->columnSpanFull(),
                ])->visible(fn (Get $get) => (bool) $get('site_visit_required')),
                Toggle::make('close')->label('Follow-up complete — close the ticket')->default(true)
                    ->helperText('Turn off to keep the ticket for another call (for example no answer).'),
            ])
            ->action(fn (Outage $record, array $data) => self::run($record, fn () => app(OutageWorkflow::class)->recordCxFollowUp($record, Auth::user(), $data), 'Customer follow-up recorded'));
    }

    // ------------------------------------------------------------------ support technician (parallel)

    public static function requestSupport(): Action
    {
        return Action::make('requestSupport')
            ->label('Request support technician')
            ->icon(Heroicon::OutlinedUserPlus)
            ->color('warning')
            ->visible(fn (Outage $record) => Auth::user()?->can('requestSupport', $record))
            ->modalDescription('A support technician (drop cable → customer premises) is assigned from the Support Technician queue and works on this ticket at the same time as the outage technician.')
            ->fillForm(fn (Outage $record) => ['location' => $record->location])
            ->schema([
                Textarea::make('work_required')->label('Work required on site')->required()->rows(3)->maxLength(2000)
                    ->placeholder('e.g. Replace damaged drop cable from FDT to customer premises and re-terminate the ONT.'),
                TextInput::make('customer_details')->label('Customer(s) — name / account / phone')->maxLength(255),
                TextInput::make('location')->label('Premises location')->maxLength(255),
            ])
            ->action(fn (Outage $record, array $data) => self::run($record, fn () => app(OutageWorkflow::class)->requestSupport($record, Auth::user(), $data), 'Support technician requested'));
    }

    public static function completeSupport(): Action
    {
        return Action::make('completeSupport')
            ->label('Support work done')
            ->icon(Heroicon::OutlinedCheckCircle)
            ->color('success')
            ->visible(fn (Outage $record) => Auth::user()?->can('completeSupport', $record))
            ->modalDescription(fn (Outage $record) => 'Requested: '.($record->openSupportRequest?->work_required ?? '—'))
            ->schema([Textarea::make('note')->label('Drop cable / premises work done')->required()->rows(3)->maxLength(5000)])
            ->action(fn (Outage $record, array $data) => self::run($record, fn () => app(OutageWorkflow::class)->completeSupport($record, Auth::user(), $data['note']), 'Support work completed'));
    }

    public static function cancelSupport(): Action
    {
        return Action::make('cancelSupport')
            ->label('Cancel support request')
            ->icon(Heroicon::OutlinedNoSymbol)
            ->color('gray')
            ->visible(fn (Outage $record) => Auth::user()?->can('cancelSupport', $record))
            ->schema([Textarea::make('reason')->label('Why is the support technician no longer needed?')->required()->maxLength(2000)])
            ->action(fn (Outage $record, array $data) => self::run($record, fn () => app(OutageWorkflow::class)->cancelSupport($record, Auth::user(), $data['reason']), 'Support request cancelled'));
    }

    // ------------------------------------------------------------------ system outages: IT first

    public static function itEscalateToNoc(): Action
    {
        return Action::make('itEscalateToNoc')
            ->label('Escalate to NOC')
            ->icon(Heroicon::OutlinedArrowUpCircle)
            ->color('info')
            ->visible(fn (Outage $record) => Auth::user()?->can('itReview', $record))
            ->modalDescription('Use when the cause is core or network. NOC works it and returns it to IT to confirm.')
            ->schema([Textarea::make('reason')->label('Why NOC is needed (core / network cause)')->required()->maxLength(2000)])
            ->action(fn (Outage $record, array $data) => self::run($record, fn () => app(OutageWorkflow::class)->itEscalateToNoc($record, Auth::user(), $data['reason']), 'Escalated to NOC'));
    }

    public static function itResolve(): Action
    {
        return Action::make('itResolve')
            ->label(fn (Outage $record) => Auth::user()?->can('itConfirm', $record) ? 'IT confirm resolved' : 'Resolve (IT)')
            ->icon(Heroicon::OutlinedCheckBadge)
            ->color('success')
            ->visible(fn (Outage $record) => Auth::user()?->can('itReview', $record) || Auth::user()?->can('itConfirm', $record))
            ->modalDescription('Closes the ticket. The feedback is shared with the team and the person who reported it.')
            ->schema([Textarea::make('feedback')->label('Feedback for the team (cause and fix)')->required()->rows(4)->maxLength(5000)])
            ->action(fn (Outage $record, array $data) => self::run($record, fn () => app(OutageWorkflow::class)->itResolve($record, Auth::user(), $data['feedback']), 'Resolved — feedback shared with the team'));
    }

    public static function nocReturnToIt(): Action
    {
        return Action::make('nocReturnToIt')
            ->label('Resolved — return to IT')
            ->icon(Heroicon::OutlinedArrowUturnLeft)
            ->color('success')
            ->visible(fn (Outage $record) => Auth::user()?->can('returnToIt', $record))
            ->schema([Textarea::make('note')->label('What NOC fixed')->required()->maxLength(2000)])
            ->action(fn (Outage $record, array $data) => self::run($record, fn () => app(OutageWorkflow::class)->nocReturnToIt($record, Auth::user(), $data['note']), 'Returned to IT to confirm'));
    }

    public static function itReturnToNoc(): Action
    {
        return Action::make('itReturnToNoc')
            ->label('Not resolved — back to NOC')
            ->icon(Heroicon::OutlinedArrowUturnLeft)
            ->color('gray')
            ->visible(fn (Outage $record) => Auth::user()?->can('itConfirm', $record))
            ->schema([Textarea::make('reason')->label('What is still failing?')->required()->maxLength(2000)])
            ->action(fn (Outage $record, array $data) => self::run($record, fn () => app(OutageWorkflow::class)->itReturnToNoc($record, Auth::user(), $data['reason']), 'Returned to NOC'));
    }

    // ------------------------------------------------------------------ FIFO assignment

    public static function accept(): Action
    {
        return Action::make('acceptAssignment')
            ->label('Accept')
            ->icon(Heroicon::OutlinedHandThumbUp)
            ->color('success')
            ->visible(fn (Outage $record) => self::myAssignment($record)?->status === AssignmentStatus::Assigned)
            ->action(fn (Outage $record) => self::run($record, fn () => app(FifoAssignmentEngine::class)->accept(self::myAssignment($record), Auth::user()), 'Ticket accepted'));
    }

    public static function rejectAssignment(): Action
    {
        return Action::make('rejectAssignment')
            ->label('Reject assignment')
            ->icon(Heroicon::OutlinedHandThumbDown)
            ->color('gray')
            ->visible(fn (Outage $record) => self::myAssignment($record) !== null)
            ->schema([Textarea::make('reason')->label('Why can you not take this ticket?')->required()->maxLength(1000)])
            ->action(fn (Outage $record, array $data) => self::run($record, fn () => app(FifoAssignmentEngine::class)->reject(self::myAssignment($record), Auth::user(), $data['reason']), 'Ticket returned to the queue'));
    }

    public static function reassign(): Action
    {
        return Action::make('reassign')
            ->label('Assign / override FIFO')
            ->icon(Heroicon::OutlinedArrowsRightLeft)
            ->color('danger')
            ->visible(fn (Outage $record) => Auth::user()?->can('overrideAssignment', $record) && (! $record->isTicket() || self::ticketQueues($record) !== []))
            ->modalDescription('Manual assignment bypasses FIFO order. It is recorded with your name, the time and your reason.')
            ->fillForm(fn (Outage $record) => ['queue_type' => $record->current_queue?->value ?? ($record->isTicket() ? array_key_first(self::ticketQueues($record)) : QueueType::NocReview->value)])
            ->schema([
                // A customer ticket / site visit is reassigned where it is: moving it to another desk is "Escalate / hand over".
                Select::make('queue_type')->label('Queue')->required()->live()
                    ->options(fn (Outage $record) => $record->isTicket() ? self::ticketQueues($record) : QueueType::class)
                    ->afterStateUpdated(fn (Set $set) => $set('user_id', null)),
                Select::make('user_id')->label('Assign to')->required()->searchable()
                    ->options(fn (Get $get, Outage $record) => self::queueUserOptions($type = self::queueType($get('queue_type')), $type ? EligibilityEvaluator::visitPermission($record, $type) : null)),
                Textarea::make('reason')->label('Override reason')->required()->maxLength(1000),
            ])
            ->action(fn (Outage $record, array $data) => self::run($record, fn () => app(FifoAssignmentEngine::class)->manualAssign(
                $record, self::queueType($data['queue_type']) ?? QueueType::NocReview, User::query()->findOrFail($data['user_id']), Auth::user(), $data['reason'],
            ), 'Assignment overridden'));
    }

    /**
     * The queues a customer ticket / site visit is in now (its desk, and a follow-up or aftercare call), value => label.
     *
     * @return array<string, string>
     */
    private static function ticketQueues(Outage $record): array
    {
        return collect([$record->current_queue])
            ->merge($record->openQueueEntries()->pluck('queue_type'))
            ->filter()
            ->mapWithKeys(fn (QueueType $type) => [$type->value => $type->getLabel()])
            ->all();
    }

    /** An enum-backed Select hands back the enum once hydrated, or the raw value before that. */
    private static function queueType(mixed $state): ?QueueType
    {
        return $state instanceof QueueType ? $state : (is_string($state) ? QueueType::tryFrom($state) : null);
    }

    // ------------------------------------------------------------------ NOC

    public static function nocConfirm(): Action
    {
        return Action::make('nocConfirm')
            ->label('NOC Confirm')
            ->icon(Heroicon::OutlinedCheckBadge)
            ->color('success')
            ->visible(fn (Outage $record) => Auth::user()?->can('confirm', $record))
            ->schema([Textarea::make('note')->label('Confirmation note (optional)')->maxLength(2000)])
            ->action(fn (Outage $record, array $data) => self::run($record, fn () => app(OutageWorkflow::class)->confirmByNoc($record, Auth::user(), $data['note'] ?? null), 'Outage confirmed — routed automatically'));
    }

    public static function nocReject(): Action
    {
        return Action::make('nocReject')
            ->label('NOC Reject')
            ->icon(Heroicon::OutlinedXCircle)
            ->color('danger')
            ->visible(fn (Outage $record) => Auth::user()?->can('confirm', $record))
            ->schema([Textarea::make('reason')->label('Rejection reason (mandatory)')->required()->maxLength(2000)])
            ->action(fn (Outage $record, array $data) => self::run($record, fn () => app(OutageWorkflow::class)->rejectByNoc($record, Auth::user(), $data['reason']), 'Outage rejected'));
    }

    // ------------------------------------------------------------------ Infrastructure / dispatch

    public static function dispatch(): Action
    {
        return Action::make('dispatch')
            ->label('Dispatch')
            ->icon(Heroicon::OutlinedTruck)
            ->color('warning')
            ->visible(fn (Outage $record) => Auth::user()?->can('dispatch', $record))
            ->modalWidth('2xl')
            ->fillForm(fn (Outage $record) => [
                'team_type' => $record->team_type?->value ?? TeamType::InHouse->value,
                'team_id' => $record->team_id,
                'sub_team_id' => $record->sub_team_id,
            ])
            ->schema([
                Select::make('team_type')->label('Team type')->options(TeamType::class)->required()->live()
                    ->afterStateUpdated(function (Set $set): void {
                        $set('team_id', null);
                        $set('sub_team_id', null);
                        $set('technician_id', null);
                    }),
                Select::make('team_id')->label('Assigned team')->required()->live()
                    ->options(fn (Get $get) => self::teamOptions($get('team_type')))
                    ->afterStateUpdated(function (Set $set): void {
                        $set('sub_team_id', null);
                        $set('technician_id', null);
                    }),
                Select::make('sub_team_id')->label('Assigned sub-team')->live()
                    ->options(fn (Get $get) => $get('team_id') ? Team::query()->active()->where('parent_id', $get('team_id'))->orderBy('name')->pluck('name', 'id') : [])
                    ->afterStateUpdated(fn (Set $set) => $set('technician_id', null)),
                Select::make('technician_id')->label('Technician')
                    ->placeholder('Automatic — next eligible technician (FIFO)')
                    ->options(fn (Get $get) => self::technicianOptions($get('team_id'), $get('sub_team_id')))
                    ->searchable()
                    ->helperText('Only active members of the selected team / sub-team are listed; full eligibility (availability, shift, leave, workload) is verified on submit.'),
                Textarea::make('note')->label('Dispatch note')->maxLength(2000),
            ])
            ->action(fn (Outage $record, array $data) => self::run($record, fn () => app(OutageWorkflow::class)->dispatch($record, $data, Auth::user()), 'Dispatched'));
    }

    // ------------------------------------------------------------------ field / restoration

    public static function addUpdate(): Action
    {
        return Action::make('addUpdate')
            ->label('Add update')
            ->icon(Heroicon::OutlinedChatBubbleLeftEllipsis)
            ->visible(fn (Outage $record) => Auth::user()?->can('addUpdate', $record))
            ->schema([
                Select::make('type')->options(Arr::only(OutageUpdate::TYPES, OutageUpdate::MANUAL_TYPES))->default('progress')->required(),
                Textarea::make('body')->label('Update / action taken')->required()->rows(4)->maxLength(5000),
            ])
            ->action(fn (Outage $record, array $data) => self::run($record, fn () => app(OutageWorkflow::class)->addUpdate($record, Auth::user(), $data['body'], $data['type']), 'Update posted'));
    }

    public static function reportRestored(): Action
    {
        return Action::make('reportRestored')
            ->label(fn (Outage $record) => self::isMaintenance($record) || $record->maintenance_kind ? 'Report work completed' : 'Report restored')
            ->icon(Heroicon::OutlinedWrenchScrewdriver)
            ->color('success')
            ->visible(fn (Outage $record) => Auth::user()?->can('reportRestored', $record))
            // Customer uptime needs both the live line and the drop cable: wait for the support technician.
            ->disabled(fn (Outage $record) => $record->openSupportRequest !== null)
            ->tooltip(fn (Outage $record) => $record->openSupportRequest !== null
                ? 'Waiting for the support technician to finish the drop cable / premises work (or cancel the request).'
                : null)
            ->schema(fn (Outage $record) => [
                DateTimePicker::make('restored_at')->label('Restored at')->default(fn () => now()->startOfMinute())->seconds(false)->maxDate(fn () => now()->addMinutes(5)->startOfMinute()),
                Textarea::make('note')->label($record->maintenance_kind ? 'Work done' : 'Restoration details')->required()->maxLength(5000),
                // Field maintenance closes with two photos of the finished work.
                ...($record->maintenance_kind ? [
                    Grid::make(['default' => 1, 'sm' => 2])->schema([
                        self::photoUpload('after_outside', 'After — outside', OutagePhoto::POSITIONS['outside'], required: app(Settings::class)->bool('photos.maintenance_required')),
                        self::photoUpload('after_inside', 'After — inside', OutagePhoto::POSITIONS['inside'], required: app(Settings::class)->bool('photos.maintenance_required')),
                    ]),
                ] : []),
                // Emergency / Regular outage: the three site photos — those not on the ticket yet are required.
                ...($record->offersSitePhotos() ? [self::sitePhotoUploads($record, requireMissing: $record->requiresSitePhotos())] : []),
            ])
            ->action(fn (Outage $record, array $data) => self::run($record, fn () => app(OutageWorkflow::class)->reportRestored(
                $record, Auth::user(), $data['note'], $data['restored_at'] ?? null,
                match (true) {
                    (bool) $record->maintenance_kind => [
                        'outside' => $data['after_outside'] ?? null, 'inside' => $data['after_inside'] ?? null,
                        'outside_name' => $data['after_outside_name'] ?? null, 'inside_name' => $data['after_inside_name'] ?? null,
                    ],
                    $record->offersSitePhotos() => self::sitePhotoData($data),
                    default => [],
                },
            ), 'Work reported — awaiting NOC confirmation'));
    }

    /**
     * Site photos of an Emergency / Regular outage, added while the work is going on — so the
     * findings are on the ticket before the repair starts. On the website they are chosen from the
     * computer; in the mobile app they can only be taken with the camera.
     */
    public static function addSitePhotos(): Action
    {
        return Action::make('addSitePhotos')
            ->label(fn (Outage $record) => 'Site photos ('.(count(OutagePhoto::SITE_PHOTOS) - count($record->missingSitePhotos())).' of '.count(OutagePhoto::SITE_PHOTOS).')')
            ->icon(Heroicon::OutlinedCamera)
            ->color('gray')
            ->visible(fn (Outage $record) => Auth::user()?->can('addSitePhotos', $record))
            ->modalDescription(fn (Outage $record) => ($record->requiresSitePhotos() ? 'The outage is reported restored with all three photos.' : 'Photos of the work (optional).').' Add each one when it is taken; a photo added again replaces the earlier one.')
            ->schema(fn (Outage $record) => [self::sitePhotoUploads($record, requireMissing: false)])
            ->action(fn (Outage $record, array $data) => self::run($record, fn () => app(OutageWorkflow::class)->addSitePhotos($record, Auth::user(), self::sitePhotoData($data)), 'Site photos saved'));
    }

    /** The three upload fields; each says what is already on the ticket. */
    private static function sitePhotoUploads(Outage $record, bool $requireMissing): Grid
    {
        $taken = $record->sitePhotos();

        return Grid::make(['default' => 1, 'md' => 3])->schema(collect(OutagePhoto::SITE_PHOTOS)
            ->map(fn (array $photo, string $position) => self::photoUpload(
                'site_'.$position,
                $photo[0],
                $taken[$position] ? 'On the ticket ('.$taken[$position]->caption().') — choose a file only to replace it.' : $photo[1],
                required: $requireMissing && $taken[$position] === null,
            ))->values()->all());
    }

    /** Form state of {@see sitePhotoUploads()} → what the workflow stores. */
    private static function sitePhotoData(array $data): array
    {
        $photos = ['source' => OutagePhoto::SOURCE_UPLOAD];

        foreach (array_keys(OutagePhoto::SITE_PHOTOS) as $position) {
            $photos[$position] = $data['site_'.$position] ?? null;
            $photos[$position.'_name'] = $data['site_'.$position.'_name'] ?? null;
        }

        return $photos;
    }

    // ------------------------------------------------------------------ field maintenance

    public static function approvePreventive(): Action
    {
        return Action::make('approvePreventive')
            ->label(fn (Outage $record) => 'Approve downtime'.(($slot = self::approvalSlot($record)) ? ' ('.MaintenanceApproval::SLOTS[$slot][0].')' : ''))
            ->icon(Heroicon::OutlinedCheckBadge)
            ->color('success')
            ->visible(fn (Outage $record) => Auth::user()?->can('approvePreventive', $record))
            ->modalDescription(fn (Outage $record) => 'Downtime '.($record->downtime_start?->format('d M Y H:i') ?? '—').' – '.($record->downtime_end?->format('d M Y H:i') ?? '—')
                .' at '.($record->site_label ?? '—').'. When the Dispatcher and the Infrastructure Manager have both approved, everyone is notified and the technician starts the work.')
            ->schema([Textarea::make('comment')->label('Comment (optional)')->maxLength(2000)])
            ->action(fn (Outage $record, array $data) => self::run($record, fn () => app(OutageWorkflow::class)->approvePreventive($record, Auth::user(), $data['comment'] ?? null), 'Downtime approved'));
    }

    public static function rejectPreventive(): Action
    {
        return Action::make('rejectPreventive')
            ->label('Reject')
            ->icon(Heroicon::OutlinedXCircle)
            ->color('danger')
            ->visible(fn (Outage $record) => Auth::user()?->can('rejectPreventive', $record))
            ->schema([Textarea::make('reason')->label('Reason')->required()->maxLength(2000)])
            ->action(fn (Outage $record, array $data) => self::run($record, fn () => app(OutageWorkflow::class)->rejectPreventive($record, Auth::user(), $data['reason']), 'Preventive maintenance rejected'));
    }

    private static function approvalSlot(Outage $record): ?string
    {
        return ($user = Auth::user()) ? MaintenanceApproval::openSlotFor($record, $user) : null;
    }

    public static function photoUpload(string $name, string $label, string $hint, bool $required = true): FileUpload
    {
        return FileUpload::make($name)->label($label)->helperText($hint)
            ->image()->required($required)
            ->disk('local')->directory('outage-photos/'.now()->format('Y/m'))->visibility('private')
            ->maxSize(12288)
            ->storeFileNamesIn($name.'_name');
    }

    public static function confirmRestoration(): Action
    {
        return Action::make('confirmRestoration')
            ->label(fn (Outage $record) => self::isMaintenance($record) ? 'All customers up — send to CX' : 'NOC Restore Confirmed')
            ->icon(Heroicon::OutlinedShieldCheck)
            ->color('success')
            ->visible(fn (Outage $record) => Auth::user()?->can('confirmRestoration', $record))
            ->modalDescription(fn (Outage $record) => self::isMaintenance($record)
                ? 'Confirm every affected customer is back online. CX then notifies customers that the maintenance was completed.'
                : null)
            ->schema(fn (Outage $record) => self::isMaintenance($record)
                ? [
                    // CRF section 5 — post-maintenance outcome.
                    Radio::make('final_status')->label('Final status')->options(ChangeRequest::FINAL_STATUSES)->required()->default('successful'),
                    Textarea::make('outcome_details')->label('Success / activity details')->rows(3)->maxLength(5000)
                        ->placeholder('Post-execution results, metrics or follow-up action items (e.g. ONUs powered off to be tracked)…'),
                ]
                : [Textarea::make('note')->label('Verification note (optional)')->maxLength(2000)])
            ->action(fn (Outage $record, array $data) => self::run($record, fn () => app(OutageWorkflow::class)->confirmRestoration(
                $record, Auth::user(), $data['note'] ?? ($data['outcome_details'] ?? null), Arr::only($data, ['final_status', 'outcome_details']),
            ), self::isMaintenance($record) ? 'Verified — sent to CX to notify customers' : 'Restoration confirmed'));
    }

    public static function rejectRestoration(): Action
    {
        return Action::make('rejectRestoration')
            ->label(fn (Outage $record) => self::isMaintenance($record) ? 'Customers not all up — back to Infrastructure' : 'Restoration not confirmed')
            ->icon(Heroicon::OutlinedArrowUturnLeft)
            ->color('gray')
            ->visible(fn (Outage $record) => Auth::user()?->can('confirmRestoration', $record))
            ->schema([Textarea::make('reason')->label('What is still failing?')->required()->maxLength(2000)])
            ->action(fn (Outage $record, array $data) => self::run($record, fn () => app(OutageWorkflow::class)->rejectRestoration($record, Auth::user(), $data['reason']), 'Returned to the field team'));
    }

    public static function resolve(): Action
    {
        return Action::make('resolve')
            ->label('Resolve outage')
            ->icon(Heroicon::OutlinedCheckCircle)
            ->color('success')
            ->visible(fn (Outage $record) => Auth::user()?->can('resolve', $record))
            ->schema([
                Textarea::make('final_reason')->label('Final Reason / RFO')->rows(4)->maxLength(5000)
                    ->required(fn () => OutageStatus::forCode(StatusCode::Resolved)->prompt_final_reason)
                    ->default(fn (Outage $record) => $record->final_reason),
            ])
            ->action(fn (Outage $record, array $data) => self::run($record, fn () => app(OutageWorkflow::class)->resolve($record, Auth::user(), $data['final_reason'] ?? null), 'Outage resolved'));
    }

    public static function setStatus(): Action
    {
        return Action::make('setStatus')
            ->label('Set status')
            ->icon(Heroicon::OutlinedSignal)
            ->visible(fn (Outage $record) => Auth::user()?->can('changeStatus', $record))
            ->schema([
                Select::make('status_id')->label('Status')->required()->live()
                    ->options(fn () => OutageStatus::query()->active()->standard()->where('is_manual_selectable', true)->orderBy('sort_order')->pluck('name', 'id')),
                Textarea::make('reason')
                    ->label(fn (Get $get) => self::promptsFinalReason($get('status_id')) ? 'Final Reason (required by this status)' : 'Note')
                    ->required(fn (Get $get) => self::promptsFinalReason($get('status_id')))
                    ->maxLength(5000),
            ])
            ->action(fn (Outage $record, array $data) => self::run($record, fn () => app(OutageWorkflow::class)->changeStatus($record, OutageStatus::query()->findOrFail($data['status_id']), Auth::user(), $data['reason'] ?? null), 'Status updated'));
    }

    public static function customerCommunication(): Action
    {
        return Action::make('customerCommunication')
            ->label('SMS / Credit')
            ->icon(Heroicon::OutlinedDevicePhoneMobile)
            ->visible(fn (Outage $record) => Auth::user()?->can('updateFlags', $record))
            ->fillForm(fn (Outage $record) => ['sms_sent' => $record->sms_sent, 'credit_shared' => $record->credit_shared])
            ->schema([
                Toggle::make('sms_sent')->label('Customer SMS sent'),
                Toggle::make('credit_shared')->label('Credit shared'),
            ])
            ->action(function (Outage $record, array $data): void {
                self::run($record, function () use ($record, $data): void {
                    $workflow = app(OutageWorkflow::class);
                    foreach (['sms_sent', 'credit_shared'] as $flag) {
                        if ((bool) $data[$flag] !== (bool) $record->{$flag}) {
                            $workflow->setFlag($record, $flag, (bool) $data[$flag], Auth::user());
                        }
                    }
                }, 'Customer communication updated');
            });
    }

    // ------------------------------------------------------------------ helpers

    public static function myAssignment(Outage $outage): ?OutageAssignment
    {
        $userId = Auth::id();

        if (! $userId) {
            return null;
        }

        // Tables eager-load activeAssignments so row actions don't query per row.
        if ($outage->relationLoaded('activeAssignments')) {
            return $outage->activeAssignments->where('user_id', $userId)->sortByDesc('id')->first();
        }

        return OutageAssignment::query()
            ->where('outage_id', $outage->id)
            ->where('user_id', $userId)
            ->active()
            ->latest('id')
            ->first();
    }

    /** Form state holds the enum once Filament hydrates it, or the raw value before that. */
    public static function teamOptions(TeamType|string|null $teamType): array
    {
        $teamType = $teamType instanceof TeamType ? $teamType->value : $teamType;

        return Team::query()->active()->topLevel()
            ->whereIn('department_id', Department::query()->whereIn('code', ['INFRA', 'DISPATCH'])->pluck('id'))
            ->when($teamType, fn ($q) => $q->where('team_type', $teamType))
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    public static function technicianOptions(?int $teamId, ?int $subTeamId): array
    {
        if (! $teamId) {
            return [];
        }

        $teamIds = $subTeamId ? [$subTeamId] : Team::query()->find($teamId)?->selfAndSubTeamIds() ?? [];

        return User::permission('queue.work_technician')
            ->active()
            ->whereHas('teams', fn ($q) => $q->whereIn('teams.id', $teamIds))
            ->withCount('activeAssignments')
            ->orderBy('name')
            ->get()
            ->mapWithKeys(fn (User $user) => [$user->id => "{$user->name} — {$user->availability_status->getLabel()}, {$user->active_assignments_count} active"])
            ->all();
    }

    public static function queueUserOptions(?QueueType $type, ?string $alsoPermission = null): array
    {
        if (! $type) {
            return [];
        }

        return User::permission($type->defaultPermission())
            ->when($alsoPermission, fn ($q, string $permission) => $q->whereIn('id', User::permission($permission)->select('id')))
            ->active()
            ->withCount('activeAssignments')
            ->orderBy('name')
            ->get()
            ->mapWithKeys(fn (User $user) => [$user->id => "{$user->name} — {$user->availability_status->getLabel()}, {$user->active_assignments_count} active"])
            ->all();
    }

    private static function promptsFinalReason(mixed $statusId): bool
    {
        return $statusId && (bool) OutageStatus::query()->whereKey($statusId)->value('prompt_final_reason');
    }

    /** Run a workflow call, surface domain errors as notifications and refresh the record. */
    public static function run(Outage $record, callable $callback, string $success): void
    {
        try {
            $callback();
            Notification::make()->title($success)->success()->send();
        } catch (ValidationException $exception) {
            Notification::make()->title('Action not completed')->body(implode(' ', $exception->validator->errors()->all()))->danger()->send();
        } catch (AuthorizationException $exception) {
            Notification::make()->title('Not allowed')->body($exception->getMessage())->danger()->send();
        }

        $record->refresh();
    }
}
