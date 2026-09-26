<?php

namespace App\Filament\Resources\Outages\RelationManagers;

use App\Models\OutageStatusHistory;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class StatusHistoriesRelationManager extends RelationManager
{
    protected static string $relationship = 'statusHistories';

    protected static ?string $title = 'Status history';

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->modifyQueryUsing(fn ($query) => $query->with(['fromStatus:id,name', 'toStatus:id,name,color', 'changedBy:id,name']))
            ->columns([
                TextColumn::make('created_at')->label('When')->dateTime('d M Y H:i:s'),
                TextColumn::make('fromStatus.name')->label('From')->placeholder('—')->visibleFrom('md'),
                TextColumn::make('toStatus.name')->label('To')->badge()->color(fn (OutageStatusHistory $record) => $record->toStatus?->color ?? 'gray'),
                TextColumn::make('reason')->wrap()->placeholder('—')->visibleFrom('sm'),
                TextColumn::make('changedBy.name')->label('By')->placeholder('System')->visibleFrom('md'),
                TextColumn::make('trigger')->color('gray')->visibleFrom('lg'),
                TextColumn::make('snapshot')->label('Snapshot')->toggleable(isToggledHiddenByDefault: true)
                    ->state(fn (OutageStatusHistory $record) => collect($record->snapshot ?? [])->filter()->map(fn ($v, $k) => "{$k}: {$v}")->implode(' · '))
                    ->wrap(),
            ]);
    }
}
