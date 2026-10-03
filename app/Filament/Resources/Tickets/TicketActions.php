<?php

namespace App\Filament\Resources\Tickets;

use App\Enums\QueueType;
use App\Filament\Resources\MobilityOutages\MobilityActions;
use App\Filament\Resources\Outages\OutageActions;
use App\Models\DelayReason;
use App\Models\DeskRoute;
use App\Models\FinalReason;
use App\Models\Outage;
use App\Models\OutageAttachment;
use App\Models\Team;
use App\Models\TicketFollowUp;
use App\Models\User;
use App\Models\VisitType;
use App\Services\Outages\OutageWorkflow;
use App\Support\Settings;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;

/**
 * Customer ticket and site visit actions, shared by the ticket pages, the lists, My Work and the
 * FIFO queue. Every action delegates to OutageWorkflow, where the policy and the FIFO owner check
 * decide — so a ticket is only moved by the person holding it at its desk (or an override holder).
 */
final class TicketActions
{
    /** @return list<Action> */
    public static function primary(): array
    {
        return [
            self::assignVisit(), self::enRoute(), self::arrive(), self::resume(), self::completeVisit(), self::notCompleted(),
            self::verifyVisit(), self::rejectCompletion(), self::confirmVisit(),
            self::approve(), self::decline(), self::raiseVisit(), self::route(), self::returnToDesk(), self::followUp(), self::close(), self::reopen(),
        ];
    }

    /** @return list<Action> */
    public static function secondary(): array
    {
        return [
            self::pause(), self::reschedule(), self::intervene(), self::endIntervention(), self::linkOutage(), self::attach(), self::cancel(),
        ];
    }

    // ------------------------------------------------------------------ desk to desk

    public static function route(): Action
    {
        return Action::make('ticketRoute')
            ->label('Escalate / hand over')
            ->icon(Heroicon::OutlinedArrowUpCircle)
            ->color('warning')
            ->visible(fn (Outage $record) => Auth::user()?->can('ticketRoute', $record) && self::routeOptions($record) !== [])
            ->modalDescription(fn (Outage $record) => "From {$record->current_queue?->getLabel()}. The next available person at the desk you choose gets it (FIFO), unless you name someone.")
            ->schema([
                Select::make('desk')->label('Send to')->required()->live()->options(fn (Outage $record) => self::routeOptions($record)),
                Select::make('user_id')->label('A specific person there (optional)')->searchable()
                    ->placeholder('Automatic — next available person (FIFO)')
                    ->options(fn (Get $get) => OutageActions::queueUserOptions(QueueType::tryFrom((string) $get('desk')))),
                Select::make('delay_reason_id')->label('Reason for delay (optional)')->options(fn () => self::delayReasons())
                    ->visible(fn (Outage $record) => $record->isSiteVisit()),
                Textarea::make('note')->label('Why / what is needed')->required()->rows(3)->maxLength(2000),
            ])
            ->action(fn (Outage $record, array $data) => OutageActions::run($record, fn () => app(OutageWorkflow::class)->routeTicket(
                $record, Auth::user(), QueueType::from($data['desk']), $data['note'],
                filled($data['delay_reason_id'] ?? null) ? (int) $data['delay_reason_id'] : null,
                filled($data['user_id'] ?? null) ? (int) $data['user_id'] : null,
            ), 'Ticket handed over'));
    }

    public static function returnToDesk(): Action
    {
        return Action::make('ticketReturn')
            ->label('Return to previous desk')
            ->icon(Heroicon::OutlinedArrowUturnLeft)
            ->color('info')
            ->visible(fn (Outage $record) => Auth::user()?->can('ticketReturn', $record))
            ->modalDescription('The ticket goes back to the desk that handed it to you, with what you did / found.')
            ->schema([Textarea::make('note')->label('What was done / found')->required()->rows(3)->maxLength(2000)])
            ->action(fn (Outage $record, array $data) => OutageActions::run($record, fn () => app(OutageWorkflow::class)->returnTicket($record, Auth::user(), $data['note']), 'Ticket returned'));
    }

    public static function approve(): Action
    {
        return Action::make('ticketApprove')
            ->label('Approve')
            ->icon(Heroicon::OutlinedCheckBadge)
            ->color('success')
            ->visible(fn (Outage $record) => Auth::user()?->can('ticketApprove', $record))
            ->modalDescription('The approved request goes to the desk that carries it out (Billing), or back to the agent who asked.')
            ->fillForm(fn (Outage $record) => ['amount' => $record->ticket?->amount])
            ->schema([
                TextInput::make('amount')->label('Amount approved (optional)')->numeric()->minValue(0)->maxValue(999999999),
                Textarea::make('note')->label('Approval note')->rows(3)->maxLength(2000),
            ])
            ->action(fn (Outage $record, array $data) => OutageActions::run($record, fn () => app(OutageWorkflow::class)->approveTicket($record, Auth::user(), $data['note'] ?? null, $data['amount'] ?? null), 'Request approved'));
    }

    public static function decline(): Action
    {
        return Action::make('ticketDecline')
            ->label('Decline')
            ->icon(Heroicon::OutlinedXCircle)
            ->color('danger')
            ->visible(fn (Outage $record) => Auth::user()?->can('ticketApprove', $record))
            ->modalDescription('The ticket goes back so the customer can be told why, and is then closed as declined.')
            ->schema([Textarea::make('reason')->label('Reason for declining')->required()->rows(3)->maxLength(2000)])
            ->action(fn (Outage $record, array $data) => OutageActions::run($record, fn () => app(OutageWorkflow::class)->declineTicket($record, Auth::user(), $data['reason']), 'Request declined'));
    }

    // ------------------------------------------------------------------ customer contact

    public static function followUp(): Action
    {
        return Action::make('ticketFollowUp')
            ->label('Log customer call')
            ->icon(Heroicon::OutlinedPhoneArrowUpRight)
            ->visible(fn (Outage $record) => Auth::user()?->can('ticketFollowUp', $record) && ! Auth::user()?->can('visitConfirm', $record))
            ->modalHeading(fn (Outage $record) => 'Call to '.($record->ticket?->customer_name ?: 'the customer'))
            ->modalDescription(fn (Outage $record) => 'Numbers: '.(implode(' · ', $record->ticket?->phones() ?? []) ?: 'none on the ticket'))
            // fillForm replaces field defaults, so the time is set here.
            ->fillForm(fn () => ['called_at' => now()->startOfMinute()->toDateTimeString()])
            ->schema([
                Grid::make(['default' => 1, 'sm' => 2])->schema([
                    Select::make('outcome')->label('Outcome')->options(TicketFollowUp::OUTCOMES)->required(),
                    DateTimePicker::make('called_at')->label('Called at')->seconds(false)->maxDate(fn () => now()->addMinutes(5)->startOfMinute()),
                ]),
                Textarea::make('notes')->label('Notes')->rows(3)->maxLength(5000),
            ])
            ->action(fn (Outage $record, array $data) => OutageActions::run($record, fn () => app(OutageWorkflow::class)->recordTicketFollowUp($record, Auth::user(), $data), 'Customer call logged'));
    }

    public static function raiseVisit(): Action
    {
        return Action::make('ticketRaiseVisit')
            ->label('Raise site visit')
            ->icon(Heroicon::OutlinedMapPin)
            ->color('primary')
            ->visible(fn (Outage $record) => Auth::user()?->can('ticketRaiseVisit', $record))
            ->modalDescription('Books a site visit with the customer details of this ticket. The ticket then waits for the visit and closes with it.')
            ->modalWidth('2xl')
            ->fillForm(fn (Outage $record) => [
                'visit_type_id' => $record->ticket?->subcategory?->visit_type_id,
                'preferred_time' => $record->ticket?->preferred_time,
                'calling_number' => $record->ticket?->calling_number,
                'address' => $record->ticket?->address,
                'landmark' => $record->ticket?->landmark,
                'description' => $record->description,
            ])
            ->schema([
                Grid::make(['default' => 1, 'sm' => 2])->schema([
                    Select::make('visit_type_id')->label('Visit type')->required()->options(fn () => self::visitTypes()),
                    DateTimePicker::make('scheduled_at')->label('Date / time agreed with the customer')->seconds(false)->minDate(fn () => now()->startOfMinute()),
                    TextInput::make('preferred_time')->label('Customer availability')->maxLength(120)->placeholder('e.g. available today after 2pm'),
                    TextInput::make('calling_number')->label('Contact number confirmed')->maxLength(40),
                    TextInput::make('address')->label('Address confirmed')->maxLength(250),
                    TextInput::make('landmark')->label('Landmark')->maxLength(250),
                ]),
                Textarea::make('description')->label('What the technician must do')->rows(3)->maxLength(5000),
                Textarea::make('note')->label('Note on this ticket (optional)')->rows(2)->maxLength(1000),
            ])
            ->action(fn (Outage $record, array $data) => OutageActions::run($record, fn () => app(OutageWorkflow::class)->raiseSiteVisit($record, Auth::user(), $data), 'Site visit raised'));
    }

    // ------------------------------------------------------------------ site visit: dispatch

    public static function assignVisit(): Action
    {
        return Action::make('visitAssign')
            ->label(fn (Outage $record) => $record->current_queue === QueueType::VisitTechnician ? 'Reassign technician' : 'Assign technician')
            ->icon(Heroicon::OutlinedTruck)
            ->color('warning')
            ->visible(fn (Outage $record) => Auth::user()?->can('visitAssign', $record))
            ->modalDescription('Pick a technician, or leave it on automatic: the visit goes to the technician FIFO queue (a visit booked for a later day is only offered on that day). The customer is texted the technician\'s name and number.')
            ->fillForm(fn (Outage $record) => ['scheduled_at' => $record->ticket?->scheduled_at?->toDateTimeString(), 'team_id' => $record->team_id])
            ->schema([
                Select::make('technician_id')->label('Technician')->searchable()->live()
                    ->placeholder('Automatic — next available technician (FIFO)')
                    ->options(fn () => self::deskUsers(QueueType::VisitTechnician)),
                Select::make('team_id')->label('Only offer it to this team (optional)')->options(fn () => self::teams())
                    ->visible(fn (Get $get) => blank($get('technician_id')) && self::teams() !== []),
                DateTimePicker::make('scheduled_at')->label('Visit date / time')->seconds(false)->minDate(fn () => now()->startOfMinute())
                    ->helperText('Leave empty for “today, as soon as possible”.'),
                Textarea::make('note')->label('Instructions (optional)')->rows(2)->maxLength(2000),
            ])
            ->action(fn (Outage $record, array $data) => OutageActions::run($record, fn () => app(OutageWorkflow::class)->assignVisit($record, Auth::user(), $data),
                filled($data['technician_id'] ?? null) ? 'Technician assigned — customer texted' : 'Placed in the technician queue (FIFO)'));
    }

    public static function reschedule(): Action
    {
        return Action::make('visitReschedule')
            ->label('Reschedule')
            ->icon(Heroicon::OutlinedCalendarDays)
            ->visible(fn (Outage $record) => Auth::user()?->can('visitReschedule', $record))
            ->modalDescription('Sets the date / time the customer asked for and texts it to them.')
            ->fillForm(fn () => ['notify' => true])
            ->schema([
                DateTimePicker::make('scheduled_at')->label('New date / time')->seconds(false)->required()->minDate(fn () => now()->startOfMinute()),
                Textarea::make('reason')->label('Reason')->required()->rows(2)->maxLength(2000)->placeholder('e.g. Customer travelling, asked for Saturday morning'),
                Toggle::make('notify')->label('Text the customer the new time'),
            ])
            ->action(fn (Outage $record, array $data) => OutageActions::run($record, fn () => app(OutageWorkflow::class)->rescheduleVisit(
                $record, Auth::user(), $data['scheduled_at'], $data['reason'], (bool) ($data['notify'] ?? true),
            ), 'Visit rescheduled'));
    }

    // ------------------------------------------------------------------ site visit: technician

    public static function enRoute(): Action
    {
        return Action::make('visitEnRoute')
            ->label('En route')
            ->icon(Heroicon::OutlinedTruck)
            ->color('primary')
            ->visible(fn (Outage $record) => Auth::user()?->can('visitEnRoute', $record))
            ->requiresConfirmation()
            ->modalDescription('Tell the dispatcher you are on the way to the customer.')
            ->action(fn (Outage $record) => OutageActions::run($record, fn () => app(OutageWorkflow::class)->visitEnRoute($record, Auth::user()), 'On the way'));
    }

    public static function arrive(): Action
    {
        return Action::make('visitArrive')
            ->label('Start at customer premise')
            ->icon(Heroicon::OutlinedHomeModern)
            ->color('primary')
            ->visible(fn (Outage $record) => Auth::user()?->can('visitArrive', $record))
            ->requiresConfirmation()
            ->modalDescription('Starts the on-premise timer.')
            ->action(fn (Outage $record) => OutageActions::run($record, fn () => app(OutageWorkflow::class)->visitArrive($record, Auth::user()), 'Arrived — timer started'));
    }

    public static function pause(): Action
    {
        return Action::make('visitPause')
            ->label('Pause work')
            ->icon(Heroicon::OutlinedPause)
            ->visible(fn (Outage $record) => Auth::user()?->can('visitPause', $record))
            ->schema([Textarea::make('reason')->label('Reason (mandatory)')->required()->rows(2)->maxLength(250)])
            ->action(fn (Outage $record, array $data) => OutageActions::run($record, fn () => app(OutageWorkflow::class)->visitPause($record, Auth::user(), $data['reason']), 'Work paused'));
    }

    public static function resume(): Action
    {
        return Action::make('visitResume')
            ->label('Resume work')
            ->icon(Heroicon::OutlinedPlay)
            ->color('primary')
            ->visible(fn (Outage $record) => Auth::user()?->can('visitResume', $record))
            ->requiresConfirmation()
            ->action(fn (Outage $record) => OutageActions::run($record, fn () => app(OutageWorkflow::class)->visitResume($record, Auth::user()), 'Work resumed'));
    }

    public static function completeVisit(): Action
    {
        return Action::make('visitComplete')
            ->label('Complete visit')
            ->icon(Heroicon::OutlinedWrenchScrewdriver)
            ->color('success')
            ->visible(fn (Outage $record) => Auth::user()?->can('visitComplete', $record))
            ->modalHeading('Work completed')
            ->modalDescription(fn (Outage $record) => self::needsEvidence($record)
                ? 'The FAT number, a geolocation photo and a speed-test photo are required. The visit returns to the dispatcher for the final close.'
                : 'The visit returns to the dispatcher for the final close.')
            ->modalWidth('3xl')
            ->fillForm(fn (Outage $record) => ['completed_at' => now()->startOfMinute()->toDateTimeString(), 'fat_number' => $record->ticket?->fat_number])
            ->schema(fn (Outage $record) => [
                Grid::make(['default' => 1, 'sm' => 2])->schema([
                    Select::make('final_reason_id')->label('Final reason')->required()->searchable()->options(fn () => self::finalReasons('visit')),
                    TextInput::make('fat_number')->label('FAT number')->maxLength(60)->required(self::needsEvidence($record)),
                    self::photo('geo_photo', 'Geolocation photo', 'Photo with the location stamp at the customer premise.')
                        ->required(self::needsEvidence($record) && ! $record->attachments()->where('kind', 'geo_photo')->exists()),
                    self::photo('speed_photo', 'Speed test photo', 'Screenshot / photo of the speed test after the fix.')
                        ->required(self::needsEvidence($record) && ! $record->attachments()->where('kind', 'speed_test')->exists()),
                ]),
                Textarea::make('resolution_notes')->label('Work done')->required()->rows(3)->maxLength(5000),
                Grid::make(['default' => 2, 'sm' => 3])->schema([
                    TextInput::make('onu_type')->label('ONU type used')->maxLength(60),
                    TextInput::make('drop_cable_m')->label('Drop cable (m)')->numeric()->minValue(0)->maxValue(100000),
                    TextInput::make('ethernet_m')->label('Ethernet cable (m)')->numeric()->minValue(0)->maxValue(100000),
                    TextInput::make('patch_cord')->label('Patch cord')->maxLength(60),
                    TextInput::make('atb')->label('ATB')->maxLength(60),
                    TextInput::make('extender')->label('Extender')->maxLength(60),
                ]),
                DateTimePicker::make('completed_at')->label('Completed at')->seconds(false)->maxDate(fn () => now()->addMinutes(5)->startOfMinute()),
            ])
            ->action(fn (Outage $record, array $data) => OutageActions::run($record, fn () => app(OutageWorkflow::class)->completeVisit($record, Auth::user(), $data), 'Completed — sent to the dispatcher'));
    }

    public static function notCompleted(): Action
    {
        return Action::make('visitNotCompleted')
            ->label('Could not complete')
            ->icon(Heroicon::OutlinedExclamationTriangle)
            ->color('danger')
            ->visible(fn (Outage $record) => Auth::user()?->can('visitNotCompleted', $record))
            ->fillForm(fn () => ['to' => 'dispatch'])
            ->schema([
                Radio::make('to')->label('What is needed')->required()->options([
                    'dispatch' => 'Escalation — the dispatcher decides (Infrastructure Support, Design, NOC, Access…)',
                    'field_support' => 'The customer — unreachable, asked to reschedule or to be called back (CX Field Support)',
                ]),
                Select::make('delay_reason_id')->label('Reason for delay')->options(fn () => self::delayReasons()),
                Textarea::make('reason')->label('What happened on site')->required()->rows(3)->maxLength(2000),
            ])
            ->action(fn (Outage $record, array $data) => OutageActions::run($record, fn () => app(OutageWorkflow::class)->visitNotCompleted(
                $record, Auth::user(), $data['reason'], filled($data['delay_reason_id'] ?? null) ? (int) $data['delay_reason_id'] : null, ($data['to'] ?? 'dispatch') === 'field_support',
            ), 'Visit handed back'));
    }

    // ------------------------------------------------------------------ verification / confirmation

    public static function verifyVisit(): Action
    {
        return Action::make('visitVerify')
            ->label('Verify & close')
            ->icon(Heroicon::OutlinedShieldCheck)
            ->color('success')
            ->visible(fn (Outage $record) => Auth::user()?->can('visitVerify', $record))
            ->modalHeading('Dispatcher final close')
            ->modalDescription(fn () => app(Settings::class)->bool('ticketing.confirm_closure')
                ? 'CX Field Support then calls the customer to confirm before the visit is closed.'
                : 'The visit is closed.')
            ->schema(fn (Outage $record) => [
                Select::make('delay_reason_id')->label('Reason for delay')->options(fn () => self::delayReasons())
                    ->visible(self::late($record))->required(self::late($record))
                    ->helperText('This visit is past its SLA: a reason for the delay is required.'),
                Textarea::make('note')->label('Verification note (optional)')->rows(2)->maxLength(2000)->placeholder('e.g. Link online, power -19 dBm'),
            ])
            ->action(fn (Outage $record, array $data) => OutageActions::run($record, fn () => app(OutageWorkflow::class)->verifyVisit(
                $record, Auth::user(), $data['note'] ?? null, filled($data['delay_reason_id'] ?? null) ? (int) $data['delay_reason_id'] : null,
            ), 'Verified'));
    }

    public static function rejectCompletion(): Action
    {
        return Action::make('visitRejectCompletion')
            ->label('Not done — send back')
            ->icon(Heroicon::OutlinedArrowUturnLeft)
            ->color('gray')
            ->visible(fn (Outage $record) => Auth::user()?->can('visitVerify', $record))
            ->modalDescription('The completion is withdrawn and the visit goes back to the technician who did it.')
            ->schema([Textarea::make('reason')->label('What is not right?')->required()->rows(3)->maxLength(2000)])
            ->action(fn (Outage $record, array $data) => OutageActions::run($record, fn () => app(OutageWorkflow::class)->rejectVisitCompletion($record, Auth::user(), $data['reason']), 'Sent back to the technician'));
    }

    public static function confirmVisit(): Action
    {
        return Action::make('visitConfirm')
            ->label('Confirm with customer')
            ->icon(Heroicon::OutlinedPhoneArrowUpRight)
            ->color('success')
            ->visible(fn (Outage $record) => Auth::user()?->can('visitConfirm', $record))
            ->modalHeading(fn (Outage $record) => 'Closure call to '.($record->ticket?->customer_name ?: 'the customer'))
            ->modalDescription(fn (Outage $record) => 'Numbers: '.(implode(' · ', $record->ticket?->phones() ?? []) ?: 'none on the ticket')
                .'. “Confirms it is resolved” closes the visit; “still not resolved” reopens it to the dispatcher.')
            ->schema([
                Select::make('outcome')->label('Outcome')->options(TicketFollowUp::OUTCOMES)->required()->live(),
                Textarea::make('notes')->label('What the customer said')->rows(3)->maxLength(5000),
                Toggle::make('close_unconfirmed')->label('Close anyway (customer not reached, link confirmed online)')
                    ->visible(fn (Get $get) => in_array($get('outcome'), TicketFollowUp::NOT_REACHED, true)),
            ])
            ->action(fn (Outage $record, array $data) => OutageActions::run($record, fn () => app(OutageWorkflow::class)->confirmVisit($record, Auth::user(), $data), 'Closure call recorded'));
    }

    // ------------------------------------------------------------------ close / cancel / reopen

    public static function close(): Action
    {
        return Action::make('ticketClose')
            ->label(fn (Outage $record) => $record->ticket?->approval_state === 'declined' ? 'Close as declined' : 'Close ticket')
            ->icon(Heroicon::OutlinedCheckCircle)
            ->color('success')
            ->visible(fn (Outage $record) => Auth::user()?->can('ticketClose', $record))
            ->modalDescription(fn (Outage $record) => $record->isSiteVisit()
                ? 'Closes the visit without field work (for example the customer confirmed uptime on the call).'
                : 'Closes the ticket with its final reason.')
            ->schema(fn (Outage $record) => [
                Select::make('final_reason_id')->label('Final reason')->required()->searchable()
                    ->options(fn () => self::finalReasons($record->isSiteVisit() ? 'visit' : 'ticket')),
                Select::make('delay_reason_id')->label('Reason for delay')->options(fn () => self::delayReasons())
                    ->required(self::late($record))
                    ->helperText(self::late($record) ? 'This ticket is past its SLA: a reason for the delay is required.' : null),
                Textarea::make('resolution_notes')->label('Resolution / what the customer was told')->rows(3)->maxLength(5000),
                Toggle::make('customer_confirmed')->label('The customer confirmed it is resolved'),
            ])
            ->action(fn (Outage $record, array $data) => OutageActions::run($record, fn () => app(OutageWorkflow::class)->closeTicket($record, Auth::user(), $data), 'Ticket closed'));
    }

    public static function cancel(): Action
    {
        return Action::make('ticketCancel')
            ->label('Cancel / duplicate')
            ->icon(Heroicon::OutlinedNoSymbol)
            ->color('danger')
            ->visible(fn (Outage $record) => Auth::user()?->can('ticketCancel', $record))
            ->modalDescription('Cancelled and duplicate tickets have no SLA outcome and are left out of the reports.')
            ->schema([
                Toggle::make('duplicate')->label('This is a duplicate of another ticket'),
                Textarea::make('reason')->label('Reason')->required()->rows(2)->maxLength(2000)->placeholder('e.g. Duplicate of SUP-102'),
            ])
            ->action(fn (Outage $record, array $data) => OutageActions::run($record, fn () => app(OutageWorkflow::class)->cancelTicket($record, Auth::user(), $data['reason'], ! empty($data['duplicate'])), 'Ticket cancelled'));
    }

    public static function reopen(): Action
    {
        return Action::make('ticketReopen')
            ->label('Reopen')
            ->icon(Heroicon::OutlinedArrowPath)
            ->color('warning')
            ->visible(fn (Outage $record) => Auth::user()?->can('ticketReopen', $record))
            ->modalDescription('The ticket returns to its desk (site visits to dispatch). The previous closure is kept in the reopening history.')
            ->schema([Textarea::make('reason')->label('Reason for reopening')->required()->rows(3)->maxLength(2000)])
            ->action(fn (Outage $record, array $data) => OutageActions::run($record, fn () => app(OutageWorkflow::class)->reopenTicket($record, Auth::user(), $data['reason']), 'Ticket reopened'));
    }

    // ------------------------------------------------------------------ team leader / outage / files

    public static function intervene(): Action
    {
        return Action::make('ticketIntervene')
            ->label('Start intervention')
            ->icon(Heroicon::OutlinedHandRaised)
            ->color('danger')
            ->visible(fn (Outage $record) => Auth::user()?->can('ticketIntervene', $record))
            ->modalHeading('Team leader intervention')
            ->modalDescription('You take the ticket over at its desk. The take-over, a separate intervention timer and who finally closes the ticket are all recorded.')
            ->schema([Textarea::make('reason')->label('Why are you intervening?')->required()->rows(3)->maxLength(2000)])
            ->action(fn (Outage $record, array $data) => OutageActions::run($record, fn () => app(OutageWorkflow::class)->startIntervention($record, Auth::user(), $data['reason']), 'Intervention started — the ticket is yours'));
    }

    public static function endIntervention(): Action
    {
        return Action::make('ticketEndIntervention')
            ->label('End intervention')
            ->icon(Heroicon::OutlinedHandRaised)
            ->visible(fn (Outage $record) => Auth::user()?->can('ticketEndIntervention', $record))
            ->fillForm(fn () => ['hand_back' => true])
            ->schema([
                Toggle::make('hand_back')->label('Hand the ticket back to the queue')->helperText('Off: you keep the ticket and finish it yourself.'),
                Textarea::make('note')->label('Note')->rows(2)->maxLength(2000),
            ])
            ->action(fn (Outage $record, array $data) => OutageActions::run($record, fn () => app(OutageWorkflow::class)->endIntervention($record, Auth::user(), $data['note'] ?? null, (bool) ($data['hand_back'] ?? true)), 'Intervention ended'));
    }

    public static function linkOutage(): Action
    {
        return Action::make('ticketLinkOutage')
            ->label('Link to outage')
            ->icon(Heroicon::OutlinedLink)
            ->visible(fn (Outage $record) => Auth::user()?->can('ticketLinkOutage', $record))
            ->modalDescription('Marks the customer as affected by a network outage (reason for delay: affected by an outage). If no outage ticket exists yet, raise one first under Operations → Outages, even before NOC confirms it.')
            ->fillForm(fn (Outage $record) => ['outage_id' => $record->ticket?->linked_outage_id])
            ->schema([
                Select::make('outage_id')->label('Ongoing outage')->searchable()->placeholder('No outage (remove the link)')
                    ->options(fn () => Outage::query()->network()->active()->latest('start_time')->limit(150)->get(['id', 'ticket_number', 'title'])
                        ->mapWithKeys(fn (Outage $outage) => [$outage->id => "{$outage->ticket_number} — ".str($outage->title)->limit(70)])),
            ])
            ->action(fn (Outage $record, array $data) => OutageActions::run($record, fn () => app(OutageWorkflow::class)->linkTicketOutage(
                $record, Auth::user(), filled($data['outage_id'] ?? null) ? (int) $data['outage_id'] : null,
            ), 'Outage link updated'));
    }

    public static function attach(): Action
    {
        return Action::make('ticketAttach')
            ->label('Attach files')
            ->icon(Heroicon::OutlinedPaperClip)
            ->visible(fn (Outage $record) => Auth::user()?->can('ticketAttach', $record))
            ->schema([
                MobilityActions::attachmentUpload('files', 'Files')->required()->storeFileNamesIn('file_names'),
                Select::make('kind')->label('What is it?')->placeholder('Other document')
                    ->options(['geo_photo' => 'Geolocation photo', 'speed_test' => 'Speed test photo', 'evidence' => 'Other evidence of the work']),
                TextInput::make('note')->label('Note (optional)')->maxLength(255),
            ])
            ->action(fn (Outage $record, array $data) => OutageActions::run($record, fn () => app(OutageWorkflow::class)->addTicketAttachments(
                $record, Auth::user(), (array) ($data['files'] ?? []), (array) ($data['file_names'] ?? []), $data['note'] ?? null, $data['kind'] ?? null,
            ), 'Files attached'));
    }

    // ------------------------------------------------------------------ helpers

    /** Desks this ticket may be sent to from where it is: the desk routes, plus the desk that approves this kind of request. */
    public static function routeOptions(Outage $record): array
    {
        $options = DeskRoute::targets($record->current_queue);
        $approval = $record->isCxTicket() ? $record->ticket?->subcategory?->approval_desk : null;

        if ($approval && $approval !== $record->current_queue) {
            $options = [$approval->value => 'For approval: '.$approval->getLabel()] + $options;
        }

        return $options;
    }

    /** Active users who may work a desk, with their availability and current workload. */
    public static function deskUsers(QueueType $desk): array
    {
        return User::permission($desk->defaultPermission())
            ->active()
            ->with('teams:id,name')
            ->withCount('activeAssignments')
            ->orderBy('name')
            ->get()
            ->mapWithKeys(fn (User $user) => [$user->id => "{$user->name}".($user->teams->isNotEmpty() ? ' ('.$user->teams->pluck('name')->implode(', ').')' : '')
                ." — {$user->availability_status->getLabel()}, {$user->active_assignments_count} active"])
            ->all();
    }

    public static function teams(): array
    {
        return Team::query()->active()->orderBy('name')->pluck('name', 'id')->all();
    }

    public static function visitTypes(): array
    {
        return VisitType::query()->active()->orderBy('sort_order')->get(['id', 'name', 'code_prefix'])
            ->mapWithKeys(fn (VisitType $type) => [$type->id => "{$type->name} ({$type->code_prefix})"])->all();
    }

    public static function finalReasons(string $kind): array
    {
        return FinalReason::query()->active()->for($kind)->orderBy('name')->pluck('name', 'id')->all();
    }

    public static function delayReasons(): array
    {
        return DelayReason::query()->active()->orderBy('sort_order')->pluck('name', 'id')->all();
    }

    /** Past its SLA deadline and no reason for the delay recorded yet. */
    public static function late(Outage $record): bool
    {
        $end = $record->ticket?->completed_at ?? now();

        return $record->sla_due_at !== null && $end->greaterThan($record->sla_due_at) && $record->ticket?->delay_reason_id === null;
    }

    public static function needsEvidence(Outage $record): bool
    {
        return app(Settings::class)->bool('ticketing.require_evidence') && (bool) $record->ticket?->visitType?->requires_evidence;
    }

    private static function photo(string $name, string $label, string $hint): FileUpload
    {
        return FileUpload::make($name)->label($label)->helperText($hint)
            ->image()
            ->disk('local')->directory(OutageAttachment::DIRECTORY.'/'.now()->format('Y/m'))->visibility('private')
            ->maxSize(12288)
            ->storeFileNamesIn($name.'_name');
    }
}
