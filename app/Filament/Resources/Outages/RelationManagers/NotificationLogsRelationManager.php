<?php

namespace App\Filament\Resources\Outages\RelationManagers;

use App\Enums\NotificationEvent;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class NotificationLogsRelationManager extends RelationManager
{
    protected static string $relationship = 'notificationLogs';

    protected static ?string $title = 'Notifications';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return (bool) Auth::user()?->can('audit.view');
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('created_at')->label('When')->dateTime('d M H:i:s'),
                TextColumn::make('event')->formatStateUsing(fn (string $state) => NotificationEvent::tryFrom($state)?->getLabel() ?? $state),
                TextColumn::make('channel')->badge()->color('gray'),
                TextColumn::make('recipient'),
                TextColumn::make('status')->badge()->color(fn (string $state) => match ($state) {
                    'sent' => 'success', 'failed' => 'danger', 'retrying' => 'warning', default => 'gray',
                }),
                TextColumn::make('failure_reason')->placeholder('—')->limit(50),
            ]);
    }
}
