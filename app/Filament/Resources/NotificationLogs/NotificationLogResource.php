<?php

namespace App\Filament\Resources\NotificationLogs;

use App\Enums\NotificationChannel;
use App\Enums\NotificationEvent;
use App\Filament\Resources\NotificationLogs\Pages\ManageNotificationLogs;
use App\Jobs\SendNotificationJob;
use App\Models\NotificationLog;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use UnitEnum;

/** Delivery log for every notification: recipient, event, time, status, failure reason (spec §12). */
class NotificationLogResource extends Resource
{
    protected static ?string $model = NotificationLog::class;

    protected static ?string $navigationLabel = 'Notification Log';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedInboxStack;

    protected static string|UnitEnum|null $navigationGroup = 'Monitoring';

    protected static ?int $navigationSort = 1;

    public static function canViewAny(): bool
    {
        return (bool) Auth::user()?->can('audit.view');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('created_at')->dateTime(),
            TextEntry::make('event'),
            TextEntry::make('channel'),
            TextEntry::make('recipient'),
            TextEntry::make('status')->badge(),
            TextEntry::make('attempts'),
            TextEntry::make('sent_at')->dateTime()->placeholder('—'),
            TextEntry::make('trigger'),
            TextEntry::make('outage.ticket_number')->label('Ticket')->placeholder('—'),
            TextEntry::make('subject')->columnSpanFull(),
            TextEntry::make('body')->columnSpanFull()->prose(),
            TextEntry::make('failure_reason')->columnSpanFull()->color('danger')->placeholder('—'),
        ])->columns(3);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->modifyQueryUsing(fn (Builder $query) => $query->with('outage:id,ticket_number'))
            ->poll('30s')
            ->columns([
                TextColumn::make('created_at')->dateTime('Y-m-d H:i:s')->sortable(),
                TextColumn::make('event')->badge()->color('gray')
                    ->formatStateUsing(fn (string $state) => NotificationEvent::tryFrom($state)?->getLabel() ?? $state),
                TextColumn::make('channel')->formatStateUsing(fn (string $state) => NotificationChannel::tryFrom($state)?->getLabel() ?? $state),
                TextColumn::make('recipient')->searchable(),
                TextColumn::make('outage.ticket_number')->label('Ticket')->searchable()->placeholder('—'),
                TextColumn::make('status')->badge()->color(fn (string $state) => match ($state) {
                    'sent' => 'success', 'failed' => 'danger', 'retrying' => 'warning', default => 'gray',
                }),
                TextColumn::make('attempts'),
                TextColumn::make('failure_reason')->limit(40)->placeholder('—')->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')->options(['pending' => 'Pending', 'retrying' => 'Retrying', 'sent' => 'Sent', 'failed' => 'Failed']),
                SelectFilter::make('channel')->options(NotificationChannel::class),
                SelectFilter::make('event')->options(NotificationEvent::class),
            ])
            ->recordActions([
                ViewAction::make(),
                Action::make('retry')
                    ->icon(Heroicon::OutlinedArrowPath)
                    ->visible(fn (NotificationLog $record) => $record->status === 'failed' && $record->email_template_id === null)
                    ->action(function (NotificationLog $record): void {
                        $record->forceFill(['status' => 'pending', 'failure_reason' => null])->save();
                        SendNotificationJob::dispatch($record->id);
                        Notification::make()->title('Notification re-queued')->success()->send();
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageNotificationLogs::route('/')];
    }
}
