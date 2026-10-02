<?php

namespace App\Filament\Resources\VendorUpdates;

use App\Filament\Concerns\ReadOnlyResource;
use App\Filament\Resources\MobilityOutages\MobilityOutageResource;
use App\Filament\Resources\VendorUpdates\Pages\ManageVendorUpdates;
use App\Models\VendorUpdate;
use BackedEnum;
use Filament\Forms\Components\DatePicker;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use UnitEnum;

/** Every vendor update the NOC has captured, across all tickets. Recorded from the ticket itself. */
class VendorUpdateResource extends Resource
{
    use ReadOnlyResource;

    protected static ?string $model = VendorUpdate::class;

    protected static ?string $slug = '4g/vendor-updates';

    protected static ?string $navigationLabel = 'Vendor Updates';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChatBubbleLeftRight;

    protected static string|UnitEnum|null $navigationGroup = '4G NOC';

    protected static ?int $navigationSort = 4;

    public static function canViewAny(): bool
    {
        return (bool) Auth::user()?->can('mobility.view');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('occurred_at', 'desc')
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['vendor:id,name', 'user:id,name', 'outage:id,ticket_number,title,workflow']))
            ->recordUrl(fn (VendorUpdate $record) => MobilityOutageResource::getUrl('view', ['record' => $record->outage_id]))
            ->emptyStateHeading('No vendor updates yet')
            ->emptyStateDescription('Open a vendor ticket and use “Add vendor update” when the vendor replies.')
            ->columns([
                TextColumn::make('occurred_at')->label('When')->dateTime('d M Y H:i')->sortable(),
                TextColumn::make('outage.ticket_number')->label('Ticket')->searchable()->weight('bold')
                    ->description(fn (VendorUpdate $record) => str($record->outage?->title)->limit(50)),
                TextColumn::make('vendor.name')->label('Vendor')->badge()->color('gray'),
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
                TextColumn::make('eta_at')->label('ETA')->dateTime('d M H:i')->placeholder('—')->visibleFrom('lg'),
                TextColumn::make('next_update_at')->label('Next update')->dateTime('d M H:i')->placeholder('—')->visibleFrom('lg'),
                TextColumn::make('comments')->wrap()->placeholder('—')->visibleFrom('md'),
                TextColumn::make('user.name')->label('Recorded by')->placeholder('—')->visibleFrom('xl'),
            ])
            ->filters([
                SelectFilter::make('vendor_id')->label('Vendor')->relationship('vendor', 'name'),
                SelectFilter::make('vendor_status')->label('Vendor status')->options(VendorUpdate::STATUSES)->multiple(),
                SelectFilter::make('method')->label('Via')->options(VendorUpdate::METHODS),
                Filter::make('occurred_at')->schema([DatePicker::make('from'), DatePicker::make('until')])
                    ->query(fn (Builder $query, array $data) => $query
                        ->when($data['from'] ?? null, fn ($q, $d) => $q->where('occurred_at', '>=', $d))
                        ->when($data['until'] ?? null, fn ($q, $d) => $q->where('occurred_at', '<=', $d.' 23:59:59')))
                    ->columns(2),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageVendorUpdates::route('/')];
    }
}
