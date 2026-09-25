<?php

namespace App\Filament\Resources\Outages\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class FlagEventsRelationManager extends RelationManager
{
    protected static string $relationship = 'flagEvents';

    protected static ?string $title = 'SMS & credit history';

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->modifyQueryUsing(fn ($query) => $query->with('changedBy:id,name'))
            ->columns([
                TextColumn::make('created_at')->label('When')->dateTime('d M Y H:i'),
                TextColumn::make('flag')->formatStateUsing(fn (string $state) => $state === 'sms_sent' ? 'SMS sent' : 'Credit shared'),
                IconColumn::make('old_value')->label('Before')->boolean(),
                IconColumn::make('new_value')->label('After')->boolean(),
                TextColumn::make('changedBy.name')->label('Changed by'),
            ]);
    }
}
