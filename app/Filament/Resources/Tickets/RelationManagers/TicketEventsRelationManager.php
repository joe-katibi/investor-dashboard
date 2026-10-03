<?php

namespace App\Filament\Resources\Tickets\RelationManagers;

use App\Models\TicketEvent;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * The steps of the ticket with their times: created, queued, assigned, accepted, en route,
 * arrived, paused, resumed, team leader intervention, completed, verified, closed, escalated.
 */
class TicketEventsRelationManager extends RelationManager
{
    protected static string $relationship = 'ticketEvents';

    protected static ?string $title = 'Steps & times';

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->modifyQueryUsing(fn ($query) => $query->with(['user:id,name', 'subjectUser:id,name']))
            ->columns([
                TextColumn::make('occurred_at')->label('When')->dateTime('d M Y H:i:s')->sortable(),
                TextColumn::make('event')->label('Step')->badge()->formatStateUsing(fn (TicketEvent $record) => $record->label())
                    ->color(fn (string $state) => match (true) {
                        in_array($state, ['closed', 'completed', 'verified', 'confirmed', 'approved'], true) => 'success',
                        in_array($state, ['escalated', 'not_completed', 'declined', 'reopened', 'intervention_started', 'follow_up_due', 'cancelled'], true) => 'danger',
                        in_array($state, ['paused', 'rescheduled', 'returned_to_queue'], true) => 'warning',
                        default => 'info',
                    }),
                TextColumn::make('desk')->label('Desk')->badge()->placeholder('—')->visibleFrom('md'),
                TextColumn::make('subjectUser.name')->label('Person')->placeholder('—')->visibleFrom('md'),
                TextColumn::make('note')->wrap()->placeholder('—'),
                TextColumn::make('user.name')->label('By')->placeholder('System')->visibleFrom('lg'),
            ])
            ->filters([SelectFilter::make('event')->label('Step')->multiple()->options(TicketEvent::LABELS)]);
    }
}
