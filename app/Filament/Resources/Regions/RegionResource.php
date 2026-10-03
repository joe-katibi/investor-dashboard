<?php

namespace App\Filament\Resources\Regions;

use App\Filament\Concerns\GuardsWithPermission;
use App\Filament\Resources\Regions\Pages\ManageRegions;
use App\Filament\Support\AuditFields;
use App\Models\Region;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Regions. An OLT belongs to a region, and customers / tickets on it inherit that. People locked
 * to regions (dispatchers, CX field support…) only receive tickets of those regions from FIFO.
 */
class RegionResource extends Resource
{
    use GuardsWithPermission;

    protected static string $permission = 'ticket.configure';

    protected static ?string $viewPermission = 'ticket.view';

    protected static ?string $model = Region::class;

    protected static ?string $slug = 'customer-care/regions';

    protected static ?string $navigationLabel = 'Regions';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedGlobeAlt;

    protected static string|UnitEnum|null $navigationGroup = 'Customer Care';

    protected static ?int $navigationSort = 20;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->required()->maxLength(120)->unique(ignoreRecord: true),
            TextInput::make('code')->maxLength(30)->unique(ignoreRecord: true),
            Select::make('users')->label('People locked to this region')->relationship('users', 'name')->multiple()->preload()->searchable()
                ->helperText('They only receive tickets of the regions they are locked to. People locked to no region receive tickets of every region.'),
            AuditFields::description(),
            AuditFields::activeToggle()->helperText('Inactive regions cannot be picked for new customers and tickets.'),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')->searchable()->sortable()->weight('bold'),
                TextColumn::make('code')->placeholder('—'),
                TextColumn::make('olts.name')->label('OLTs')->badge()->color('gray')->limitList(8)->placeholder('None')->wrap(),
                TextColumn::make('users.name')->label('People locked to it')->badge()->color('info')->limitList(6)->placeholder('—')->wrap()->visibleFrom('lg'),
                TextColumn::make('customers_count')->counts('customers')->label('Customers')->sortable()->visibleFrom('md'),
                AuditFields::activeColumn(),
                ...AuditFields::columns(),
            ])
            ->filters([AuditFields::activeFilter()])
            ->recordActions([EditAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageRegions::route('/')];
    }
}
