<?php

namespace App\Filament\Resources\Tickets;

use App\Enums\QueueType;
use App\Filament\Resources\MobilityOutages\MobilityActions;
use App\Filament\Resources\Outages\OutageActions;
use App\Models\DelayReason;
use App\Models\DeskRoute;
use App\Models\Fdt;
use App\Models\FinalReason;
use App\Models\Olt;
use App\Models\Outage;
use App\Models\OutageAttachment;
use App\Models\Region;
use App\Models\Team;
use App\Models\TicketFollowUp;
use App\Models\User;
use App\Models\Vendor;
use App\Models\Vertical;
use App\Models\VisitType;
use App\Services\Fifo\EligibilityEvaluator;
use App\Services\Outages\OutageWorkflow;
use App\Support\AftercareCalls;
use App\Support\Permissions;
use App\Support\Settings;
use App\Support\VisitPhotos;
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
use Filament\Schemas\Components\Utilities\Set;
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
            self::assignVisit(), self::enRoute(), self::arrive(), self::resume(), self::completeVisit(), self::notCompleted(), self::provisionRequest(), self::provisioned(), self::aftercareCall(),
            self::verifyVisit(), self::rejectCompletion(), self::confirmVisit(),
            self::approve(), self::decline(), self::raiseVisit(), self::vendorEscalate(), self::vendorFeedback(), self::route(), self::returnToDesk(), self::followUp(), self::close(), self::reopen(),
        ];
    }

    /** @return list<Action> */
    public static function secondary(): array
    {
        return [
            self::relocate(), self::pause(), self::reschedule(), self::intervene(), self::endIntervention(), self::linkOutage(), self::attach(), self::cancel(),
        ];
    }

    // ------------------------------------------------------------------ 4G: the team's vendor

    /** The IP, RF or Core team calls in one of its vendors: the vendor gets the e-mail, reminders follow. */
    public static function vendorEscalate(): Action
    {
        return Action::make('ticketVendorEscalate')
            ->label(fn (Outage $record) => $record->vendor_id ? 'Change vendor' : 'Escalate to vendor')
            ->icon(Heroicon::OutlinedBuildingOffice2)
            ->color('warning')
            ->visible(fn (Outage $record) => Auth::user()?->can('ticketVendorEscalate', $record))
            ->modalDescription(fn (Outage $record) => "The vendor is e-mailed the ticket and reminded while it says nothing. The ticket stays with the {$record->current_queue?->getLabel()}: record the vendor's feedback here when it comes in.")
            ->schema([
                Select::make('vendor_id')->label('Vendor')->required()->searchable()
                    ->options(fn (Outage $record) => Vendor::query()->active()->where('vendor_type', $record->current_queue?->vendorType())->orderBy('name')->pluck('name', 'id'))
                    ->default(fn (Outage $record) => $record->vendor_id)
                    ->helperText(fn (Outage $record) => 'Vendors of type “'.(Vendor::TYPES[$record->current_queue?->vendorType()] ?? '—').'” (4G NOC › Vendors).'),
                Textarea::make('note')->label('What the vendor is asked to do')->required()->rows(3)->maxLength(2000),
            ])
            ->action(fn (Outage $record, array $data) => OutageActions::run($record, fn () => app(OutageWorkflow::class)->escalateTicketToVendor(
                $record, Auth::user(), (int) $data['vendor_id'], $data['note'],
            ), 'Escalated to the vendor'));
    }

    public static function vendorFeedback(): Action
    {
        return Action::make('ticketVendorFeedback')
            ->label('Record vendor feedback')
            ->icon(Heroicon::OutlinedChatBubbleBottomCenterText)
            ->color('info')
            ->visible(fn (Outage $record) => Auth::user()?->can('ticketVendorFeedback', $record))
            ->modalDescription(fn (Outage $record) => 'What '.($record->vendor?->name ?? 'the vendor').' said. Everyone on the ticket is told. When the service is up, send the ticket to the 4G NOC with the reason (Escalate / hand over).')
            ->schema([
                Textarea::make('feedback')->label('Vendor feedback')->required()->rows(4)->maxLength(5000),
            ])
            ->action(fn (Outage $record, array $data) => OutageActions::run($record, fn () => app(OutageWorkflow::class)->recordTicketVendorFeedback(
                $record, Auth::user(), $data['feedback'],
            ), 'Vendor feedback recorded'));
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
                    ->options(fn (Get $get, Outage $record) => OutageActions::queueUserOptions($desk = QueueType::tryFrom((string) $get('desk')), EligibilityEvaluator::visitPermission($record, $desk))),
                Select::make('delay_reason_id')->label('Reason for delay (optional)')->options(fn (Outage $record) => self::delayReasons($record->vertical_id))
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
                    ->options(fn (Outage $record) => self::visitTechnicians($record))
                    ->helperText(fn (Outage $record) => (($needs = EligibilityEvaluator::visitTechnicianPermission($record)) ? 'Only technicians with “'.Permissions::label($needs).'” are listed and offered this visit.' : null)),
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
            ->modalDescription('Starts the on-premise timer. The photos and “Complete visit” are available from here.')
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
            ->modalDescription(fn (Outage $record) => match (true) {
                ! self::needsEvidence($record) => 'The visit returns to the dispatcher for the final close.',
                // Installation / relocation: the nine photos are taken in the mobile app — nothing is uploaded for them here.
                VisitPhotos::appOnly($record) => 'The FAT number and all '.count(VisitPhotos::of($record)).' installation photos are required. The photos are taken in the mobile app ('
                    .VisitPhotos::progress($record).' on the visit'.(($missing = VisitPhotos::missing($record)) !== []
                        ? '; still to take: '.collect(array_keys($missing))->map(fn (string $kind) => VisitPhotos::short($kind))->implode('; ')
                        : '').'). The visit returns to the dispatcher for the final close.',
                default => 'The FAT number, a geolocation photo and a speed-test photo are required. The visit returns to the dispatcher for the final close.',
            })
            ->modalWidth('3xl')
            ->fillForm(fn (Outage $record) => ['completed_at' => now()->startOfMinute()->toDateTimeString(), 'fat_number' => $record->ticket?->fat_number,
                'onu_status' => VisitPhotos::onuChange($record) ? VisitPhotos::ONU_REPLACED : VisitPhotos::ONU_EXISTING])
            ->schema(fn (Outage $record) => [
                Grid::make(['default' => 1, 'sm' => 2])->schema([
                    Select::make('final_reason_id')->label('Final reason')->required()->searchable()->options(fn (Outage $record) => self::finalReasons('visit', $record->current_queue, $record->vertical_id)),
                    TextInput::make('fat_number')->label('FAT number')->maxLength(60)->required(self::needsEvidence($record)),
                    // The photos of the visit (a new ONU adds its MAC address and the smart devices working); already taken ones are optional.
                    ...(VisitPhotos::appOnly($record) ? [] : collect(VisitPhotos::of($record))->except(VisitPhotos::locked($record))
                        ->map(fn (array $photo, string $kind) => self::photo($photo['field'], $photo['label'], $photo['help'])
                            ->required(self::needsEvidence($record) && ! $record->attachments()->where('kind', $kind)->exists()))
                        ->values()->all()),
                ]),
                // Support and other visits: did the customer keep their ONU? A new one is provisioned by the NOC first.
                Radio::make('onu_status')->label('ONU')->inline()->required()->options(VisitPhotos::ONU_STATUSES)
                    ->default(fn () => VisitPhotos::onuChange($record) ? VisitPhotos::ONU_REPLACED : VisitPhotos::ONU_EXISTING)
                    ->disableOptionWhen(fn (string $value) => VisitPhotos::onuChange($record) && $value === VisitPhotos::ONU_EXISTING)
                    ->helperText(VisitPhotos::onuChange($record) ? 'A new ONU: provisioned by the NOC.' : 'ONU replaced? Use “ONU replaced — send to NOC for provisioning” first.')
                    ->visible(! VisitPhotos::isRelocation($record)),
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
                Select::make('delay_reason_id')->label('Reason for delay')->options(fn (Outage $record) => self::delayReasons($record->vertical_id)),
                Textarea::make('reason')->label('What happened on site')->required()->rows(3)->maxLength(2000),
            ])
            ->action(fn (Outage $record, array $data) => OutageActions::run($record, fn () => app(OutageWorkflow::class)->visitNotCompleted(
                $record, Auth::user(), $data['reason'], filled($data['delay_reason_id'] ?? null) ? (int) $data['delay_reason_id'] : null, ($data['to'] ?? 'dispatch') === 'field_support',
            ), 'Visit handed back'));
    }

    // ------------------------------------------------------------------ relocation (Shifting)

    /** Technician on site: the FTTH NOC provisions the service before the speed tests and the smart-device photos. */
    public static function provisionRequest(): Action
    {
        return Action::make('visitProvisionRequest')
            ->label(fn (Outage $record) => VisitPhotos::needsProvisioning($record) ? 'Send to NOC for provisioning' : 'ONU replaced — send to NOC for provisioning')
            ->icon(Heroicon::OutlinedServerStack)
            ->color(fn (Outage $record) => VisitPhotos::needsProvisioning($record) ? 'warning' : 'gray')
            ->visible(fn (Outage $record) => Auth::user()?->can('visitProvisionRequest', $record))
            ->modalDescription(fn (Outage $record) => (VisitPhotos::isRelocation($record) ? 'The FTTH NOC provisions the service at the new location' : 'A new ONU: the FTTH NOC provisions it from the photo of its MAC address')
                .' and sends the visit back to you. Then take the speed tests and the smart-device photos and complete the visit.')
            ->schema(fn (Outage $record) => [
                ...(VisitPhotos::isRelocation($record) ? [] : [
                    self::photo('onu_mac', VisitPhotos::PHOTOS['onu_mac']['label'], VisitPhotos::PHOTOS['onu_mac']['help'])->required(),
                ]),
                Textarea::make('note')->label('What to provision')->required()->rows(3)->maxLength(2000)->placeholder('ONU serial number, OLT port, package…'),
            ])
            ->action(fn (Outage $record, array $data) => OutageActions::run($record, fn () => app(OutageWorkflow::class)->requestProvisioning($record, Auth::user(), $data['note'], $data),
                'Sent to the NOC for provisioning'));
    }

    /** NOC: provisioning done — the visit goes back to the technician who asked. */
    public static function provisioned(): Action
    {
        return Action::make('visitProvisioned')
            ->label('Provisioning done — back to the technician')
            ->icon(Heroicon::OutlinedCheckCircle)
            ->color('success')
            ->visible(fn (Outage $record) => Auth::user()?->can('visitProvisioned', $record))
            ->modalDescription(fn (Outage $record) => 'The visit goes back to '.($record->ticket?->provisionRequestedBy?->name ?? 'the technician').', who takes the speed tests and the smart-device photos. Could not provision? Use “Return to previous desk” instead: it goes to the dispatcher.')
            ->schema([
                Textarea::make('note')->label('Note for the technician (optional)')->rows(2)->maxLength(2000),
            ])
            ->action(fn (Outage $record, array $data) => OutageActions::run($record, fn () => app(OutageWorkflow::class)->confirmProvisioning($record, Auth::user(), $data['note'] ?? null),
                'Provisioned — back to the technician'));
    }

    /** Dispatcher: the customer moves to another OLT / region — new location, and a dispatcher of the new area. */
    public static function relocate(): Action
    {
        $id = fn (mixed $value) => is_int($value) || (is_string($value) && ctype_digit($value)) ? (int) $value : null;

        return Action::make('visitRelocate')
            ->label('Relocate to another OLT / region')
            ->icon(Heroicon::OutlinedArrowsRightLeft)
            ->color('gray')
            ->visible(fn (Outage $record) => Auth::user()?->can('visitRelocate', $record))
            ->modalDescription('The customer is moving to a location served by another OLT or region. The visit takes the new location and goes to a dispatcher there, who assigns a team.')
            ->fillForm(fn (Outage $record) => ['landmark' => $record->ticket?->landmark])
            ->schema([
                Select::make('olt_id')->label('New OLT')->required()->searchable()->live()
                    ->options(fn () => Olt::query()->active()->orderBy('name')->pluck('name', 'id'))
                    ->afterStateUpdated(function (Set $set, mixed $state) use ($id): void {
                        $set('fdt_id', null);
                        $set('region_id', $id($state) ? Olt::query()->whereKey($id($state))->value('region_id') : null);
                    }),
                Select::make('fdt_id')->label('New FDT (optional)')->searchable()
                    ->options(fn (Get $get) => $id($get('olt_id')) ? Fdt::query()->where('olt_id', $id($get('olt_id')))->orderBy('name')->pluck('name', 'id') : []),
                Select::make('region_id')->label('Region')->options(fn () => Region::query()->active()->orderBy('name')->pluck('name', 'id'))
                    ->helperText('Taken from the OLT; change it if the new area is another region.'),
                TextInput::make('address')->label('New address')->required()->maxLength(250),
                TextInput::make('road')->label('New road / street')->maxLength(250),
                TextInput::make('landmark')->label('Landmark')->maxLength(250),
                Select::make('dispatcher_id')->label('Dispatcher of the new area (optional)')->searchable()
                    ->placeholder('Automatic — the next available dispatcher of the region (FIFO)')
                    ->options(fn (Outage $record) => self::deskUsers($desk = EligibilityEvaluator::visitDispatchDesk($record), EligibilityEvaluator::visitPermission($record, $desk))),
                Textarea::make('note')->label('Note (optional)')->rows(2)->maxLength(2000),
            ])
            ->action(fn (Outage $record, array $data) => OutageActions::run($record, fn () => app(OutageWorkflow::class)->relocateVisit($record, Auth::user(), $data),
                'Relocated — sent to the dispatcher of the new area'));
    }

    // ------------------------------------------------------------------ 72-hour follow-up (CX Follow-up – Site Visit)

    /** The call 72 hours after the visit: disposition, feedback and what happens next. */
    public static function aftercareCall(): Action
    {
        $reached = fn (Get $get) => filled($get('disposition')) && AftercareCalls::callOutcome((string) $get('disposition')) === 'connected';

        return Action::make('visitAftercare')
            ->label('Record 72-hour follow-up call')
            ->icon(Heroicon::OutlinedPhoneArrowUpRight)
            ->color('success')
            ->visible(fn (Outage $record) => Auth::user()?->can('visitAftercare', $record))
            ->modalWidth('3xl')
            ->modalDescription(fn (Outage $record) => 'Call '.($record->ticket?->customer_name ?: 'the customer').' on '.($record->ticket?->calling_number ?: $record->ticket?->primary_number ?: '—')
                .': is the service still working well since the visit? Happy: close with the feedback. Same issue back: reopen the visit for a team. A different issue: raise a new ticket.')
            ->schema([
                Select::make('disposition')->label('Disposition')->required()->searchable()->native(false)->live()->options(AftercareCalls::options())
                    ->afterStateUpdated(fn (Set $set, ?string $state) => $set('action_required', AftercareCalls::suggestedAction($state))),
                Select::make('action_required')->label('Action required')->required()->live()
                    ->options(collect(AftercareCalls::ACTIONS)->except('callback')->all())->visible($reached),
                Grid::make(['default' => 1, 'sm' => 2])->schema([
                    Select::make('rating')->label('Satisfaction (1–5)')->options([5 => '5 — Very satisfied', 4 => '4', 3 => '3', 2 => '2', 1 => '1 — Very dissatisfied']),
                    Radio::make('service_working')->label('Internet service working')->inline()->boolean(),
                    Radio::make('installation_quality')->label('Installation quality')->inline()->options(AftercareCalls::QUALITY),
                    Radio::make('technician_conduct')->label('Technician conduct')->inline()->options(AftercareCalls::QUALITY),
                ])->visible($reached),
                Textarea::make('notes')->label('Complaint details / customer comments')->rows(3)->maxLength(5000)->required($reached),
                DateTimePicker::make('callback_at')->label('Call back at (optional)')->seconds(false)->minDate(fn () => now()->startOfMinute())
                    ->helperText('Not reached: offered again at this time, or a few hours from now.')
                    ->visible(fn (Get $get) => filled($get('disposition')) && ! $reached($get)),
                Select::make('subcategory_id')->label('New issue (category — subcategory)')->searchable()->required()
                    ->options(fn () => TicketForm::subcategoryOptions(null, verticalId: Vertical::idFor(Vertical::FTTH)))
                    ->visible(fn (Get $get) => $reached($get) && $get('action_required') === 'raise_ticket'),
                Textarea::make('new_ticket_narration')->label('Narration for the new ticket')->rows(3)->maxLength(5000)->required()
                    ->visible(fn (Get $get) => $reached($get) && $get('action_required') === 'raise_ticket'),
            ])
            ->action(fn (Outage $record, array $data) => OutageActions::run($record, fn () => app(OutageWorkflow::class)->recordVisitAftercare($record, Auth::user(), $data),
                'Follow-up call recorded'));
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
                Select::make('delay_reason_id')->label('Reason for delay')->options(fn (Outage $record) => self::delayReasons($record->vertical_id))
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
                    ->options(fn () => self::finalReasons($record->isSiteVisit() ? 'visit' : 'ticket', $record->current_queue, $record->vertical_id)),
                Select::make('delay_reason_id')->label('Reason for delay')->options(fn (Outage $record) => self::delayReasons($record->vertical_id))
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
                    // The photos of an installation / relocation come from the mobile app only: here a file is plain evidence.
                    ->options(fn (Outage $record) => VisitPhotos::appOnly($record)
                        ? ['evidence' => 'Other evidence of the work']
                        : ['geo_photo' => 'Geolocation photo', 'speed_test' => 'Speed test photo', 'evidence' => 'Other evidence of the work']),
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
        $options = DeskRoute::targetsFor($record);
        $approval = $record->isCxTicket() ? $record->ticket?->subcategory?->approval_desk : null;

        if ($approval && $approval !== $record->current_queue) {
            $options = [$approval->value => 'For approval: '.$approval->getLabel()] + $options;
        }

        return $options;
    }

    /** Active users who may work a desk, with their availability and current workload. */
    /** The technicians a visit can be given: all of them, or those allowed its kind (Shifting / Relocation). */
    public static function visitTechnicians(Outage $visit): array
    {
        return self::deskUsers(QueueType::VisitTechnician, EligibilityEvaluator::visitTechnicianPermission($visit), $visit);
    }

    public static function deskUsers(QueueType $desk, ?string $alsoPermission = null, ?Outage $outage = null): array
    {
        return User::permission(EligibilityEvaluator::deskPermissions($desk, $outage))
            ->when($alsoPermission, fn ($q, string $permission) => $q->whereIn('id', User::permission(EligibilityEvaluator::satisfiedBy($permission))->select('id')))
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

    /** Final reasons for a ticket or a visit, narrowed to the ones offered at the desk it is at. */
    /** Final reasons of this kind of ticket, offered at this desk — of the ticket's vertical (FTTH, 4G…) when it is given. */
    public static function finalReasons(string $kind, ?QueueType $desk = null, ?int $verticalId = null): array
    {
        return FinalReason::query()->active()->for($kind)->ofVertical($verticalId)->orderBy('sort_order')->orderBy('name')->get()
            ->filter(fn (FinalReason $reason) => $reason->isOfferedAt($desk))
            ->pluck('name', 'id')->all();
    }

    /** Reasons for delay — of the ticket's vertical (FTTH, 4G…) when it is given. */
    public static function delayReasons(?int $verticalId = null): array
    {
        return DelayReason::query()->active()->ofVertical($verticalId)->orderBy('sort_order')->pluck('name', 'id')->all();
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
