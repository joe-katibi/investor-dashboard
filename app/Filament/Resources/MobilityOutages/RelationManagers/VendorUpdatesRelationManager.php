<?php

namespace App\Filament\Resources\MobilityOutages\RelationManagers;

use App\Filament\Resources\MobilityOutages\MobilityActions;
use App\Models\OutageAttachment;
use App\Models\VendorUpdate;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;

/** What the vendor reported, as captured by the NOC (vendors never use the system). */
class VendorUpdatesRelationManager extends RelationManager
{
    protected static string $relationship = 'vendorUpdates';

    protected static ?string $title = 'Vendor updates';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return $ownerRecord->isVendorTicket();
    }

    public function isReadOnly(): bool
    {
        return false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['vendor:id,name', 'user:id,name', 'attachments']))
            ->emptyStateHeading('No vendor update recorded yet')
            ->emptyStateDescription('When the vendor replies by e-mail, phone or WhatsApp, record it with “Add vendor update”.')
            ->columns([
                TextColumn::make('occurred_at')->label('When')->dateTime('d M Y H:i')
                    ->description(fn (VendorUpdate $record) => $record->vendor?->name),
                TextColumn::make('vendor_status')->label('Vendor status')->badge()
                    ->formatStateUsing(fn (VendorUpdate $record) => $record->statusLabel())
                    ->color(fn (string $state) => match (true) {
                        in_array($state, VendorUpdate::RESOLVED, true) => 'success',
                        in_array($state, ['unable_to_resolve', 'escalated'], true) => 'danger',
                        in_array($state, ['awaiting_parts', 'awaiting_access'], true) => 'warning',
                        default => 'info',
                    }),
                TextColumn::make('method')->label('Via')->formatStateUsing(fn (VendorUpdate $record) => $record->methodLabel())
                    ->description(fn (VendorUpdate $record) => $record->contact_person)->visibleFrom('md'),
                TextColumn::make('eta_at')->label('ETA')->dateTime('d M H:i')->placeholder('—')->visibleFrom('md'),
                TextColumn::make('next_update_at')->label('Next update')->dateTime('d M H:i')->placeholder('—')->visibleFrom('lg'),
                TextColumn::make('comments')->label('Vendor comments')->wrap()->placeholder('—'),
                TextColumn::make('files')->label('Evidence')->html()->placeholder('—')->visibleFrom('lg')
                    ->state(fn (VendorUpdate $record) => $record->attachments->isEmpty() ? null : new HtmlString($record->attachments
                        ->map(fn (OutageAttachment $file) => '<a class="underline" href="'.e($file->url()).'">'.e($file->name()).'</a>')->implode('<br>'))),
                TextColumn::make('user.name')->label('Recorded by')->placeholder('—')->visibleFrom('lg')
                    ->description(fn (VendorUpdate $record) => $record->created_at?->format('d M H:i')),
            ])
            ->headerActions([MobilityActions::addVendorUpdate()->record($this->getOwnerRecord())]);
    }
}
