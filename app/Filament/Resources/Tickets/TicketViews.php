<?php

namespace App\Filament\Resources\Tickets;

use App\Enums\QueueType;
use App\Enums\SlaState;
use App\Filament\Resources\Outages\OutageActions;
use App\Filament\Resources\SiteVisits\SiteVisitResource;
use App\Filament\Support\OutageLink;
use App\Filament\Support\PhotoViewer;
use App\Models\Olt;
use App\Models\Outage;
use App\Models\OutageAttachment;
use App\Models\OutageStatus;
use App\Models\Region;
use App\Models\Ticket;
use App\Models\TicketFollowUp;
use App\Models\Vertical;
use App\Models\VisitType;
use App\Services\Maintenance\MaintenanceNotifier;
use App\Services\Tickets\BusinessClock;
use App\Support\AftercareCalls;
use App\Support\VisitPhotos;
use Carbon\CarbonInterface;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\DatePicker;
use Filament\Infolists\Components\ImageEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/** The list table and the ticket page, shared by customer tickets and site visits. */
final class TicketViews
{
    public const RELATIONS = [
        'ticket.subcategory', 'ticket.category', 'ticket.visitType', 'ticket.olt', 'ticket.region', 'ticket.delayReason',
        'priority:id,name,color', 'status:id,name,color', 'currentAssignee:id,name', 'technician:id,name', 'activeAssignments',
        'vertical:id,name,code',
    ];

    /** The latest 72-hour follow-up call of a visit, looked up once per page. */
    public static function aftercareCall(Outage $record): ?TicketFollowUp
    {
        self::$aftercareCalls ??= new \WeakMap;

        return (self::$aftercareCalls[$record] ??= [TicketFollowUp::query()->where('outage_id', $record->id)->where('kind', 'aftercare')->with('user:id,name')->latest('id')->first()])[0];
    }

    /** @var \WeakMap<Outage, array{0: ?TicketFollowUp}>|null per visit shown; gone with it */
    private static ?\WeakMap $aftercareCalls = null;

    /**
     * The site visit a customer ticket is waiting for (the latest one still open), with its desk and
     * holder. Looked up once per ticket shown and kept with it.
     */
    public static function openVisit(Outage $record): ?Outage
    {
        if (! $record->isCxTicket() || $record->is_closed) {
            return null;
        }

        self::$openVisits ??= new \WeakMap;

        if (! isset(self::$openVisits[$record])) {
            // Wrapped: "no open visit" is an answer too, and is not asked for again.
            self::$openVisits[$record] = [$record->siteVisits()->where('outages.is_closed', false)
                ->with(['status', 'currentAssignee:id,name'])->latest('outages.id')->first()];
        }

        return self::$openVisits[$record][0];
    }

    /** @var \WeakMap<Outage, array{0: ?Outage}>|null per ticket shown; gone with it */
    private static ?\WeakMap $openVisits = null;

    /** The photo of one kind on a visit (the latest), looked up once per page for all kinds. */
    private static function visitPhoto(Outage $record, string $kind): ?OutageAttachment
    {
        self::$visitPhotos ??= new \WeakMap;
        self::$visitPhotos[$record] ??= VisitPhotos::taken($record);

        return self::$visitPhotos[$record][$kind] ?? null;
    }

    /** @var \WeakMap<Outage, \Illuminate\Support\Collection<string, ?OutageAttachment>>|null */
    private static ?\WeakMap $visitPhotos = null;

    /** What the search box at the top of every page looks in, for customer tickets and site visits. */
    public const GLOBAL_SEARCH = [
        'ticket_number', 'title', 'ticket.account_number', 'ticket.customer_name',
        'ticket.calling_number', 'ticket.primary_number', 'ticket.external_reference',
    ];

    /** @return array<string, string> shown under a ticket in the search results */
    public static function globalSearchDetails(Outage $record): array
    {
        return array_filter([
            'Account' => $record->ticket?->account_number,
            'Customer' => $record->ticket?->customer_name,
            'Status' => $record->status?->name,
        ]);
    }

    /** @param  class-string|null  $resource  the resource whose page a row opens (4G Tickets has its own) */
    public static function table(Table $table, bool $visits, ?string $resource = null): Table
    {
        $resource ??= $visits ? SiteVisitResource::class : TicketResource::class;

        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(self::RELATIONS))
            ->defaultSort('start_time', 'desc')
            ->poll('60s')
            ->recordUrl(fn (Outage $record) => $resource::getUrl('view', ['record' => $record]))
            ->extraAttributes(['class' => 'oms-mobile-no-actions'])
            ->columns([
                TextColumn::make('ticket_number')->label('Ticket')->sortable()->weight('bold')
                    ->formatStateUsing(fn (string $state) => str_replace('-', "\u{2011}", $state))
                    ->copyable()->copyableState(fn (Outage $record) => $record->ticket_number)
                    ->searchable(query: fn (Builder $query, string $search) => $query->where(fn (Builder $q) => $q
                        ->where('ticket_number', 'like', "%{$search}%")
                        ->orWhereHas('ticket', fn (Builder $t) => $t->where('account_number', 'like', "%{$search}%")
                            ->orWhere('customer_name', 'like', "%{$search}%")->orWhere('calling_number', 'like', "%{$search}%")
                            ->orWhere('primary_number', 'like', "%{$search}%")->orWhere('external_reference', 'like', "%{$search}%"))))
                    ->description(fn (Outage $record) => collect([$record->ticket?->account_number, str((string) $record->ticket?->customer_name)->limit(28)->toString()])->filter()->implode(' · '))
                    ->wrap(),
                TextColumn::make('issue')->label('Issue')->wrap()->visibleFrom('sm')
                    ->state(fn (Outage $record) => $record->ticket?->subcategory?->name ?? $record->ticket?->visitType?->name)
                    ->description(fn (Outage $record) => $visits ? $record->ticket?->visitType?->name : $record->ticket?->category?->name),
                TextColumn::make('vertical.name')->label('Vertical')->badge()->visible(! $visits)->visibleFrom('md')->placeholder('—')
                    ->color(fn (Outage $record) => $record->isFourGTicket() ? 'info' : 'gray'),
                TextColumn::make('where')->label('Region / OLT')->visibleFrom('lg')->wrap()->placeholder('—')
                    ->state(fn (Outage $record) => collect([$record->ticket?->region?->name, $record->ticket?->olt?->name])->filter()->implode(' · ') ?: null),
                TextColumn::make('status.name')->label('Status')->badge()->wrap()->color(fn (Outage $record) => $record->status?->color ?? 'gray'),
                TextColumn::make('current_queue')->label('Desk')->badge()->visibleFrom('md')->placeholder('—')
                    ->description(fn (Outage $record) => $record->is_closed ? null : ($record->currentAssignee?->name ?? ($record->current_queue ? 'waiting (FIFO)' : null))),
                TextColumn::make('scheduled')->label('Visit')->visible($visits)->visibleFrom('xl')->placeholder('ASAP')
                    ->state(fn (Outage $record) => $record->ticket?->scheduled_at?->format('d M H:i'))
                    ->description(fn (Outage $record) => $record->technician?->name),
                TextColumn::make('sla_state')->label('SLA')->badge()->visibleFrom('sm')->wrap()->state(fn (Outage $record) => $record->currentSlaState())
                    ->description(fn (Outage $record) => ! $record->is_closed && $record->sla_due_at ? 'due '.$record->sla_due_at->diffForHumans(short: true) : null),
                TextColumn::make('start_time')->label('Reported')->dateTime('d M H:i')->sortable()->visibleFrom('xl'),
                TextColumn::make('ticket.delayReason.name')->label('Reason for delay')->placeholder('—')->wrap()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('final_reason')->label('Final reason')->limit(60)->wrap()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('ticket.external_reference')->label('Billing ref')->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status_id')->label('Status')->multiple()
                    ->options(fn () => OutageStatus::query()->ticketing()->orderBy('sort_order')->pluck('name', 'id')),
                SelectFilter::make('vertical_id')->label('Vertical')->visible(! $visits)->options(fn () => Vertical::query()->orderBy('id')->pluck('name', 'id')),
                SelectFilter::make('current_queue')->label('Desk')->options(QueueType::deskOptions()),
                SelectFilter::make('region')->label('Region')->options(fn () => Region::query()->orderBy('name')->pluck('name', 'id'))
                    ->query(fn (Builder $query, array $data) => $query->when($data['value'] ?? null, fn (Builder $q, $v) => $q->whereHas('ticket', fn (Builder $t) => $t->where('region_id', $v)))),
                SelectFilter::make('olt')->label('OLT')->multiple()->searchable()->options(fn () => Olt::query()->orderBy('name')->pluck('name', 'id'))
                    ->query(fn (Builder $query, array $data) => $query->when(array_filter(array_map('intval', (array) ($data['values'] ?? []))),
                        fn (Builder $q, array $ids) => $q->whereHas('ticket', fn (Builder $t) => $t->whereIn('olt_id', $ids)))),
                SelectFilter::make('visit_type')->label('Visit type')->visible($visits)->options(fn () => VisitType::query()->orderBy('sort_order')->pluck('name', 'id'))
                    ->query(fn (Builder $query, array $data) => $query->when($data['value'] ?? null, fn (Builder $q, $v) => $q->whereHas('ticket', fn (Builder $t) => $t->where('visit_type_id', $v)))),
                SelectFilter::make('ticket_type')->label('Ticket type')->options(Ticket::TYPES)
                    ->query(fn (Builder $query, array $data) => $query->when($data['value'] ?? null, fn (Builder $q, $v) => $q->whereHas('ticket', fn (Builder $t) => $t->where('ticket_type', $v)))),
                SelectFilter::make('sla_state')->label('SLA state')->options(SlaState::class)->multiple(),
                SelectFilter::make('technician_id')->label('Technician')->visible($visits)->relationship('technician', 'name')->searchable()->preload(),
                Filter::make('start_time')->schema([DatePicker::make('from'), DatePicker::make('until')])
                    ->query(fn (Builder $query, array $data) => $query
                        ->when($data['from'] ?? null, fn ($q, $d) => $q->where('start_time', '>=', $d))
                        ->when($data['until'] ?? null, fn ($q, $d) => $q->where('start_time', '<=', $d.' 23:59:59')))
                    ->columns(2),
            ])
            ->filtersFormColumns(3)
            ->recordActions([
                ActionGroup::make([
                    OutageActions::accept(), OutageActions::rejectAssignment(), ...TicketActions::primary(),
                    OutageActions::addUpdate(), ...TicketActions::secondary(), OutageActions::reassign(), OutageActions::returnToQueue(),
                ])->extraAttributes(['class' => 'oms-hide-mobile']),
            ]);
    }

    public static function infolist(Schema $schema, bool $visits): Schema
    {
        return $schema->components([
            Section::make('Affected by an ongoing outage')
                ->icon(Heroicon::OutlinedExclamationTriangle)->iconColor('danger')
                ->schema([
                    TextEntry::make('ticket.linkedOutage.ticket_number')->label('Outage')->weight('bold')->color('danger')
                        ->url(fn (Outage $record) => OutageLink::url($record->ticket?->linkedOutage)),
                    TextEntry::make('ticket.linkedOutage.title')->label('Title')->columnSpan(2),
                    TextEntry::make('ticket.linkedOutage.status.name')->label('Outage status')->badge()
                        ->helperText(fn (Outage $record) => $record->ticket?->linkedOutage?->noc_confirmed_at ? 'Confirmed by NOC' : 'Not yet confirmed by NOC'),
                ])->columns(4)->columnSpanFull()->compact()
                ->visible(fn (Outage $record) => $record->ticket?->linkedOutage !== null && ! $record->ticket->linkedOutage->is_closed),

            Grid::make(['default' => 1, 'lg' => 3])->schema([
                Grid::make(1)->schema([
                    Section::make($visits ? 'Site visit' : 'Ticket')->schema([
                        TextEntry::make('ticket_number')->label('Ticket')->weight('bold')->copyable(),
                        TextEntry::make('status.name')->label('Status')->badge()->color(fn (Outage $record) => $record->status?->color ?? 'gray'),
                        TextEntry::make('ticket.visitType.name')->label('Visit type')->badge()->color('info')->visible($visits),
                        TextEntry::make('vertical.name')->label('Vertical')->badge()->placeholder('—')->visible(! $visits),
                        TextEntry::make('ticket.category.name')->label('Category')->placeholder('—')->visible(! $visits),
                        TextEntry::make('ticket.subcategory.name')->label($visits ? 'Issue reported' : 'Subcategory')->placeholder('—'),
                        TextEntry::make('ticket.ticket_type')->label('Type')->formatStateUsing(fn (?string $state) => Ticket::TYPES[$state] ?? $state),
                        TextEntry::make('ticket.source')->label('Source')->placeholder('—')->formatStateUsing(fn (?string $state) => Ticket::SOURCES[$state] ?? $state),
                        TextEntry::make('priority.name')->label('Priority')->badge()->color(fn (Outage $record) => $record->priority?->color ?? 'gray')->placeholder('—'),
                        TextEntry::make('ticket.external_reference')->label('Billing system ticket')->placeholder('—')->copyable(),
                        TextEntry::make('ticket.parentOutage.ticket_number')->label('Raised from ticket')
                            ->url(fn (Outage $record) => OutageLink::url($record->ticket?->parentOutage))->color('primary')
                            ->visible(fn (Outage $record) => $record->ticket?->parent_outage_id !== null),
                        TextEntry::make('site_visits')->label('Site visit(s) raised')->badge()->color('info')
                            ->state(fn (Outage $record) => $record->siteVisits()->with('status')->get()->map(fn (Outage $visit) => "{$visit->ticket_number} — {$visit->status?->name}")->all())
                            ->visible(fn (Outage $record) => ! $visits && $record->siteVisits()->exists())->columnSpanFull(),
                        TextEntry::make('ticket.amount')->label('Amount')->numeric(2)->visible(fn (Outage $record) => $record->ticket?->amount !== null),
                        TextEntry::make('ticket.approval_state')->label('Approval')->badge()
                            ->color(fn (?string $state) => ['approved' => 'success', 'declined' => 'danger', 'pending' => 'warning'][$state] ?? 'gray')
                            ->formatStateUsing(fn (?string $state) => ucfirst((string) $state))
                            ->helperText(fn (Outage $record) => collect([$record->ticket?->approvalBy?->name, $record->ticket?->approval_at?->format('d M H:i'), $record->ticket?->approval_note])->filter()->implode(' · ') ?: null)
                            ->visible(fn (Outage $record) => $record->ticket?->approval_state !== null),
                        TextEntry::make('description')->label('Narration')->columnSpanFull()->placeholder('—')->prose(),
                        TextEntry::make('latest_update')->label('Latest update')->columnSpanFull()->placeholder('No update yet')->prose(),
                    ])->columns(['default' => 2, 'lg' => 3]),

                    Section::make('Customer')->icon(Heroicon::OutlinedUser)->schema([
                        TextEntry::make('ticket.account_number')->label('Account number')->weight('bold')->copyable()->placeholder('—'),
                        TextEntry::make('ticket.customer_name')->label('Customer')->placeholder('—')
                            ->helperText(fn (Outage $record) => $record->ticket?->customer?->isProvisional() ? 'Captured by hand — not on the customer file yet' : null),
                        TextEntry::make('ticket.bandwidth')->label('Bandwidth')->placeholder('—'),
                        TextEntry::make('ticket.calling_number')->label('Calling number')->copyable()->placeholder('—'),
                        TextEntry::make('ticket.primary_number')->label('Primary number')->copyable()->placeholder('—'),
                        TextEntry::make('alternatives')->label('Alternative numbers')->placeholder('—')
                            ->state(fn (Outage $record) => collect([$record->ticket?->alt_number_1, $record->ticket?->alt_number_2])->filter()->implode(' · ') ?: null),
                        TextEntry::make('ticket.address')->label('Address')->placeholder('—'),
                        TextEntry::make('ticket.landmark')->label('Landmark')->placeholder('—'),
                        TextEntry::make('ticket.road')->label('Road / street')->placeholder('—')->visible(fn (Outage $record) => filled($record->ticket?->road)),
                        TextEntry::make('ticket.relocated_at')->label('Relocated')->dateTime('d M Y H:i')->color('warning')
                            ->helperText('Moved to another OLT / region: the position and address above are the new ones.')
                            ->visible(fn (Outage $record) => $record->ticket?->relocated_at !== null),
                        TextEntry::make('ticket.region.name')->label('Region')->placeholder('—'),
                        TextEntry::make('position')->label('OLT › FDT › Slot › Port')->columnSpanFull()->placeholder('Not on file')
                            ->state(fn (Outage $record) => $record->ticket?->topologyPath() ?: null),
                    ])->columns(['default' => 2, 'lg' => 3]),
                ])->columnSpan(['default' => 'full', 'lg' => 2]),

                Grid::make(1)->schema([
                    Section::make('SLA')->schema([
                        TextEntry::make('sla_state')->label('State')->badge()->state(fn (Outage $record) => $record->currentSlaState()),
                        TextEntry::make('sla_due_at')->label('Deadline')->dateTime('d M Y H:i')->placeholder('—')
                            ->helperText(fn (Outage $record) => ! $record->is_closed && $record->sla_due_at ? $record->sla_due_at->diffForHumans() : null),
                        TextEntry::make('sla_used')->label('SLA time used')
                            ->state(fn (Outage $record) => self::duration(app(BusinessClock::class)->minutesBetween($record->start_time, $record->end_time ?? now())))
                            ->helperText(fn () => app(BusinessClock::class)->describe()),
                        TextEntry::make('ticket.delayReason.name')->label('Reason for delay')->placeholder('—')
                            ->helperText(fn (Outage $record) => $record->ticket?->delayReason ? ($record->ticket->delayReason->within_control ? 'Within our control' : 'Outside our control') : null),
                        TextEntry::make('ticket.follow_up_due_at')->label('Follow-up due since')->dateTime('d M H:i')->color('danger')
                            ->helperText('Pending past the follow-up time — ready for credit')
                            ->visible(fn (Outage $record) => $record->ticket?->follow_up_due_at !== null),
                        TextEntry::make('ticket.followed_up_at')->label('Last customer call')->dateTime('d M H:i')->placeholder('None')
                            ->helperText(fn (Outage $record) => collect([$record->ticket?->followedUpBy?->name, TicketFollowUp::OUTCOMES[$record->ticket?->follow_up_outcome] ?? null])->filter()->implode(' — ') ?: null),
                    ])->columns(2),

                    Section::make('Desk')->icon(Heroicon::OutlinedQueueList)->schema([
                        // A ticket that waits for its site visit shows where the visit is and who has it:
                        // the work is at that desk, not nowhere.
                        TextEntry::make('current_queue')->label('At desk')->badge()
                            ->state(fn (Outage $record) => $record->current_queue ?? self::openVisit($record)?->current_queue)
                            ->helperText(fn (Outage $record) => ! $record->current_queue && ($visit = self::openVisit($record))
                                ? "Site visit {$visit->ticket_number} ({$visit->status?->name}) — this ticket waits for it and closes with it" : null)
                            ->url(fn (Outage $record) => ! $record->current_queue && ($visit = self::openVisit($record)) ? OutageLink::url($visit) : null)
                            ->placeholder(fn (Outage $record) => $record->is_closed ? 'Closed' : 'Waiting for the site visit'),
                        TextEntry::make('currentAssignee.name')->label('With')
                            ->state(fn (Outage $record) => $record->currentAssignee?->name ?? ($record->current_queue ? null : self::openVisit($record)?->currentAssignee?->name))
                            ->placeholder(fn (Outage $record) => $record->current_queue || self::openVisit($record)?->current_queue ? 'Waiting for the next available person (FIFO)' : '—'),
                        TextEntry::make('currentAssignment.status')->label('Assignment')->badge()->placeholder('—'),
                        TextEntry::make('currentAssignment.ack_due_at')->label('Accept by')->dateTime('H:i')->placeholder('—'),
                        TextEntry::make('ticket.escalated_to')->label('Last escalated to')->badge()->placeholder('—')
                            ->helperText(fn (Outage $record) => $record->ticket?->escalated_at?->format('d M H:i')),
                        TextEntry::make('ticket.returned_at')->label('Last returned')->dateTime('d M H:i')->placeholder('—'),
                        TextEntry::make('ticket.interventionBy.name')->label('Team leader intervention')->color('danger')
                            ->helperText(fn (Outage $record) => $record->ticket?->intervention_ended_at
                                ? 'ended, '.self::duration(intdiv((int) $record->ticket->intervention_seconds, 60))
                                : 'running since '.$record->ticket?->intervention_started_at?->format('H:i'))
                            ->visible(fn (Outage $record) => $record->ticket?->intervention_by !== null)->columnSpanFull(),
                    ])->columns(2),

                    Section::make('Visit')->icon(Heroicon::OutlinedMapPin)->visible($visits)->schema([
                        TextEntry::make('ticket.scheduled_at')->label('Booked for')->dateTime('D d M Y H:i')->placeholder('As soon as possible')
                            ->helperText(fn (Outage $record) => $record->ticket?->reschedule_count ? "Rescheduled {$record->ticket->reschedule_count}×" : $record->ticket?->preferred_time),
                        TextEntry::make('technician.name')->label('Technician')->placeholder('Not assigned')
                            ->helperText(fn (Outage $record) => $record->team?->name),
                        TextEntry::make('ticket.customer_sms_at')->label('Customer texted')->dateTime('d M H:i')->placeholder('Not yet'),
                        TextEntry::make('ticket.en_route_at')->label('En route')->dateTime('d M H:i')->placeholder('—'),
                        TextEntry::make('ticket.arrived_at')->label('Arrived at premise')->dateTime('d M H:i')->placeholder('—'),
                        TextEntry::make('on_premise')->label('On premise')->placeholder('—')
                            ->state(fn (Outage $record) => ($seconds = $record->ticket?->onPremiseSeconds()) !== null ? self::duration(intdiv($seconds, 60)) : null)
                            ->helperText(fn (Outage $record) => $record->ticket?->paused_at ? 'Paused: '.$record->ticket->pause_reason : null)
                            ->color(fn (Outage $record) => $record->ticket?->paused_at ? 'warning' : null),
                    ])->columns(2),
                ])->columnSpan(['default' => 'full', 'lg' => 1]),
            ])->columnSpanFull(),

            // Installation / relocation: the nine photos the visit is completed with, taken in the mobile app.
            Section::make('Installation photos')->icon(Heroicon::OutlinedCamera)
                ->description(fn (Outage $record) => VisitPhotos::progress($record).' taken. They are taken in the mobile app, with the camera — there is no upload for them here. Click a photo to view it.')
                ->schema(fn (): array => collect(VisitPhotos::PHOTOS)->except('geo_photo')->keys()->map(fn (string $kind) => ImageEntry::make("visit_photo_{$kind}")
                    ->label(fn (Outage $record) => VisitPhotos::of($record)[$kind]['label'] ?? VisitPhotos::short($kind))
                    ->state(fn (Outage $record) => self::visitPhoto($record, $kind)?->url())
                    ->extraImgAttributes(fn (Outage $record) => PhotoViewer::attachmentImageAttributes(self::visitPhoto($record, $kind)))
                    ->imageHeight(170)
                    ->helperText(fn (Outage $record) => ($photo = self::visitPhoto($record, $kind)) ? collect([
                        $photo->coordinates(), $photo->place, ($photo->taken_at ?? $photo->created_at)?->format('d M Y H:i'), $photo->user?->name,
                    ])->filter()->implode(' · ') : null)
                    ->placeholder('Not taken yet'))->all())
                ->columns(['default' => 1, 'sm' => 2, 'lg' => 3])->columnSpanFull()
                ->visible(fn (Outage $record) => $visits && VisitPhotos::appOnly($record)),

            // The call 72 hours after the visit (CX Follow-up – Site Visit).
            Section::make('72-hour follow-up')->icon(Heroicon::OutlinedPhoneArrowUpRight)->schema([
                TextEntry::make('aftercare_state')->label('Status')->badge()
                    ->state(fn (Outage $record) => match (true) {
                        $record->ticket?->aftercare_done_at !== null => 'Done — '.(AftercareCalls::FEEDBACK[$record->ticket->aftercare_result] ?? ['reopened' => 'Visit reopened', 'new_ticket' => 'New ticket raised', 'unreachable' => 'Customer not reached'][$record->ticket->aftercare_result] ?? $record->ticket->aftercare_result),
                        default => 'Call due since '.$record->ticket?->aftercare_due_at?->format('d M Y H:i'),
                    })
                    ->color(fn (Outage $record) => $record->ticket?->aftercare_done_at ? 'success' : 'warning')
                    ->helperText(fn (Outage $record) => $record->ticket?->aftercare_attempts ? $record->ticket->aftercare_attempts.' call(s)' : null),
                TextEntry::make('aftercare_disposition')->label('Last call')->placeholder('Not called yet')
                    ->state(fn (Outage $record) => ($call = self::aftercareCall($record)) ? AftercareCalls::label($call->disposition).' — '.(AftercareCalls::ACTIONS[$call->action_required] ?? '') : null)
                    ->helperText(fn (Outage $record) => ($call = self::aftercareCall($record)) ? $call->called_at?->format('d M Y H:i').' · '.$call->user?->name : null),
                TextEntry::make('aftercare_scores')->label('Customer feedback')->placeholder('—')
                    ->state(fn (Outage $record) => ($call = self::aftercareCall($record)) ? collect([
                        $call->rating ? "Rating {$call->rating}/5" : null,
                        $call->service_working === null ? null : 'Service '.($call->service_working ? 'working' : 'NOT working'),
                        $call->installation_quality ? 'Installation '.strtolower(AftercareCalls::QUALITY[$call->installation_quality]) : null,
                        $call->technician_conduct ? 'Technician '.strtolower(AftercareCalls::QUALITY[$call->technician_conduct]) : null,
                    ])->filter()->implode(' · ') ?: null : null),
                TextEntry::make('aftercare_notes')->label('Comments')->placeholder('—')->columnSpanFull()
                    ->state(fn (Outage $record) => self::aftercareCall($record)?->notes),
            ])->columns(3)->columnSpanFull()
                ->visible(fn (Outage $record) => $visits && $record->ticket?->aftercare_due_at !== null),

            Section::make('Outcome')->icon(Heroicon::OutlinedCheckBadge)->schema([
                TextEntry::make('ticket.finalReason.name')->label('Final reason')->placeholder('—')->weight('bold'),
                TextEntry::make('ticket.resolution_notes')->label('Work done / resolution')->placeholder('—')->prose()->columnSpan(2),
                TextEntry::make('ticket.fat_number')->label('FAT number')->placeholder('—')->visible($visits),
                TextEntry::make('ticket.onu_status')->label('ONU')->badge()->placeholder('—')->visible($visits)
                    ->formatStateUsing(fn (?string $state) => VisitPhotos::ONU_STATUSES[$state] ?? $state)
                    ->color(fn (?string $state) => $state === VisitPhotos::ONU_REPLACED ? 'warning' : 'gray'),
                TextEntry::make('ticket.provisioned_at')->label('Provisioned by the NOC')->dateTime('d M Y H:i')
                    ->placeholder(fn (Outage $record) => $record->ticket?->provision_requested_at ? 'Requested '.$record->ticket->provision_requested_at->format('d M H:i').' — waiting for the NOC' : 'Not yet — the technician sends it to the NOC before the speed tests')
                    ->helperText(fn (Outage $record) => $record->ticket?->provisionedBy?->name)
                    ->visible(fn (Outage $record) => $visits && VisitPhotos::needsProvisioning($record)),
                TextEntry::make('materials')->label('Materials')->placeholder('—')->visible($visits)->columnSpan(2)
                    ->state(fn (Outage $record) => collect([
                        'ONU' => $record->ticket?->onu_type, 'Drop cable' => $record->ticket?->drop_cable_m ? $record->ticket->drop_cable_m.' m' : null,
                        'Ethernet' => $record->ticket?->ethernet_m ? $record->ticket->ethernet_m.' m' : null, 'Patch cord' => $record->ticket?->patch_cord,
                        'ATB' => $record->ticket?->atb, 'Extender' => $record->ticket?->extender,
                    ])->filter()->map(fn ($value, $label) => "{$label}: {$value}")->implode(' · ') ?: null),
                TextEntry::make('ticket.completed_at')->label('Completed by technician')->dateTime('d M Y H:i')->placeholder('—')->visible($visits)
                    ->helperText(fn (Outage $record) => $record->ticket?->completedBy?->name),
                TextEntry::make('ticket.verified_at')->label('Verified by dispatcher')->dateTime('d M Y H:i')->placeholder('—')->visible($visits)
                    ->helperText(fn (Outage $record) => $record->ticket?->verifiedBy?->name),
                TextEntry::make('ticket.confirmed_at')->label('Confirmed with customer')->dateTime('d M Y H:i')->placeholder('—')->visible($visits)
                    ->helperText(fn (Outage $record) => collect([$record->ticket?->confirmedBy?->name, $record->ticket?->customer_confirmed === false ? 'customer not reached' : null])->filter()->implode(' — ') ?: null),
                TextEntry::make('resolved_at')->label('Closed')->dateTime('d M Y H:i')->placeholder('—')
                    ->helperText(fn (Outage $record) => $record->resolvedBy?->name),
                TextEntry::make('reopen_count')->label('Reopened')->formatStateUsing(fn (int $state) => $state.'×')
                    ->helperText(fn (Outage $record) => $record->reopened_at ? 'last '.$record->reopened_at->format('d M H:i') : null)
                    ->visible(fn (Outage $record) => $record->reopen_count > 0),
            ])->columns(['default' => 2, 'lg' => 3])->columnSpanFull()
                ->visible(fn (Outage $record) => $record->is_closed || $record->ticket?->final_reason_id !== null || $record->ticket?->completed_at !== null || $record->reopen_count > 0),

            Section::make('Timeline')->schema([
                TextEntry::make('start_time')->label('Reported')->dateTime('d M Y H:i')
                    ->helperText(fn (Outage $record) => 'by '.($record->creator?->name ?? 'system')),
                TextEntry::make('ticket.first_assigned_at')->label('First assigned')->dateTime('d M H:i')->placeholder('—')
                    ->helperText(fn (Outage $record) => self::span($record->start_time, $record->ticket?->first_assigned_at)),
                TextEntry::make('ticket.assigned_at')->label('Assigned (current desk)')->dateTime('d M H:i')->placeholder('—'),
                TextEntry::make('ticket.accepted_at')->label('Accepted')->dateTime('d M H:i')->placeholder('—'),
                TextEntry::make('ticket.desk_since')->label('At this desk since')->dateTime('d M H:i')->placeholder('—'),
                TextEntry::make('ticket.escalation_count')->label('Escalations')->placeholder('0'),
                TextEntry::make('end_time')->label('Ended')->dateTime('d M Y H:i')->placeholder('—'),
                TextEntry::make('updated_at')->label('Last updated')->dateTime('d M Y H:i'),
            ])->columns(['default' => 2, 'md' => 4])->collapsible()->columnSpanFull(),
        ]);
    }

    public static function duration(int $minutes): string
    {
        return $minutes < 1 ? 'under a minute' : MaintenanceNotifier::duration($minutes);
    }

    private static function span(?CarbonInterface $from, ?CarbonInterface $to): ?string
    {
        return $from && $to ? 'after '.self::duration(max(0, (int) $from->diffInMinutes($to))) : null;
    }
}
