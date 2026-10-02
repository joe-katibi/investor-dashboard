<?php

namespace App\Filament\Resources\MobilityOutages;

use App\Enums\SlaState;
use App\Filament\Resources\MobilityOutages\Pages\CreateMobilityOutage;
use App\Filament\Resources\MobilityOutages\Pages\EditMobilityOutage;
use App\Filament\Resources\MobilityOutages\Pages\ListMobilityOutages;
use App\Filament\Resources\MobilityOutages\Pages\ViewMobilityOutage;
use App\Filament\Resources\MobilityOutages\RelationManagers\AttachmentsRelationManager;
use App\Filament\Resources\MobilityOutages\RelationManagers\ReopeningsRelationManager;
use App\Filament\Resources\MobilityOutages\RelationManagers\TimelineRelationManager;
use App\Filament\Resources\MobilityOutages\RelationManagers\VendorUpdatesRelationManager;
use App\Filament\Resources\Outages\OutageActions;
use App\Filament\Resources\Outages\RelationManagers\AssignmentHistoriesRelationManager;
use App\Filament\Resources\Outages\RelationManagers\AssignmentsRelationManager;
use App\Filament\Resources\Outages\RelationManagers\AuditLogsRelationManager;
use App\Filament\Resources\Outages\RelationManagers\NotificationLogsRelationManager;
use App\Filament\Resources\Outages\RelationManagers\SlaEventsRelationManager;
use App\Filament\Resources\Outages\RelationManagers\StatusHistoriesRelationManager;
use App\Models\Outage;
use App\Models\OutageStatus;
use App\Models\OutageType;
use App\Services\Maintenance\MaintenanceNotifier;
use BackedEnum;
use Carbon\CarbonInterface;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\DatePicker;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use UnitEnum;

/**
 * 4G NOC tickets: eNodeB, transmission and power outages. Same Outage model as the FTTH flows
 * (so SLA, notifications, audit and reports are shared) but its own screens, statuses and actions.
 */
class MobilityOutageResource extends Resource
{
    protected static ?string $model = Outage::class;

    protected static ?string $slug = '4g/outages';

    protected static ?string $modelLabel = '4G outage';

    protected static ?string $pluralModelLabel = '4G outages';

    protected static ?string $navigationLabel = 'Outages';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSignal;

    protected static string|UnitEnum|null $navigationGroup = '4G NOC';

    protected static ?int $navigationSort = 2;

    protected static ?string $recordTitleAttribute = 'ticket_number';

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->mobility();
    }

    public static function getGloballySearchableAttributes(): array
    {
        return ['ticket_number', 'title', 'location'];
    }

    public static function getNavigationBadge(): ?string
    {
        $count = Cache::remember('oms.nav.active-4g-outages', 30, fn () => Outage::query()->mobility()->active()->count());

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    /**
     * Filament asks the model policy by default (create → outage.create, update → the FTTH edit
     * rule). 4G tickets have their own rules, so every resource action is mapped here.
     */
    public static function getAuthorizationResponse(string|UnitEnum $action, ?Model $record = null): Response
    {
        $action = $action instanceof UnitEnum ? ($action instanceof BackedEnum ? (string) $action->value : $action->name) : $action;
        $user = Auth::user();

        $allowed = $user !== null && match ($action) {
            'viewAny' => $user->can('mobility.view'),
            'view' => $record ? $user->can('view', $record) : $user->can('mobility.view'),
            'create' => $user->can('createMobility', Outage::class),
            'update' => $record !== null && $user->can('mobilityEdit', $record),
            default => false, // tickets are never deleted from the UI
        };

        return $allowed ? Response::allow() : Response::deny();
    }

    public static function form(Schema $schema): Schema
    {
        return MobilityOutageForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with([
                'outageType:id,name', 'priority:id,name,color', 'status:id,name,color', 'currentAssignee:id,name', 'technician:id,name',
                'vendor', 'enodebs:id,site_id,name', 'transmissionNodes:id,code,name', 'activeAssignments',
            ]))
            ->defaultSort('start_time', 'desc')
            ->poll('60s')
            ->recordUrl(fn (Outage $record) => static::getUrl('view', ['record' => $record]))
            ->extraAttributes(['class' => 'oms-mobile-no-actions'])
            ->columns([
                TextColumn::make('ticket_number')->label('Ticket')->searchable(['ticket_number', 'title'])->sortable()->weight('bold')
                    ->formatStateUsing(fn (string $state) => str_replace('-', "\u{2011}", $state))
                    ->copyable()->copyableState(fn (Outage $record) => $record->ticket_number)
                    ->description(fn (Outage $record) => str($record->title)->limit(60))->wrap(),
                TextColumn::make('compact_state')->label('Severity / status')->hiddenFrom('sm')->badge()->listWithLineBreaks()->wrap()
                    ->state(fn (Outage $record) => array_values(array_filter([$record->priority?->name, $record->status?->name])))
                    ->color(fn (string $state, Outage $record) => $state === $record->priority?->name ? ($record->priority?->color ?? 'gray') : ($record->status?->color ?? 'gray')),
                TextColumn::make('fault_domain')->label('Category')->badge()->visibleFrom('md')
                    ->formatStateUsing(fn (?string $state) => OutageType::FAULT_DOMAINS[$state] ?? $state)
                    ->color(fn (?string $state) => match ($state) {
                        OutageType::DOMAIN_POWER => 'warning', OutageType::DOMAIN_TRANSMISSION => 'info', default => 'gray',
                    }),
                TextColumn::make('sites')->label('Site(s) / node(s)')->visibleFrom('lg')->wrap()
                    ->state(fn (Outage $record) => $record->sitesLabel(3) ?: ($record->nodesLabel(3) ?: $record->location))->placeholder('—'),
                TextColumn::make('priority.name')->label('Severity')->badge()->color(fn (Outage $record) => $record->priority?->color ?? 'gray')->sortable()->visibleFrom('sm')
                    ->description(fn (Outage $record) => $record->impactLabel() ?: null),
                TextColumn::make('status.name')->label('Status')->badge()->color(fn (Outage $record) => $record->status?->color ?? 'gray')->visibleFrom('sm')->wrap(),
                TextColumn::make('owner')->label('With')->visibleFrom('lg')->wrap()
                    ->state(fn (Outage $record) => self::owner($record))->placeholder('4G NOC')
                    ->description(fn (Outage $record) => $record->isVendorTicket() && $record->vendor_eta_at && ! $record->is_closed ? 'ETA '.$record->vendor_eta_at->format('d M H:i') : null),
                TextColumn::make('sla_state')->label('SLA')->badge()->visibleFrom('sm')->wrap()->state(fn (Outage $record) => $record->currentSlaState())
                    ->description(fn (Outage $record) => ! $record->is_closed && $record->sla_due_at ? 'due '.$record->sla_due_at->diffForHumans(short: true) : null),
                TextColumn::make('elapsed')->label('Duration')->state(fn (Outage $record) => $record->elapsedForHumans())->visibleFrom('xl'),
                TextColumn::make('start_time')->label('Start')->dateTime('d M H:i')->sortable()->toggleable()->visibleFrom('2xl'),
                TextColumn::make('outageType.name')->label('Type')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('location')->toggleable(isToggledHiddenByDefault: true)->wrap(),
                TextColumn::make('vendor.name')->label('Vendor')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('technician.name')->label('Engineer')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('outage_type_id')->label('Outage type')->options(fn () => OutageType::query()->mobility()->orderBy('sort_order')->pluck('name', 'id')),
                SelectFilter::make('priority_id')->label('Severity')->multiple()->options(fn () => MobilityOutageForm::priorityOptions()),
                SelectFilter::make('status_id')->label('Status')->multiple()->options(fn () => OutageStatus::query()->mobility()->orderBy('sort_order')->pluck('name', 'id')),
                SelectFilter::make('sla_state')->label('SLA state')->options(SlaState::class)->multiple(),
                SelectFilter::make('vendor_id')->label('Vendor')->relationship('vendor', 'name'),
                SelectFilter::make('technician_id')->label('Engineer / technician')->relationship('technician', 'name')->searchable()->preload(),
                SelectFilter::make('enodebs')->label('eNodeB site')->relationship('enodebs', 'name')->multiple()->searchable(),
                Filter::make('start_time')->schema([DatePicker::make('from'), DatePicker::make('until')])
                    ->query(fn (Builder $query, array $data) => $query
                        ->when($data['from'] ?? null, fn ($q, $d) => $q->where('start_time', '>=', $d))
                        ->when($data['until'] ?? null, fn ($q, $d) => $q->where('start_time', '<=', $d.' 23:59:59')))
                    ->columns(2),
            ])
            ->filtersFormColumns(3)
            ->recordActions([
                ActionGroup::make([
                    OutageActions::accept(), OutageActions::rejectAssignment(), ...MobilityActions::primary(),
                    OutageActions::addUpdate(), ...MobilityActions::secondary(),
                ])->extraAttributes(['class' => 'oms-hide-mobile']),
            ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Grid::make(['default' => 1, 'lg' => 3])->schema([
                Section::make('Outage')->schema([
                    TextEntry::make('ticket_number')->label('Ticket')->weight('bold')->copyable(),
                    TextEntry::make('status.name')->label('Status')->badge()->color(fn (Outage $record) => $record->status?->color ?? 'gray'),
                    TextEntry::make('priority.name')->label('Severity')->badge()->color(fn (Outage $record) => $record->priority?->color ?? 'gray')
                        ->helperText(fn (Outage $record) => $record->priority_is_manual ? 'Set manually' : 'From the 4G impact rules'),
                    TextEntry::make('title')->columnSpanFull(),
                    TextEntry::make('outageType.name')->label('Outage type'),
                    TextEntry::make('impact')->label('Affected')->state(fn (Outage $record) => $record->impactLabel() ?: null)->placeholder('—'),
                    TextEntry::make('location')->placeholder('—'),
                    TextEntry::make('sites')->label('eNodeB site(s)')->state(fn (Outage $record) => $record->enodebs->map->label()->all())
                        ->badge()->color('gray')->placeholder('—')->columnSpanFull(),
                    TextEntry::make('nodes')->label('Transmission node(s)')->state(fn (Outage $record) => $record->transmissionNodes->map->label()->all())
                        ->badge()->color('gray')->columnSpanFull()
                        ->visible(fn (Outage $record) => $record->transmissionNodes->isNotEmpty()),
                    TextEntry::make('transmission_link_id')->label('Transmission link ID')->placeholder('—')
                        ->visible(fn (Outage $record) => $record->fault_domain === OutageType::DOMAIN_TRANSMISSION),
                    TextEntry::make('transmission_type')->label('Transmission type')->placeholder('—')
                        ->formatStateUsing(fn (?string $state) => Outage::TRANSMISSION_TYPES[$state] ?? $state)
                        ->visible(fn (Outage $record) => $record->fault_domain === OutageType::DOMAIN_TRANSMISSION),
                    TextEntry::make('services.name')->label('Affected services')->badge()->placeholder('—'),
                    TextEntry::make('customers_affected')->label('Customers affected')->numeric()->visible(fn (Outage $record) => $record->customers_affected > 0),
                    TextEntry::make('description')->columnSpanFull()->placeholder('—')->prose(),
                    TextEntry::make('service_impact')->label('Service impact')->columnSpanFull()->prose()->visible(fn (Outage $record) => filled($record->service_impact)),
                    TextEntry::make('latest_update')->label('Latest update')->columnSpanFull()->placeholder('Awaiting update')->prose(),
                ])->columns(['default' => 2, 'lg' => 3])->columnSpan(['default' => 'full', 'lg' => 2]),

                Grid::make(1)->schema([
                    Section::make('SLA')->schema([
                        TextEntry::make('sla_state')->label('State')->badge()->state(fn (Outage $record) => $record->currentSlaState()),
                        TextEntry::make('sla_due_at')->label('Deadline')->dateTime('d M Y H:i')
                            ->helperText(fn (Outage $record) => ! $record->is_closed && $record->sla_due_at ? $record->sla_due_at->diffForHumans() : null)->placeholder('—'),
                        TextEntry::make('slaPolicy.name')->label('Policy')->placeholder('Default'),
                        TextEntry::make('elapsed')->label('Outage duration')->state(fn (Outage $record) => $record->elapsedForHumans()),
                        TextEntry::make('time_to_acknowledge')->label('Time to acknowledge')->state(fn (Outage $record) => self::span($record->created_at, $record->acknowledged_at))->placeholder('—'),
                        TextEntry::make('time_to_respond')->label('Time to respond')
                            ->state(fn (Outage $record) => self::span($record->created_at, $record->isVendorTicket() ? $record->vendor_acknowledged_at : $record->work_started_at))->placeholder('—'),
                        TextEntry::make('time_to_resolve')->label('Time to resolve')->state(fn (Outage $record) => self::span($record->start_time, $record->restored_at))->placeholder('—'),
                    ])->columns(2),

                    // Who has the ticket through the FIFO queues: the engineer / technician, then the NOC user verifying it.
                    Section::make('FIFO ownership')->icon(Heroicon::OutlinedQueueList)->schema([
                        TextEntry::make('current_queue')->label('Queue')->badge()->placeholder('Not queued'),
                        TextEntry::make('currentAssignee.name')->label('Assigned to')
                            ->placeholder(fn (Outage $record) => $record->current_queue ? 'Waiting for the next available person (FIFO)' : 'With the 4G NOC'),
                        TextEntry::make('currentAssignment.status')->label('Assignment')->badge()->placeholder('—'),
                        TextEntry::make('currentAssignment.ack_due_at')->label('Accept by')->dateTime('H:i')->placeholder('—'),
                        TextEntry::make('technician.name')->label('Engineer / technician')->placeholder('—')
                            ->visible(fn (Outage $record) => ! $record->isVendorTicket()),
                        TextEntry::make('work_started_at')->label('Investigation started')->dateTime('d M H:i')->placeholder('—')
                            ->visible(fn (Outage $record) => ! $record->isVendorTicket()),
                        TextEntry::make('team.name')->label('Team')->visible(fn (Outage $record) => $record->team_id !== null),
                    ])->columns(2)->visible(fn (Outage $record) => ! $record->isVendorTicket() || $record->current_queue !== null),

                    Section::make('Vendor')->icon(Heroicon::OutlinedBuildingOffice2)
                        ->description('External party — no system access. Updates are captured by the NOC.')
                        ->schema([
                            TextEntry::make('vendor.name')->label('Vendor')->weight('bold')
                                ->helperText(fn (Outage $record) => $record->vendor?->typeLabel()),
                            TextEntry::make('vendor.contact_person')->label('Contact')->placeholder('—')
                                ->helperText(fn (Outage $record) => $record->vendor?->phone),
                            TextEntry::make('vendor.email')->label('E-mail')->placeholder('No e-mail address — set it under Vendors')->columnSpanFull(),
                            TextEntry::make('vendor_notified_at')->label('Notification sent')->dateTime('d M H:i')->placeholder('Not sent'),
                            TextEntry::make('vendor_acknowledged_at')->label('Acknowledged')->dateTime('d M H:i')->placeholder('—'),
                            TextEntry::make('vendor_last_update_at')->label('Last update')->since()->placeholder('None yet'),
                            TextEntry::make('vendor_next_update_at')->label('Next update expected')->dateTime('d M H:i')->placeholder('—')
                                ->color(fn (Outage $record) => ! $record->is_closed && $record->vendor_next_update_at?->isPast() ? 'danger' : null),
                            TextEntry::make('vendor_eta_at')->label('Vendor ETA')->dateTime('d M H:i')->placeholder('—'),
                            TextEntry::make('vendor_escalation_level')->label('Escalation')->badge()
                                ->formatStateUsing(fn (int $state) => match ($state) {
                                    1 => 'NOC reminded', 2 => 'Escalated to management', default => 'None'
                                })
                                ->color(fn (int $state) => match ($state) {
                                    1 => 'warning', 2 => 'danger', default => 'gray'
                                }),
                        ])->columns(2)->visible(fn (Outage $record) => $record->isVendorTicket()),
                ])->columnSpan(['default' => 'full', 'lg' => 1]),
            ])->columnSpanFull(),

            Section::make('Resolution')->icon(Heroicon::OutlinedCheckBadge)->schema([
                TextEntry::make('root_cause')->label('Root cause')->placeholder('—')->prose()->columnSpan(2),
                TextEntry::make('resolution')->label('Resolution')->placeholder('—')->prose()->columnSpan(2),
                TextEntry::make('restored_at')->label('Restoration time')->dateTime('d M Y H:i')->placeholder('—'),
                TextEntry::make('restore_confirmed_at')->label('NOC verification')->dateTime('d M Y H:i')->placeholder('Pending')
                    ->helperText(fn (Outage $record) => $record->restoreConfirmedBy?->name),
                TextEntry::make('resolved_at')->label('Closed')->dateTime('d M Y H:i')->placeholder('—')
                    ->helperText(fn (Outage $record) => $record->resolvedBy?->name),
                TextEntry::make('reopen_count')->label('Reopened')->formatStateUsing(fn (int $state) => $state.'×')
                    ->helperText(fn (Outage $record) => $record->reopened_at ? 'last '.$record->reopened_at->format('d M H:i') : null)
                    ->visible(fn (Outage $record) => $record->reopen_count > 0),
            ])->columns(['default' => 2, 'lg' => 4])->columnSpanFull()
                ->visible(fn (Outage $record) => filled($record->root_cause) || $record->restored_at !== null || $record->is_closed || $record->reopen_count > 0),

            Section::make('Lifecycle')->schema([
                TextEntry::make('start_time')->label('Outage start')->dateTime('d M Y H:i'),
                TextEntry::make('detected_at')->label('Detected')->dateTime('d M Y H:i')->placeholder('—'),
                TextEntry::make('created_at')->label('Ticket created')->dateTime('d M Y H:i')
                    ->helperText(fn (Outage $record) => 'by '.($record->creator?->name ?? 'system')),
                TextEntry::make('acknowledged_at')->label('Acknowledged')->dateTime('d M H:i')->placeholder('—'),
                TextEntry::make('end_time')->label('Outage end')->dateTime('d M Y H:i')->placeholder('—'),
                TextEntry::make('escalated_at')->label('Escalated by NOC')->dateTime('d M H:i')
                    ->helperText(fn (Outage $record) => $record->escalatedBy?->name)
                    ->visible(fn (Outage $record) => $record->escalated_at !== null),
                TextEntry::make('cancelled_at')->label('Cancelled')->dateTime('d M H:i')
                    ->helperText(fn (Outage $record) => $record->cancelledBy?->name)
                    ->visible(fn (Outage $record) => $record->cancelled_at !== null),
                TextEntry::make('final_reason')->label('Cancellation reason')->columnSpanFull()
                    ->visible(fn (Outage $record) => $record->cancelled_at !== null),
            ])->columns(['default' => 2, 'md' => 3, 'xl' => 5])->collapsible()->columnSpanFull(),
        ]);
    }

    public static function getRelations(): array
    {
        return [
            TimelineRelationManager::class,
            VendorUpdatesRelationManager::class,
            AttachmentsRelationManager::class,
            ReopeningsRelationManager::class,
            StatusHistoriesRelationManager::class,
            AssignmentsRelationManager::class,
            AssignmentHistoriesRelationManager::class,
            SlaEventsRelationManager::class,
            NotificationLogsRelationManager::class,
            AuditLogsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListMobilityOutages::route('/'),
            'create' => CreateMobilityOutage::route('/create'),
            'view' => ViewMobilityOutage::route('/{record}'),
            'edit' => EditMobilityOutage::route('/{record}/edit'),
        ];
    }

    /** Who has the ticket: the vendor, the engineer, or the NOC user verifying it. */
    public static function owner(Outage $record): ?string
    {
        return $record->mobilityOwner();
    }

    private static function span(?CarbonInterface $from, ?CarbonInterface $to): ?string
    {
        if (! $from || ! $to) {
            return null;
        }

        $minutes = max(0, (int) $from->diffInMinutes($to));

        return $minutes < 1 ? 'under a minute' : MaintenanceNotifier::duration($minutes);
    }
}
