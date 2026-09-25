<?php

namespace App\Filament\Resources\Outages\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/** Every queue / assign / accept / reject / timeout / release / override event (spec §27.10). */
class AssignmentHistoriesRelationManager extends RelationManager
{
    protected static string $relationship = 'assignmentHistories';

    protected static ?string $title = 'Assignment history';

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->modifyQueryUsing(fn ($query) => $query->with(['previousUser:id,name', 'newUser:id,name', 'performer:id,name']))
            ->columns([
                TextColumn::make('performed_at')->label('When')->dateTime('d M H:i:s'),
                TextColumn::make('queue_type')->label('Queue')->badge()->placeholder('—'),
                TextColumn::make('action')->badge()->formatStateUsing(fn (string $state) => str($state)->replace('_', ' ')->title())
                    ->color(fn (string $state) => match ($state) {
                        'assigned', 'accepted', 'completed' => 'success',
                        'rejected', 'timed_out', 'override' => 'danger',
                        'released' => 'warning',
                        default => 'gray',
                    }),
                TextColumn::make('previousUser.name')->label('From')->placeholder('—'),
                TextColumn::make('newUser.name')->label('To')->placeholder('—'),
                TextColumn::make('reason')->wrap()->placeholder('—'),
                TextColumn::make('performer.name')->label('By')->placeholder('System'),
                TextColumn::make('trigger')->color('gray'),
            ]);
    }
}
