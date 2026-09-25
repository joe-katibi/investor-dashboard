<?php

namespace App\Filament\Resources\MajorOutageTypes;

use App\Filament\Concerns\GuardsWithPermission;
use App\Filament\Resources\MajorOutageTypes\Pages\ManageMajorOutageTypes;
use App\Filament\Support\AuditFields;
use App\Models\MajorOutageType;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

class MajorOutageTypeResource extends Resource
{
    use GuardsWithPermission;

    protected static string $permission = 'master_data.manage';

    protected static ?string $model = MajorOutageType::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleGroup;

    protected static string|UnitEnum|null $navigationGroup = 'Configuration';

    protected static ?int $navigationSort = 12;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->required()->maxLength(100)->unique(ignoreRecord: true),
            TextInput::make('code')->required()->maxLength(30)->unique(ignoreRecord: true)
                ->disabled(fn (?MajorOutageType $record) => in_array($record?->code, [MajorOutageType::NETWORK, MajorOutageType::CORE, MajorOutageType::SYSTEM], true))
                ->dehydrated(fn (?MajorOutageType $record) => ! in_array($record?->code, [MajorOutageType::NETWORK, MajorOutageType::CORE, MajorOutageType::SYSTEM], true)),
            Toggle::make('requires_noc_confirmation')->label('Requires NOC confirmation')->default(true),
            Toggle::make('requires_infrastructure')->label('Escalate to Infrastructure after NOC confirmation')->default(true),
            Toggle::make('uses_topology')->label('Uses OLT / FDT / Slot / Port topology'),
            TextInput::make('sort_order')->numeric()->default(0),
            AuditFields::description(),
            AuditFields::activeToggle(),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('sort_order')
            ->columns([
                TextColumn::make('name')->searchable(),
                TextColumn::make('code')->badge(),
                IconColumn::make('requires_noc_confirmation')->label('NOC')->boolean(),
                IconColumn::make('requires_infrastructure')->label('Infra')->boolean(),
                IconColumn::make('uses_topology')->label('Topology')->boolean(),
                AuditFields::activeColumn(),
                ...AuditFields::columns(),
            ])
            ->recordActions([EditAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageMajorOutageTypes::route('/')];
    }
}
