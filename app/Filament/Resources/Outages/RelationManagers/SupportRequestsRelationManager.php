<?php

namespace App\Filament\Resources\Outages\RelationManagers;

use App\Models\SupportRequest;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/** Support technician requests (drop cable → customer premises) worked in parallel on this ticket. */
class SupportRequestsRelationManager extends RelationManager
{
    protected static string $relationship = 'supportRequests';

    protected static ?string $title = 'Support technician';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return $ownerRecord->dispatched_at !== null;
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->modifyQueryUsing(fn ($query) => $query->with(['requester:id,name', 'completer:id,name', 'queueEntry.activeAssignment.user:id,name']))
            ->emptyStateHeading('No support technician requested')
            ->emptyStateDescription('Use "Request support technician" when the drop cable / customer premises also needs work.')
            ->columns([
                TextColumn::make('created_at')->label('Requested')->dateTime('d M Y H:i')
                    ->description(fn (SupportRequest $record) => $record->requester?->name ? 'by '.$record->requester->name : null),
                TextColumn::make('status')->badge()
                    ->color(fn (string $state) => match ($state) {
                        SupportRequest::OPEN => 'warning',
                        SupportRequest::COMPLETED => 'success',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (string $state) => ucfirst($state)),
                TextColumn::make('work_required')->label('Work required')->wrap()->limit(200),
                TextColumn::make('customer_details')->label('Customer')->wrap()->placeholder('—')->visibleFrom('lg'),
                TextColumn::make('technician')->label('Support technician')
                    ->state(fn (SupportRequest $record) => $record->isOpen()
                        ? ($record->queueEntry?->activeAssignment?->user?->name ?? 'Waiting in queue')
                        : $record->completer?->name)
                    ->placeholder('—'),
                TextColumn::make('outcome')->label('Outcome')->wrap()->limit(200)->placeholder('—')
                    ->state(fn (SupportRequest $record) => $record->completion_note ?? $record->cancel_reason)
                    ->description(fn (SupportRequest $record) => ($record->completed_at ?? $record->cancelled_at)?->format('d M H:i')),
            ]);
    }
}
