<?php

namespace App\Filament\Resources\Fdts;

use App\Filament\Concerns\GuardsWithPermission;
use App\Filament\Resources\Fdts\Pages\ManageFdts;
use App\Filament\Resources\Slots\SlotResource;
use App\Filament\Support\AuditFields;
use App\Models\Fdt;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Validation\Rules\Unique;
use UnitEnum;

class FdtResource extends Resource
{
    use GuardsWithPermission;

    protected static string $permission = 'topology.manage';

    protected static ?string $viewPermission = 'outage.view';

    protected static ?string $model = Fdt::class;

    protected static ?string $modelLabel = 'FDT';

    protected static ?string $pluralModelLabel = 'FDTs';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShare;

    protected static string|UnitEnum|null $navigationGroup = 'Topology';

    protected static ?int $navigationSort = 2;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('olt_id')->label('Parent OLT')->relationship('olt', 'name', fn ($query) => $query->where('is_active', true))
                ->searchable()->preload()->required(),
            TextInput::make('name')->label('Name / identifier')->required()->maxLength(120)
                ->unique(ignoreRecord: true, modifyRuleUsing: fn (Unique $rule, $get) => $rule->where('olt_id', $get('olt_id'))),
            TextInput::make('location')->maxLength(255),
            AuditFields::description(),
            AuditFields::activeToggle(),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('olt:id,name'))
            ->columns([
                TextColumn::make('olt.name')->label('OLT')->searchable()->sortable(),
                TextColumn::make('name')->label('FDT')->searchable()->sortable(),
                TextColumn::make('location')->placeholder('—'),
                TextColumn::make('slots_count')->counts('slots')->label('Slots'),
                AuditFields::activeColumn(),
                ...AuditFields::columns(),
            ])
            ->filters([
                SelectFilter::make('olt_id')->label('OLT')->relationship('olt', 'name')->searchable()->preload(),
                AuditFields::activeFilter(),
            ])
            ->recordActions([
                Action::make('slots')->label('Slots')->icon(Heroicon::OutlinedArrowRight)->color('gray')
                    ->url(fn (Fdt $record) => SlotResource::getUrl('index', ['filters' => ['fdt_id' => ['value' => $record->id]]])),
                EditAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageFdts::route('/')];
    }
}
