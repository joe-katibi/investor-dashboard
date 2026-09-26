<?php

namespace App\Filament\Pages;

use App\Enums\AssignmentStatus;
use App\Enums\AvailabilityStatus;
use App\Filament\Concerns\RefreshesOnOmsEvents;
use App\Filament\Resources\Outages\OutageActions;
use App\Filament\Resources\Outages\OutageResource;
use App\Models\Outage;
use App\Models\OutageAssignment;
use App\Services\Fifo\FifoAssignmentEngine;
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

    public static function canAccess(): bool
    {
        return (bool) Auth::user()?->can('outage.view');
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
                ->with(['priority:id,name,color', 'status:id,name,color', 'outageType:id,name', 'activeAssignments',
                    'assignments' => fn ($q) => $q->where('user_id', Auth::id())->whereIn('status', AssignmentStatus::activeValues())]))
            ->defaultSort('sla_due_at')
            ->poll('30s')
            ->recordUrl(fn (Outage $record) => OutageResource::getUrl('view', ['record' => $record]))
            ->emptyStateHeading('Nothing assigned to you')
            ->emptyStateDescription('Set yourself Available to receive the next eligible ticket from the FIFO queue.')
            ->emptyStateIcon('heroicon-o-inbox')
            ->columns([
                TextColumn::make('ticket_number')->label('Ticket')->weight('bold')->wrap()->formatStateUsing(fn (?string $state) => str_replace('-', "\u{2011}", (string) $state))->description(fn (Outage $record) => str($record->title)->limit(45)),
                TextColumn::make('queue')->label('My step')->badge()->visibleFrom('sm')
                    ->state(fn (Outage $record) => $record->assignments->first()?->queue_type?->getLabel()),
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
                    ...array_values(array_filter(OutageActions::primary(), fn (Action $action) => $action->getName() !== 'acceptAssignment')),
                    ...OutageActions::secondary(),
                ]),
            ]);
    }
}
