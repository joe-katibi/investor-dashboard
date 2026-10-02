<?php

namespace App\Filament\Resources\MobilityOutages\RelationManagers;

use App\Filament\Resources\MobilityOutages\MobilityActions;
use App\Models\OutageAttachment;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/** Files on the ticket: engineer evidence, screenshots, vendor e-mails. Private downloads. */
class AttachmentsRelationManager extends RelationManager
{
    protected static string $relationship = 'attachments';

    protected static ?string $title = 'Attachments';

    public function isReadOnly(): bool
    {
        return false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['user:id,name', 'vendorUpdate:id,vendor_status,occurred_at']))
            ->emptyStateHeading('No attachments')
            ->columns([
                TextColumn::make('original_name')->label('File')->state(fn (OutageAttachment $record) => $record->name())
                    ->icon(Heroicon::OutlinedPaperClip)->wrap(),
                TextColumn::make('note')->placeholder('—')->wrap()
                    ->state(fn (OutageAttachment $record) => $record->note ?? ($record->vendorUpdate ? 'Vendor update: '.$record->vendorUpdate->statusLabel() : null)),
                TextColumn::make('user.name')->label('Added by')->placeholder('—'),
                TextColumn::make('created_at')->label('Added')->dateTime('d M Y H:i'),
            ])
            ->recordActions([
                Action::make('download')->label('Download')->icon(Heroicon::OutlinedArrowDownTray)
                    ->url(fn (OutageAttachment $record) => $record->url()),
            ])
            ->headerActions([MobilityActions::addAttachment()->record($this->getOwnerRecord())]);
    }
}
