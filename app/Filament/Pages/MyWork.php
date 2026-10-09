<?php

namespace App\Filament\Pages;

use App\Enums\AssignmentStatus;
use App\Enums\AvailabilityStatus;
use App\Filament\Concerns\RefreshesOnOmsEvents;
use App\Filament\Resources\Outages\OutageActions;
use App\Filament\Support\OutageLink;
use App\Models\Outage;
use App\Models\OutageAssignment;
use App\Services\Fifo\FifoAssignmentEngine;
use App\Services\Sla\DeskSla;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use UnitEnum;

/**
 * Personal work queue: tickets FIFO assigned to me, acceptance deadlines, and my
 * availability (which controls whether FIFO offers me new tickets).
 */
class MyWork extends Page implements HasTable
{
    use InteractsWithTable, RefreshesOnOmsEvents;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedInbox;

    protected static string|UnitEnum|null $navigationGroup = 'Operations';

    protected static ?int $navigationSort = 1;

    protected static ?string $navigationLabel = 'My Work';

    protected static ?string $title = 'My Work';

    protected string $view = 'filament.pages.my-work';

    /** How often the page looks for new work by itself (seconds) — also in a browser tab that is not in front. */
    public const REFRESH_SECONDS = 40;

    /** The assignments this page has already shown: one that appears later is announced. */
    public array $knownAssignments = [];

    public function mount(): void
    {
        $this->knownAssignments = $this->assignmentIds();
    }

    /**
     * Runs every REFRESH_SECONDS: the list is redrawn, and a ticket that was not there before is
     * announced — a notification on the page and its count in the browser tab's title, so it is
     * seen from another tab too.
     */
    public function checkForNewWork(): void
    {
        $current = $this->assignmentIds();
        $new = array_values(array_diff($current, $this->knownAssignments));
        $this->knownAssignments = $current;

        if ($new !== []) {
            $tickets = Outage::query()
                ->whereIn('id', OutageAssignment::query()->whereIn('id', $new)->select('outage_id'))
                ->orderBy('sla_due_at')->get(['id', 'ticket_number', 'title']);

            Notification::make()
                ->title($tickets->count() === 1 ? 'New ticket for you: '.$tickets->first()->ticket_number : $tickets->count().' new tickets for you')
                ->body($tickets->take(5)->map(fn (Outage $ticket) => e($ticket->ticket_number.' — '.str($ticket->title)->limit(70)))->implode('<br>'))
                ->icon('heroicon-o-inbox-arrow-down')->warning()->persistent()->send();
        }

        $this->js('document.title = '.json_encode(($current === [] ? '' : '('.count($current).') ').'My Work - '.config('app.name')));
    }

    /** @return list<int> ids of my active assignments */
    private function assignmentIds(): array
    {
        return OutageAssignment::query()->where('user_id', Auth::id())->active()->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    public static function canAccess(): bool
    {
        return (bool) Auth::user()?->canAny(['outage.view', 'mobility.view', 'ticket.view']);
    }

    public static function getNavigationBadge(): ?string
    {
        $count = OutageAssignment::query()->where('user_id', Auth::id())->active()->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public function getSubheading(): ?string
    {
        $user = Auth::user()->loadMissing([
            'shiftAssignments' => fn ($q) => $q->activeAt(now()),
            'leaves' => fn ($q) => $q->approvedAt(now()),
        ]);

        $shift = $user->shiftAssignments->first();

        return collect([
            'Availability: '.$user->availability_status->getLabel(),
            $shift ? 'On shift until '.$shift->shift_end->format('H:i') : 'Not on an active shift',
            $user->leaves->isNotEmpty() ? 'On approved leave' : null,
        ])->filter()->implode(' · ');
    }

    protected function getHeaderActions(): array
    {
        $actions = [];

        foreach (AvailabilityStatus::cases() as $status) {
            $actions[] = Action::make('availability_'.$status->value)
                ->label($status->getLabel())
                ->color(fn () => Auth::user()->availability_status === $status ? $status->getColor() : 'gray')
                ->outlined(fn () => Auth::user()->availability_status !== $status)
                ->action(function () use ($status): void {
                    app(FifoAssignmentEngine::class)->setAvailability(Auth::user(), $status, Auth::user(), 'Self-service');
                    Notification::make()->title('You are now '.$status->getLabel())->success()->send();
                });
        }

        return $actions;
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => Outage::query()
                ->whereHas('assignments', fn ($q) => $q->where('user_id', Auth::id())->whereIn('status', AssignmentStatus::activeValues()))
                ->with(['priority:id,name,color', 'status:id,name,color', 'outageType:id,name', 'activeAssignments', 'openSupportRequest', 'changeRequest', 'vendor', 'ticket.subcategory', 'ticket.visitType',
                    'assignments' => fn ($q) => $q->where('user_id', Auth::id())->whereIn('status', AssignmentStatus::activeValues())->with('queueEntry')]))
            ->defaultSort('sla_due_at')
            // Redrawn by the page's own refresh (checkForNewWork, every REFRESH_SECONDS).
            // FTTH and 4G tickets each open on their own ticket page.
            ->recordUrl(fn (Outage $record) => OutageLink::url($record))
            ->emptyStateHeading('Nothing assigned to you')
            ->emptyStateDescription('Set yourself Available to receive the next eligible ticket from the FIFO queue.')
            ->emptyStateIcon('heroicon-o-inbox')
            ->columns([
                TextColumn::make('ticket_number')->label('Ticket')->weight('bold')->wrap()->formatStateUsing(fn (?string $state) => str_replace('-', "\u{2011}", (string) $state))->description(fn (Outage $record) => str($record->title)->limit(45)),
                TextColumn::make('queue')->label('My step')->badge()->visibleFrom('sm')
                    // A step of a custom workflow shows its own name.
                    ->state(fn (Outage $record) => $record->assignments->first()?->queueEntry?->label() ?? $record->assignments->first()?->queue_type?->getLabel())
                    // The time this desk has to act on it (SLA per desk), when the desk has a target.
                    ->description(fn (Outage $record) => ($due = $record->assignments->first()?->queueEntry?->sla_due_at) ? 'desk target: '.DeskSla::describe($due) : null),
                TextColumn::make('assignment_status')->label('Assignment')->badge()->wrap()
                    ->state(fn (Outage $record) => $record->assignments->first()?->status)
                    ->description(fn (Outage $record) => ($a = $record->assignments->first()) && $a->status === AssignmentStatus::Assigned && $a->ack_due_at
                        ? 'accept by '.$a->ack_due_at->format('H:i').' ('.$a->ack_due_at->diffForHumans(short: true).')' : null),
                TextColumn::make('priority.name')->label('Priority')->badge()->color(fn (Outage $record) => $record->priority?->color ?? 'gray')->visibleFrom('sm'),
                TextColumn::make('customers_affected')->label('Customers')->numeric()->visibleFrom('lg'),
                TextColumn::make('status.name')->label('Status')->badge()->wrap()->color(fn (Outage $record) => $record->status?->color ?? 'gray')->visibleFrom('md'),
                TextColumn::make('sla_state')->label('SLA')->badge()->visibleFrom('md')->state(fn (Outage $record) => $record->currentSlaState())
                    ->description(fn (Outage $record) => $record->sla_due_at ? 'due '.$record->sla_due_at->diffForHumans(short: true) : null),
                TextColumn::make('elapsed')->state(fn (Outage $record) => $record->elapsedForHumans())->visibleFrom('lg'),
            ])
            ->recordActions([
                OutageActions::accept()->button()->size('sm'),
                ActionGroup::make([
                    ...array_values(array_filter(OutageActions::all(), fn (Action $action) => $action->getName() !== 'acceptAssignment')),
                ]),
            ]);
    }
}
