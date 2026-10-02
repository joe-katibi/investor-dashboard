<?php

namespace App\Filament\Resources\MobilityOutages\RelationManagers;

use App\Filament\Resources\MobilityOutages\MobilityActions;
use App\Filament\Resources\Outages\OutageActions;
use App\Models\OutageUpdate;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * Chronological ticket timeline: creation, assignment, status changes, engineer updates, vendor
 * assignment, automatic e-mails, manual vendor updates, attachments, NOC verification, closure
 * and reopening — each marked as coming from the System, the NOC, an Engineer or a Technician.
 */
class TimelineRelationManager extends RelationManager
{
    protected static string $relationship = 'updates';

    protected static ?string $title = 'Timeline';

    public function isReadOnly(): bool
    {
        return false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->modifyQueryUsing(fn ($query) => $query->with('user:id,name'))
            ->columns([
                TextColumn::make('created_at')->label('When')->dateTime('d M H:i')->sortable(),
                TextColumn::make('actor_type')->label('From')->badge()->placeholder('—')
                    ->color(fn (?string $state) => match ($state) {
                        OutageUpdate::ACTOR_SYSTEM => 'gray', OutageUpdate::ACTOR_NOC => 'info', default => 'warning',
                    }),
                TextColumn::make('type')->badge()->visibleFrom('md')->formatStateUsing(fn (string $state) => OutageUpdate::TYPES[$state] ?? $state)
                    ->color(fn (string $state) => match ($state) {
                        'system' => 'gray', 'restoration' => 'success', 'escalation' => 'danger', 'vendor' => 'warning', default => 'info',
                    }),
                TextColumn::make('body')->label('Entry')->wrap(),
                TextColumn::make('user.name')->label('By')->placeholder('System')->visibleFrom('md'),
            ])
            ->filters([
                SelectFilter::make('actor_type')->label('From')->options([
                    OutageUpdate::ACTOR_SYSTEM => 'System', OutageUpdate::ACTOR_NOC => 'NOC',
                    OutageUpdate::ACTOR_ENGINEER => 'Engineer', OutageUpdate::ACTOR_TECHNICIAN => 'Technician',
                ]),
                SelectFilter::make('type')->options(OutageUpdate::TYPES),
            ])
            ->headerActions([
                OutageActions::addUpdate()->record($this->getOwnerRecord()),
                MobilityActions::addAttachment()->record($this->getOwnerRecord()),
            ]);
    }
}
