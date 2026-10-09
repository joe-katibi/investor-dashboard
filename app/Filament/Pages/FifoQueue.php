<?php

namespace App\Filament\Pages;

use App\Enums\QueueEntryStatus;
use App\Enums\QueueType;
use App\Enums\SlaState;
use App\Filament\Concerns\RefreshesOnOmsEvents;
use App\Filament\Resources\Outages\OutageActions;
use App\Filament\Support\OutageLink;
use App\Models\OutageQueueEntry;
use App\Models\OutageType;
use App\Models\User;
use App\Models\Vertical;
use App\Services\Fifo\FifoAssignmentEngine;
use App\Services\Sla\DeskSla;
use App\Support\Settings;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Url;
use UnitEnum;

/**
 * Operational FIFO queue screen (spec §27.6): position, queue age, priority, impact, team,
 * sub-team, SLA state and the assigned / next eligible user, with "why" explanations.
 */
class FifoQueue extends Page implements HasTable
{
    use InteractsWithTable, RefreshesOnOmsEvents;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedQueueList;

    protected static string|UnitEnum|null $navigationGroup = 'Operations';

    protected static ?int $navigationSort = 3;

    protected static ?string $navigationLabel = 'FIFO Queues';

    protected static ?string $title = 'FIFO Queues';

    protected string $view = 'filament.pages.fifo-queue';

    #[Url]
    public string $queue = 'noc_review';

    /** @var array<int, array<string, mixed>>|null */
    protected ?array $snapshotCache = null;

    public static function canAccess(): bool
    {
        return (bool) Auth::user()?->can('queue.view');
    }

    public function updatedQueue(): void
    {
        $this->snapshotCache = null;
        $this->resetTable();
    }

    /**
     * Queues this user may look at: the 4G queues need "View 4G outages", the others "View outages"
     * (the same split as the tickets in them).
     *
     * @return list<QueueType>
     */
    public function visibleQueues(): array
    {
        $user = Auth::user();

        return array_values(array_filter(QueueType::cases(), fn (QueueType $type) => (bool) $user?->can(match (true) {
            $type->isTicketing() => 'ticket.view',
            $type->isMobility() => 'mobility.view',
            default => 'outage.view',
        })));
    }

    public function queueType(): QueueType
    {
        $visible = $this->visibleQueues();
        $selected = QueueType::tryFrom($this->queue);

        return $selected && in_array($selected, $visible, true) ? $selected : ($visible[0] ?? QueueType::NocReview);
    }

    /** Snapshot keyed by entry id: position + next eligible user (computed once per render). */
    public function snapshot(): array
    {
        return $this->snapshotCache ??= app(FifoAssignmentEngine::class)
            ->snapshot($this->queueType())
            ->keyBy(fn (array $row) => $row['entry']->id)
            ->map(fn (array $row) => ['position' => $row['position'], 'next' => $row['next_eligible'], 'assigned' => $row['assigned_to']])
            ->all();
    }

    /**
     * Second line under the ticket. On phones the position, priority and assignee columns are
     * hidden, so this line carries them.
     */
    public function ticketLine(OutageQueueEntry $record): string
    {
        $row = $this->snapshot()[$record->id] ?? [];
        $owner = $record->status === QueueEntryStatus::Pending ? ($row['next'] ?? null) : ($row['assigned'] ?? null);

        return collect([
            ($row['position'] ?? null) ? '#'.$row['position'] : null,
            $record->priority?->name,
            str($record->outage?->title)->limit(40)->toString(),
            $owner ? '→ '.$owner : null,
        ])->filter()->implode(' · ');
    }

    public function getQueueCounts(): array
    {
        return OutageQueueEntry::query()->open()->toBase()
            ->selectRaw('queue_type, count(*) as aggregate')->groupBy('queue_type')->pluck('aggregate', 'queue_type')->all();
    }

    public function getSubheading(): ?string
    {
        $settings = app(Settings::class);

        return ! $settings->bool('fifo.enabled')
            ? 'FIFO is disabled — tickets wait for manual assignment.'
            : ($settings->get('fifo.assignment_mode') === 'auto'
                ? 'Automatic priority-aware FIFO: '.($settings->bool('fifo.priority_aware') ? 'Critical → High → Medium → Low, oldest first within each priority.' : 'strict oldest-first.')
                : 'Manual mode — managers assign from this screen.');
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('runFifo')
                ->label('Run FIFO now')
                ->icon(Heroicon::OutlinedPlay)
                ->visible(fn () => Auth::user()?->can('queue.override'))
                ->action(function (): void {
                    $count = app(FifoAssignmentEngine::class)->process();
                    $this->snapshotCache = null;
                    Notification::make()->title("{$count} ticket(s) assigned")->success()->send();
                }),
        ];
    }

    public function table(Table $table): Table
    {
        $priorityAware = app(Settings::class)->bool('fifo.priority_aware');

        return $table
            ->query(fn (): Builder => OutageQueueEntry::query()
                ->open()
                ->where('queue_type', $this->queueType()->value)
                ->with([
                    'priority:id,name,color', 'team:id,name', 'subTeam:id,name', 'department:id,name',
                    'outage' => fn ($q) => $q->with(['status:id,name,color', 'outageType:id,name', 'vertical:id,name']),
                ])
                ->fifoOrder($priorityAware))
            ->paginated([25, 50, 100])
            ->poll('30s')
            ->recordUrl(fn (OutageQueueEntry $record) => OutageLink::url($record->outage ?? $record->outage_id))
            ->emptyStateHeading('Queue is empty')
            ->emptyStateIcon('heroicon-o-check-circle')
            ->columns([
                TextColumn::make('position')->label('Pos.')->visibleFrom('sm')
                    ->state(fn (OutageQueueEntry $record) => $this->snapshot()[$record->id]['position'] ?? '—')
                    ->weight('bold'),
                TextColumn::make('outage.ticket_number')->label('Ticket')->searchable()->weight('bold')->formatStateUsing(fn (?string $state) => str_replace('-', "\u{2011}", (string) $state))
                    ->description(fn (OutageQueueEntry $record) => $this->ticketLine($record))->wrap(),
                TextColumn::make('priority.name')->label('Priority')->badge()->color(fn (OutageQueueEntry $record) => $record->priority?->color ?? 'gray')->visibleFrom('sm'),
                TextColumn::make('queued_at')->label('Queue age')->visibleFrom('sm')->since()->tooltip(fn (OutageQueueEntry $record) => $record->queued_at->format('Y-m-d H:i:s.v'))
                    // The desk's own SLA target for this stay, when it has one.
                    ->description(fn (OutageQueueEntry $record) => $record->sla_due_at ? 'target '.$record->sla_minutes.' min · '.DeskSla::describe($record->sla_due_at) : null),
                TextColumn::make('outage.customers_affected')->label('Customers')->numeric()->visibleFrom('xl'),
                TextColumn::make('outage.outageType.name')->label('Type')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('outage.vertical.name')->label('Vertical')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('team.name')->label('Team')->toggleable()->visibleFrom('2xl')->placeholder($this->queueType()->getLabel()),
                TextColumn::make('subTeam.name')->label('Sub-team')->placeholder('—')->toggleable()->visibleFrom('2xl'),
                TextColumn::make('outage.sla_state')->label('SLA')->badge()->visibleFrom('xl')->state(fn (OutageQueueEntry $record) => $record->outage?->currentSlaState())
                    ->formatStateUsing(fn ($state) => $state instanceof SlaState ? $state->getLabel() : $state)
                    ->color(fn ($state) => $state instanceof SlaState ? $state->getColor() : 'gray'),
                TextColumn::make('status')->label('Queue status')->badge()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('assignee')->label('Assigned / next eligible')->wrap()->visibleFrom('sm')
                    ->state(fn (OutageQueueEntry $record) => $record->status === QueueEntryStatus::Pending
                        ? ($this->snapshot()[$record->id]['next'] ?? 'Beyond preview')
                        : ($this->snapshot()[$record->id]['assigned'] ?? '—'))
                    ->description(fn (OutageQueueEntry $record) => $record->status === QueueEntryStatus::Pending ? 'next eligible' : ($record->attempts ? "{$record->attempts} reject/timeout" : null))
                    ->color(fn (OutageQueueEntry $record) => $record->status === QueueEntryStatus::Pending ? 'gray' : null),
            ])
            ->filters([
                SelectFilter::make('department_id')->label('Department')->relationship('department', 'name'),
                SelectFilter::make('team_id')->label('Team')->relationship('team', 'name'),
                SelectFilter::make('sub_team_id')->label('Sub-team')->relationship('subTeam', 'name'),
                SelectFilter::make('priority_id')->label('Priority')->relationship('priority', 'name'),
                SelectFilter::make('outage_type')->label('Outage type')
                    ->options(fn () => OutageType::query()->orderBy('sort_order')->pluck('name', 'id'))
                    ->query(fn (Builder $query, array $data) => $query->when($data['value'] ?? null, fn ($q, $v) => $q->whereHas('outage', fn ($o) => $o->where('outage_type_id', $v)))),
                SelectFilter::make('vertical')->label('Vertical')
                    ->options(fn () => Vertical::query()->orderBy('name')->pluck('name', 'id'))
                    ->query(fn (Builder $query, array $data) => $query->when($data['value'] ?? null, fn ($q, $v) => $q->whereHas('outage', fn ($o) => $o->where('vertical_id', $v)))),
            ])
            // One compact menu per row so the queue fits phones and tablets.
            ->recordActions([ActionGroup::make([
                Action::make('explain')
                    ->label('Why?')
                    ->icon(Heroicon::OutlinedQuestionMarkCircle)
                    ->color('gray')
                    ->modalHeading(fn (OutageQueueEntry $record) => 'Eligibility — '.$record->outage?->ticket_number)
                    ->modalWidth('6xl')
                    ->modalSubmitAction(false)
                    ->modalContent(fn (OutageQueueEntry $record) => view('filament.fifo-explain', [
                        'entry' => $record,
                        'rows' => app(FifoAssignmentEngine::class)->explain($record),
                    ])),
                Action::make('assign')
                    ->label('Assign')
                    ->icon(Heroicon::OutlinedArrowsRightLeft)
                    ->color('danger')
                    ->visible(fn () => Auth::user()?->can('queue.override') && app(Settings::class)->bool('fifo.manual_override'))
                    ->modalDescription('Manual assignment overrides FIFO order and is fully audited.')
                    ->schema([
                        Select::make('user_id')->label('Assign to')->required()->searchable()
                            ->options(fn () => OutageActions::queueUserOptions($this->queueType())),
                        Textarea::make('reason')->label('Reason')->required()->maxLength(1000),
                    ])
                    ->action(function (OutageQueueEntry $record, array $data): void {
                        try {
                            app(FifoAssignmentEngine::class)->manualAssign($record->outage, $record->queue_type, User::query()->findOrFail($data['user_id']), Auth::user(), $data['reason']);
                            Notification::make()->title('Assigned')->success()->send();
                        } catch (ValidationException|AuthorizationException $exception) {
                            Notification::make()->title('Not assigned')->body($exception->getMessage())->danger()->send();
                        }
                        $this->snapshotCache = null;
                    }),
            ])]);
    }
}
