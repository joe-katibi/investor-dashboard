<?php

namespace App\Filament\Resources\Enodebs;

use App\Filament\Concerns\GuardsWithPermission;
use App\Filament\Resources\Enodebs\Pages\ManageEnodebs;
use App\Filament\Support\AuditFields;
use App\Models\Enodeb;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

/** 4G base station sites (eNodeBs) and the tower company each one stands on. */
class EnodebResource extends Resource
{
    use GuardsWithPermission;

    protected static string $permission = 'mobility.configure';

    protected static ?string $viewPermission = 'mobility.view';

    protected static ?string $model = Enodeb::class;

    protected static ?string $slug = '4g/sites';

    protected static ?string $modelLabel = 'eNodeB site';

    protected static ?string $pluralModelLabel = 'eNodeB sites';

    protected static ?string $navigationLabel = 'eNodeB Sites';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSignal;

    protected static string|UnitEnum|null $navigationGroup = '4G NOC';

    protected static ?int $navigationSort = 6;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('site_id')->label('Site ID')->required()->maxLength(30)->unique(ignoreRecord: true),
            TextInput::make('name')->label('Site name')->required()->maxLength(150),
            TextInput::make('tower_company')->label('Tower company (owner)')->maxLength(30)->placeholder('ATC / TOA / UTEL / RT'),
            TextInput::make('tower_co_site_id')->label('Tower company site ID')->maxLength(60),
            Select::make('vendor_id')->label('Power vendor')->relationship('vendor', 'name')->preload()->searchable()
                ->helperText('Pre-selected when a power outage is raised for this site.'),
            TextInput::make('location')->label('Location / area')->maxLength(255),
            TextInput::make('latitude')->numeric()->minValue(-90)->maxValue(90),
            TextInput::make('longitude')->numeric()->minValue(-180)->maxValue(180),
            TextInput::make('bracket_height')->label('Proposed height / bracket (m)')->maxLength(40),
            TextInput::make('tower_height')->label('Building / tower height (m)')->maxLength(40),
            AuditFields::description(),
            AuditFields::activeToggle(),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->columns([
                TextColumn::make('site_id')->label('Site ID')->searchable()->sortable()->weight('bold'),
                TextColumn::make('name')->label('Site name')->searchable()->sortable()->wrap(),
                TextColumn::make('tower_company')->label('Tower co.')->badge()->color('gray')->placeholder('—')
                    ->description(fn (Enodeb $record) => $record->tower_co_site_id),
                TextColumn::make('vendor.name')->label('Power vendor')->placeholder('—')->visibleFrom('md'),
                TextColumn::make('transmissionNodes.code')->label('SSG')->badge()->color('info')->placeholder('—')->visibleFrom('md'),
                TextColumn::make('coordinates')->label('Lat / long')->visibleFrom('xl')->placeholder('—')
                    ->state(fn (Enodeb $record) => $record->latitude !== null ? $record->latitude.', '.$record->longitude : null),
                TextColumn::make('tower_height')->label('Tower (m)')->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('outages_count')->counts('outages')->label('Outages')->sortable()->visibleFrom('lg'),
                AuditFields::activeColumn(),
                ...AuditFields::columns(),
            ])
            ->filters([
                SelectFilter::make('tower_company')->label('Tower company')
                    ->options(fn () => Enodeb::query()->whereNotNull('tower_company')->distinct()->orderBy('tower_company')->pluck('tower_company', 'tower_company')),
                SelectFilter::make('vendor_id')->label('Power vendor')->relationship('vendor', 'name'),
                AuditFields::activeFilter(),
            ])
            ->recordActions([EditAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageEnodebs::route('/')];
    }
}
