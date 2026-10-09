<?php

namespace App\Filament\Resources\CustomTickets;

use App\Enums\QueueType;
use App\Filament\Resources\CustomTickets\Pages\CreateCustomTicket;
use App\Filament\Resources\CustomTickets\Pages\ListCustomTickets;
use App\Filament\Resources\CustomTickets\Pages\ViewCustomTicket;
use App\Filament\Resources\Outages\OutageActions;
use App\Filament\Resources\Outages\RelationManagers\AssignmentHistoriesRelationManager;
use App\Filament\Resources\Outages\RelationManagers\AssignmentsRelationManager;
use App\Filament\Resources\Outages\RelationManagers\AuditLogsRelationManager;
use App\Filament\Resources\Outages\RelationManagers\NotificationLogsRelationManager;
use App\Filament\Resources\Outages\RelationManagers\SlaEventsRelationManager;
use App\Filament\Resources\Outages\RelationManagers\StatusHistoriesRelationManager;
use App\Filament\Resources\Outages\RelationManagers\UpdatesRelationManager;
use App\Models\CustomFlow;
use App\Models\Outage;
use App\Models\Priority;
use App\Models\User;
use App\Services\Sla\DeskSla;
use BackedEnum;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Components\ViewEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use UnitEnum;

/**
 * Tickets of the workflows built under Configuration → Custom Workflows: raise one, follow it
 * through its steps, and — for whoever holds it — complete the step or send it back.
 */
class CustomTicketResource extends Resource
{
    protected static ?string $model = Outage::class;

    protected static ?string $modelLabel = 'workflow ticket';

    protected static ?string $navigationLabel = 'Workflow Tickets';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static string|UnitEnum|null $navigationGroup = 'Operations';

    protected static ?int $navigationSort = 6;

    protected static ?string $slug = 'workflow-tickets';

    protected static ?string $recordTitleAttribute = 'ticket_number';

    /** Everything with "custom.view"; otherwise what the person raised, holds or held, or that waits for one of their teams. */
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()->customFlows();
        $user = Auth::user();

        return $user?->can('custom.view') ? $query : self::visibleTo($query, $user);
    }

    public static function visibleTo(Builder $query, ?User $user): Builder
    {
        if (! $user) {
            return $query->whereRaw('1 = 0');
        }

        $teams = $user->teams()->pluck('teams.id')->all();

        return $query->where(fn (Builder $q) => $q
            ->where($q->qualifyColumn('created_by'), $user->id)
            ->orWhereHas('assignments', fn (Builder $assignment) => $assignment->where('user_id', $user->id))
            ->orWhereHas('openQueueEntries', fn (Builder $entry) => $entry->whereIn('team_id', $teams ?: [0])));
    }

    /** Filament asks the model policy by default (the outage rules); custom tickets have their own, mapped here. */
    public static function getAuthorizationResponse(string|UnitEnum $action, ?Model $record = null): Response
    {
        $action = $action instanceof UnitEnum ? ($action instanceof BackedEnum ? (string) $action->value : $action->name) : $action;
        $user = Auth::user();

        $allowed = $user !== null && match ($action) {
            // The menu entry: for people who may see or raise, and for anyone a ticket concerns.
            'viewAny' => $user->can('custom.view') || $user->can('custom.raise') || self::visibleTo(Outage::query()->customFlows(), $user)->exists(),
            'view' => $record ? $user->can('view', $record) : $user->can('custom.view'),
            'create' => $user->can('raiseCustom', Outage::class) && self::flowsFor($user) !== [],
            default => false, // changed through their actions only, never deleted from the UI
        };

        return $allowed ? Response::allow() : Response::deny();
    }

    /** @return array<int, string> the workflows this person may raise a ticket in */
    public static function flowsFor(?User $user): array
    {
        return $user ? CustomFlow::query()->active()->with('steps')->orderBy('name')->get()
            ->filter(fn (CustomFlow $flow) => $flow->mayBeRaisedBy($user) && $flow->problems() === [])
            ->mapWithKeys(fn (CustomFlow $flow) => [$flow->id => $flow->name])->all() : [];
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->schema([
                Select::make('custom_flow_id')->label('Workflow')->required()->live()
                    ->options(fn () => self::flowsFor(Auth::user()))
                    ->helperText(fn (?string $state) => $state ? CustomFlow::query()->with('steps')->find($state)?->steps->pluck('name')->implode(' → ') : null),
                Select::make('priority_id')->label('Priority')->options(fn () => Priority::query()->active()->orderBy('queue_rank')->pluck('name', 'id'))
                    ->placeholder('Lowest'),
                TextInput::make('title')->label('Title')->required()->maxLength(250)->columnSpanFull(),
                Textarea::make('description')->label('Details')->rows(5)->maxLength(5000)->columnSpanFull(),
            ])->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['customTicket.flow', 'status', 'priority', 'currentAssignee:id,name', 'creator:id,name', 'currentQueueEntry.team', 'activeAssignments']))
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('ticket_number')->label('Ticket')->weight('bold')->searchable()->sortable()
                    ->formatStateUsing(fn (?string $state) => str_replace('-', "\u{2011}", (string) $state))
                    ->description(fn (Outage $record) => str($record->title)->limit(60)),
                TextColumn::make('customTicket.flow_name')->label('Workflow')->badge()->color('gray'),
                TextColumn::make('step')->label('Step')->wrap()
                    ->state(fn (Outage $record) => $record->is_closed ? '—' : $record->customTicket?->progressLabel())
                    ->description(fn (Outage $record) => ($due = $record->currentQueueEntry?->sla_due_at) && ! $record->is_closed ? 'step target: '.DeskSla::describe($due) : null),
                TextColumn::make('with')->label('With')->wrap()->state(fn (Outage $record) => $record->is_closed ? '—'
                    : ($record->currentAssignee?->name ?? 'Waiting — '.($record->currentQueueEntry?->team?->name ?? 'no team'))),
                TextColumn::make('status.name')->label('Status')->badge()->color(fn (Outage $record) => $record->status?->color ?? 'gray'),
                TextColumn::make('priority.name')->label('Priority')->badge()->color(fn (Outage $record) => $record->priority?->color ?? 'gray')->visibleFrom('lg'),
                TextColumn::make('sla_state')->label('SLA')->badge()->visibleFrom('md')->state(fn (Outage $record) => $record->currentSlaState())
                    ->description(fn (Outage $record) => ! $record->is_closed && $record->sla_due_at ? 'due '.$record->sla_due_at->diffForHumans(short: true) : null),
                TextColumn::make('creator.name')->label('Raised by')->visibleFrom('xl'),
                TextColumn::make('start_time')->label('Raised')->dateTime('d M H:i')->sortable()->visibleFrom('xl'),
            ])
            ->filters([
                SelectFilter::make('flow')->label('Workflow')->options(fn () => CustomFlow::query()->orderBy('name')->pluck('name', 'id'))
                    ->query(fn (Builder $query, array $data) => $query->when($data['value'] ?? null, fn (Builder $q, $id) => $q->whereHas('customTicket', fn (Builder $t) => $t->where('custom_flow_id', $id)))),
            ])
            ->recordActions([
                OutageActions::accept()->button()->size('sm'),
                ActionGroup::make([CustomTicketActions::complete(), CustomTicketActions::sendBack(), OutageActions::addUpdate()]),
            ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Ticket')->schema([
                TextEntry::make('ticket_number')->label('Ticket')->weight('bold')->copyable(),
                TextEntry::make('customTicket.flow_name')->label('Workflow')
                    ->helperText(fn (Outage $record) => 'version '.$record->customTicket?->flow_version.' — the steps as they were when it was raised'),
                TextEntry::make('status.name')->label('Status')->badge()->color(fn (Outage $record) => $record->status?->color ?? 'gray'),
                TextEntry::make('priority.name')->label('Priority')->badge()->color(fn (Outage $record) => $record->priority?->color ?? 'gray')->placeholder('—'),
                TextEntry::make('creator.name')->label('Raised by')->helperText(fn (Outage $record) => $record->start_time?->format('d M Y H:i')),
                TextEntry::make('step')->label('Step')->weight('bold')->state(fn (Outage $record) => $record->is_closed ? 'Finished' : $record->customTicket?->progressLabel()),
                TextEntry::make('with')->label('With')->state(fn (Outage $record) => $record->is_closed ? '—'
                    : ($record->currentAssignee?->name ?? 'Waiting for '.($record->currentQueueEntry?->team?->name ?? 'a team'))),
                TextEntry::make('sla_state')->label('SLA')->badge()->state(fn (Outage $record) => $record->currentSlaState())
                    ->helperText(fn (Outage $record) => $record->sla_due_at ? 'due '.$record->sla_due_at->format('d M Y H:i') : null),
                TextEntry::make('final_reason')->label('How it ended')->placeholder('—')->visible(fn (Outage $record) => $record->is_closed),
                TextEntry::make('description')->label('Details')->placeholder('—')->columnSpanFull()->prose(),
            ])->columns(4),

            Section::make('Steps')->schema([
                ViewEntry::make('steps')->hiddenLabel()->view('filament.custom-ticket-steps')->columnSpanFull(),
            ]),
        ]);
    }

    public static function getRelations(): array
    {
        return [
            UpdatesRelationManager::class,
            StatusHistoriesRelationManager::class,
            AssignmentsRelationManager::class,
            AssignmentHistoriesRelationManager::class,
            SlaEventsRelationManager::class,
            NotificationLogsRelationManager::class,
            AuditLogsRelationManager::class,
        ];
    }

    public static function getNavigationBadge(): ?string
    {
        $count = Outage::query()->customFlows()->active()->where('current_assignee_id', Auth::id())->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCustomTickets::route('/'),
            'create' => CreateCustomTicket::route('/create'),
            'view' => ViewCustomTicket::route('/{record}'),
        ];
    }

    /** The members of the team of the step the ticket is at (for a manager's "Assign"). */
    public static function stepTeamOptions(Outage $record): array
    {
        $teamId = $record->openQueueEntries()->where('queue_type', QueueType::CustomStep->value)->value('team_id');

        return $teamId ? User::query()->where('is_active', true)->whereHas('teams', fn (Builder $team) => $team->where('teams.id', $teamId)->orWhere('teams.parent_id', $teamId))
            ->orderBy('name')->pluck('name', 'id')->all() : [];
    }
}
