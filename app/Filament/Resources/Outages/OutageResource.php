<?php

namespace App\Filament\Resources\Outages;

use App\Enums\SlaState;
use App\Filament\Resources\Outages\Pages\CreateOutage;
use App\Filament\Resources\Outages\Pages\EditOutage;
use App\Filament\Resources\Outages\Pages\ListOutages;
use App\Filament\Resources\Outages\Pages\ViewOutage;
use App\Filament\Resources\Outages\RelationManagers\AssignmentHistoriesRelationManager;
use App\Filament\Resources\Outages\RelationManagers\AssignmentsRelationManager;
use App\Filament\Resources\Outages\RelationManagers\AuditLogsRelationManager;
use App\Filament\Resources\Outages\RelationManagers\CrfSignaturesRelationManager;
use App\Filament\Resources\Outages\RelationManagers\FlagEventsRelationManager;
use App\Filament\Resources\Outages\RelationManagers\FollowUpsRelationManager;
use App\Filament\Resources\Outages\RelationManagers\NotificationLogsRelationManager;
use App\Filament\Resources\Outages\RelationManagers\SlaEventsRelationManager;
use App\Filament\Resources\Outages\RelationManagers\StatusHistoriesRelationManager;
use App\Filament\Resources\Outages\RelationManagers\SupportRequestsRelationManager;
use App\Filament\Resources\Outages\RelationManagers\UpdatesRelationManager;
use App\Models\ChangeRequest;
use App\Models\Outage;
use BackedEnum;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\DatePicker;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use UnitEnum;

class OutageResource extends Resource
{
    protected static ?string $model = Outage::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBolt;

    protected static string|UnitEnum|null $navigationGroup = 'Operations';

    protected static ?int $navigationSort = 2;

    protected static ?string $recordTitleAttribute = 'ticket_number';

    public static function getGloballySearchableAttributes(): array
    {
        return ['ticket_number', 'title', 'location'];
    }

    public static function getNavigationBadge(): ?string
    {
        // Short cache keeps the sidebar cheap on every page load across all nodes.
        $count = Cache::remember('oms.nav.active-outages', 30, fn () => Outage::query()->active()->count());

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public static function form(Schema $schema): Schema
    {
        return OutageForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with([
                'majorOutageType:id,name,code', 'outageType:id,name', 'vertical:id,name', 'priority:id,name,color',
                'status:id,name,color', 'currentAssignee:id,name', 'olts:id,name', 'activeAssignments', 'openSupportRequest', 'changeRequest',
            ]))
            ->defaultSort('start_time', 'desc')
            ->poll('60s')
            // Whole row opens the outage page (saves an action column on small screens).
            ->recordUrl(fn (Outage $record) => static::getUrl('view', ['record' => $record]))
            ->extraAttributes(['class' => 'oms-mobile-no-actions'])
            ->columns([
                // Phone: ticket + stacked priority/status. Tablet adds priority (with customers), status, SLA. Desktop adds
                // location and owner; very wide screens add vertical, customers, elapsed and start. The rest is in the column toggle.
                TextColumn::make('ticket_number')->label('Ticket')->searchable(['ticket_number', 'title'])->sortable()->weight('bold')
                    // Non-breaking hyphen keeps the ticket on one line while the title wraps; copy uses the real value.
                    ->formatStateUsing(fn (string $state) => str_replace('-', "\u{2011}", $state))
                    ->copyable()->copyableState(fn (Outage $record) => $record->ticket_number)
                    ->description(fn (Outage $record) => str($record->title)->limit(60))->wrap(),
                // Phone only: priority and status badges stacked in one column.
                TextColumn::make('compact_state')->label('Priority / status')->hiddenFrom('sm')->badge()->listWithLineBreaks()->wrap()
                    ->state(fn (Outage $record) => array_values(array_filter([$record->priority?->name, $record->status?->name])))
                    ->color(fn (string $state, Outage $record) => $state === $record->priority?->name
                        ? ($record->priority?->color ?? 'gray')
                        : ($record->status?->color ?? 'gray')),
                TextColumn::make('location')->label('Location / OLT')->visibleFrom('xl')->wrap()
                    ->state(fn (Outage $record) => $record->olts->pluck('name')->implode(', ') ?: $record->location)->placeholder('—'),
                TextColumn::make('majorOutageType.name')->label('Major')->badge()->color('gray')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('outageType.name')->label('Type')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('vertical.name')->label('Vertical')->toggleable()->visibleFrom('2xl'),
                TextColumn::make('priority.name')->label('Priority')->badge()->color(fn (Outage $record) => $record->priority?->color ?? 'gray')->sortable()->visibleFrom('sm')
                    ->description(fn (Outage $record) => number_format($record->customers_affected).' customers'),
                TextColumn::make('customers_affected')->label('Customers')->numeric()->sortable()->toggleable()->visibleFrom('2xl'),
                TextColumn::make('status.name')->label('Status')->badge()->color(fn (Outage $record) => $record->status?->color ?? 'gray')->visibleFrom('sm')->wrap(),
                TextColumn::make('sla_state')->label('SLA')->badge()->visibleFrom('sm')->wrap()->state(fn (Outage $record) => $record->currentSlaState())
                    ->description(fn (Outage $record) => ! $record->is_closed && $record->sla_due_at ? 'due '.$record->sla_due_at->diffForHumans(short: true) : null),
                TextColumn::make('elapsed')->label('Elapsed')->state(fn (Outage $record) => $record->elapsedForHumans())->visibleFrom('2xl'),
                TextColumn::make('current_queue')->label('Queue')->badge()->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('currentAssignee.name')->label('Owner')->placeholder('Unassigned')->visibleFrom('xl')->wrap(),
                IconColumn::make('sms_sent')->label('SMS')->boolean()->toggleable(isToggledHiddenByDefault: true),
                IconColumn::make('credit_shared')->label('Credit')->boolean()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('start_time')->label('Start')->dateTime('d M H:i')->sortable()->toggleable()->visibleFrom('2xl'),
            ])
            ->filters([
                SelectFilter::make('major_outage_type_id')->label('Major type')->relationship('majorOutageType', 'name'),
                SelectFilter::make('outage_type_id')->label('Outage type')->relationship('outageType', 'name'),
                SelectFilter::make('vertical_id')->label('Vertical')->relationship('vertical', 'name'),
                SelectFilter::make('priority_id')->label('Priority')->relationship('priority', 'name')->multiple(),
                SelectFilter::make('status_id')->label('Status')->relationship('status', 'name')->multiple(),
                SelectFilter::make('sla_state')->label('SLA state')->options(SlaState::class)->multiple(),
                SelectFilter::make('team_id')->label('Team')->relationship('team', 'name'),
                SelectFilter::make('olts')->label('OLT')->relationship('olts', 'name')->multiple()->searchable()->preload(),
                SelectFilter::make('services')->label('Service')->relationship('services', 'name')->multiple()->preload(),
                Filter::make('start_time')->schema([DatePicker::make('from'), DatePicker::make('until')])
                    ->query(fn (Builder $query, array $data) => $query
                        ->when($data['from'] ?? null, fn ($q, $d) => $q->where('start_time', '>=', $d))
                        ->when($data['until'] ?? null, fn ($q, $d) => $q->where('start_time', '<=', $d.' 23:59:59')))
                    ->columns(2),
            ])
            ->filtersFormColumns(3)
            ->recordActions([
                // On phones the row itself opens the outage page, where every action is available.
                ActionGroup::make([...OutageActions::primary(), ...OutageActions::secondary()])
                    ->extraAttributes(['class' => 'oms-hide-mobile']),
            ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Grid::make(['default' => 1, 'lg' => 3])->schema([
                Section::make('Outage')->schema([
                    TextEntry::make('ticket_number')->label('Ticket')->weight('bold')->copyable(),
                    TextEntry::make('status.name')->label('Status')->badge()->color(fn (Outage $record) => $record->status?->color ?? 'gray'),
                    TextEntry::make('priority.name')->label('Priority')->badge()->color(fn (Outage $record) => $record->priority?->color ?? 'gray')
                        ->helperText(fn (Outage $record) => $record->priority_is_manual ? 'Manually overridden' : 'Calculated from impact rules'),
                    TextEntry::make('title')->columnSpanFull(),
                    TextEntry::make('majorOutageType.name')->label('Major type'),
                    TextEntry::make('outageType.name')->label('Outage type'),
                    TextEntry::make('vertical.name')->label('Vertical'),
                    TextEntry::make('customers_affected')->label('Customers affected')->numeric(),
                    TextEntry::make('location')->placeholder('—'),
                    TextEntry::make('services.name')->label('Affected services')->badge()->placeholder('—'),
                    TextEntry::make('topology')->label('OLT › FDT › Slot › Port')->state(fn (Outage $record) => $record->topologyPath() ?: null)->placeholder('—')->columnSpanFull(),
                    TextEntry::make('mobility')->label('4G impact')->state(fn (Outage $record) => "Sites down {$record->sites_down} · Cells down {$record->cells_down} · Power faults {$record->power_faults}")
                        ->visible(fn (Outage $record) => $record->is4g())->columnSpanFull(),
                    TextEntry::make('description')->columnSpanFull()->placeholder('—')->prose(),
                    TextEntry::make('latest_update')->label('Latest action / update')->columnSpanFull()->placeholder('Awaiting update')->prose(),
                ])->columns(['default' => 2, 'lg' => 3])->columnSpan(['default' => 'full', 'lg' => 2]),

                Grid::make(1)->schema([
                    Section::make('SLA')->schema([
                        TextEntry::make('sla_state')->label('State')->badge()->state(fn (Outage $record) => $record->currentSlaState()),
                        TextEntry::make('sla_due_at')->label('Due')->dateTime('d M Y H:i')
                            ->helperText(fn (Outage $record) => ! $record->is_closed && $record->sla_due_at ? $record->sla_due_at->diffForHumans() : null)->placeholder('—'),
                        TextEntry::make('slaPolicy.name')->label('Policy')->placeholder('Default'),
                        TextEntry::make('elapsed')->label('Elapsed')->state(fn (Outage $record) => $record->elapsedForHumans()),
                        IconEntry::make('sla_paused_at')->label('Paused')->state(fn (Outage $record) => $record->sla_paused_at !== null)->boolean()
                            ->visible(fn (Outage $record) => $record->sla_paused_at !== null),
                    ])->columns(2),
                    Section::make('FIFO ownership')->schema([
                        TextEntry::make('current_queue')->label('Queue')->badge()->placeholder('Not queued'),
                        TextEntry::make('currentAssignee.name')->label('Assigned to')->placeholder('Waiting for next eligible user'),
                        TextEntry::make('currentAssignment.status')->label('Assignment')->badge()->placeholder('—'),
                        TextEntry::make('currentAssignment.ack_due_at')->label('Acknowledge by')->dateTime('H:i')->placeholder('—'),
                        TextEntry::make('team.name')->label('Team')->placeholder('—'),
                        TextEntry::make('subTeam.name')->label('Sub-team')->placeholder('—'),
                        TextEntry::make('technician.name')->label('Technician')->placeholder('—'),
                        TextEntry::make('team_type')->label('Team type')->badge()->placeholder('—'),
                    ])->columns(2),
                    // Parallel work on the same ticket: drop cable → customer premises.
                    Section::make('Support technician')->icon(Heroicon::OutlinedUserPlus)->schema([
                        TextEntry::make('openSupportRequest.work_required')->label('Work required')->columnSpanFull(),
                        TextEntry::make('support_owner')->label('Assigned to')
                            ->state(fn (Outage $record) => $record->openSupportRequest?->queueEntry?->activeAssignment?->user?->name)
                            ->placeholder('Waiting for next support technician'),
                        TextEntry::make('openSupportRequest.requester.name')->label('Requested by')
                            ->helperText(fn (Outage $record) => $record->openSupportRequest?->created_at?->format('d M H:i')),
                    ])->columns(2)->visible(fn (Outage $record) => $record->openSupportRequest !== null),
                    Section::make('Planned maintenance (CRF)')->icon(Heroicon::OutlinedCalendarDays)->schema([
                        TextEntry::make('changeRequest.scheduled_start')->label('Window')->columnSpanFull()
                            ->state(fn (Outage $record) => $record->changeRequest?->scheduled_start->format('d M Y H:i').' – '
                                .$record->changeRequest?->scheduled_end->format($record->changeRequest->scheduled_end->isSameDay($record->changeRequest->scheduled_start) ? 'H:i' : 'd M Y H:i'))
                            ->helperText(fn (Outage $record) => ($record->changeRequest?->scheduled_start->isFuture() ? 'Starts '.$record->changeRequest->scheduled_start->diffForHumans() : null)
                                .($record->changeRequest?->reschedule_count ? ' · rescheduled '.$record->changeRequest->reschedule_count.'×' : '')),
                        TextEntry::make('changeRequest.status')->label('CRF')->badge()
                            ->formatStateUsing(fn (Outage $record) => $record->changeRequest?->statusLabel())
                            ->color(fn (string $state) => match ($state) {
                                ChangeRequest::APPROVED => 'success',
                                ChangeRequest::PENDING_SIGNOFF => 'warning',
                                ChangeRequest::CHANGES_REQUESTED, ChangeRequest::CANCELLED => 'danger',
                                default => 'gray',
                            }),
                        TextEntry::make('signoff')->label('Signed')
                            ->state(fn (Outage $record) => $record->changeRequest?->signatures->isNotEmpty()
                                ? $record->changeRequest->signedCount().' of '.$record->changeRequest->signatures->count()
                                : null)->placeholder('—'),
                        TextEntry::make('changeRequest.risk_tier')->label('Risk')->formatStateUsing(fn (Outage $record) => $record->changeRequest?->riskLabel()),
                        TextEntry::make('changeRequest.in_charge')->label('Lead')->placeholder('—'),
                        TextEntry::make('changeRequest.final_status')->label('Final status')->placeholder('—')
                            ->formatStateUsing(fn (?string $state) => ChangeRequest::FINAL_STATUSES[$state] ?? $state),
                        TextEntry::make('changeRequest.customers_notified_at')->label('Customers notified')->dateTime('d M H:i')->placeholder('—'),
                    ])->columns(2)->visible(fn (Outage $record) => $record->changeRequest !== null),
                    Section::make('Customer communication')->schema([
                        IconEntry::make('sms_sent')->label('SMS sent')->boolean(),
                        TextEntry::make('smsUpdatedBy.name')->label('by')->placeholder('—')
                            ->helperText(fn (Outage $record) => $record->sms_updated_at?->format('d M H:i')),
                        IconEntry::make('credit_shared')->label('Credit shared')->boolean(),
                        TextEntry::make('creditUpdatedBy.name')->label('by')->placeholder('—')
                            ->helperText(fn (Outage $record) => $record->credit_updated_at?->format('d M H:i')),
                    ])->columns(2),
                ])->columnSpan(['default' => 'full', 'lg' => 1]),
            ])->columnSpanFull(),

            Section::make('Lifecycle')->schema([
                TextEntry::make('start_time')->label('Start')->dateTime('d M Y H:i'),
                TextEntry::make('created_at')->label('Reported')->dateTime('d M Y H:i')
                    ->helperText(fn (Outage $record) => 'by '.($record->creator?->name ?? 'system')),
                TextEntry::make('noc_escalated_at')->label('Escalated to NOC')->dateTime('d M H:i')->placeholder('—'),
                TextEntry::make('noc_confirmed_at')->label('NOC confirmed')->dateTime('d M H:i')->placeholder('—')
                    ->helperText(fn (Outage $record) => $record->nocConfirmedBy?->name),
                TextEntry::make('infra_escalated_at')->label('Escalated to Infrastructure')->dateTime('d M H:i')->placeholder('—'),
                TextEntry::make('dispatched_at')->label('Dispatched')->dateTime('d M H:i')->placeholder('—'),
                TextEntry::make('restored_at')->label('Restoration reported')->dateTime('d M H:i')->placeholder('—'),
                TextEntry::make('restore_confirmed_at')->label('NOC restore confirmed')->dateTime('d M H:i')->placeholder('—'),
                TextEntry::make('end_time')->label('End time')->dateTime('d M Y H:i')->placeholder('—'),
                TextEntry::make('resolved_at')->label('Resolved')->dateTime('d M Y H:i')->placeholder('—')
                    ->helperText(fn (Outage $record) => $record->resolvedBy?->name),
                TextEntry::make('cx_followed_up_at')->label('CX followed up')->dateTime('d M H:i')->placeholder('—')
                    ->visible(fn (Outage $record) => $record->noc_rejected_at !== null),
                TextEntry::make('it_escalated_to_noc_at')->label('IT escalated to NOC')->dateTime('d M H:i')->placeholder('—')
                    ->helperText(fn (Outage $record) => $record->itEscalatedBy?->name)
                    ->visible(fn (Outage $record) => $record->it_escalated_to_noc_at !== null),
                TextEntry::make('noc_returned_to_it_at')->label('NOC returned to IT')->dateTime('d M H:i')->placeholder('—')
                    ->visible(fn (Outage $record) => $record->noc_returned_to_it_at !== null),
                TextEntry::make('it_confirmed_at')->label('IT confirmed')->dateTime('d M H:i')->placeholder('—')
                    ->helperText(fn (Outage $record) => $record->itConfirmedBy?->name)
                    ->visible(fn (Outage $record) => $record->it_confirmed_at !== null),
                TextEntry::make('rejection_reason')->label('NOC rejection reason')->placeholder('—')->visible(fn (Outage $record) => filled($record->rejection_reason))->columnSpanFull(),
                TextEntry::make('final_reason')->label('Final Reason / RFO')->placeholder('—')->columnSpanFull(),
            ])->columns(['default' => 2, 'md' => 3, 'xl' => 5])->collapsible()->columnSpanFull(),
        ]);
    }

    public static function getRelations(): array
    {
        return [
            UpdatesRelationManager::class,
            CrfSignaturesRelationManager::class,
            FollowUpsRelationManager::class,
            SupportRequestsRelationManager::class,
            StatusHistoriesRelationManager::class,
            AssignmentsRelationManager::class,
            AssignmentHistoriesRelationManager::class,
            FlagEventsRelationManager::class,
            SlaEventsRelationManager::class,
            NotificationLogsRelationManager::class,
            AuditLogsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListOutages::route('/'),
            'create' => CreateOutage::route('/create'),
            'view' => ViewOutage::route('/{record}'),
            'edit' => EditOutage::route('/{record}/edit'),
        ];
    }

    public static function canViewAny(): bool
    {
        return (bool) Auth::user()?->can('viewAny', Outage::class);
    }
}
