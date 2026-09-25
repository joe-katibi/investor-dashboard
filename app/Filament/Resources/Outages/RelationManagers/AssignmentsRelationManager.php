<?php

namespace App\Filament\Resources\Outages\RelationManagers;

use App\Models\OutageAssignment;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/** FIFO assignment records with the decision explanation (spec §27.11). */
class AssignmentsRelationManager extends RelationManager
{
    protected static string $relationship = 'assignments';

    protected static ?string $title = 'FIFO assignments';

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->modifyQueryUsing(fn ($query) => $query->with(['user:id,name', 'team:id,name', 'subTeam:id,name', 'assignedBy:id,name']))
            ->columns([
                TextColumn::make('queue_type')->label('Queue')->badge(),
                TextColumn::make('user.name')->label('Assigned to'),
                TextColumn::make('assignment_type')->label('How')->badge(),
                TextColumn::make('queue_position')->label('Pos.')->placeholder('—'),
                TextColumn::make('priority_name')->label('Priority @ assignment')->placeholder('—'),
                TextColumn::make('queued_at')->label('Queued')->dateTime('d M H:i:s'),
                TextColumn::make('assigned_at')->label('Assigned')->dateTime('d M H:i:s'),
                TextColumn::make('accepted_at')->label('Accepted')->dateTime('H:i:s')->placeholder('—'),
                TextColumn::make('status')->badge(),
                TextColumn::make('release_reason')->label('Closed because')->placeholder('—')->wrap()->toggleable(),
                TextColumn::make('assignedBy.name')->label('Assigned by')->placeholder('FIFO engine')->toggleable(),
            ])
            ->recordActions([
                Action::make('why')
                    ->label('Why?')
                    ->icon(Heroicon::OutlinedQuestionMarkCircle)
                    ->modalHeading('Assignment decision')
                    ->modalWidth('4xl')
                    ->modalSubmitAction(false)
                    ->modalContent(fn (OutageAssignment $record) => view('filament.fifo-decision', ['assignment' => $record])),
            ]);
    }
}
