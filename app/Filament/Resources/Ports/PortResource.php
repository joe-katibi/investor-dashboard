<?php

namespace App\Filament\Resources\Ports;

use App\Filament\Concerns\GuardsWithPermission;
use App\Filament\Resources\Ports\Pages\ManagePorts;
use App\Filament\Support\AuditFields;
use App\Models\Port;
use App\Models\Slot;
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
use Illuminate\Validation\Rules\Unique;
use UnitEnum;

class PortResource extends Resource
{
    use GuardsWithPermission;

    protected static string $permission = 'topology.manage';

    protected static ?string $viewPermission = 'outage.view';

    protected static ?string $model = Port::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedStop;

    protected static string|UnitEnum|null $navigationGroup = 'Topology';

    protected static ?int $navigationSort = 4;

    protected static ?string $recordTitleAttribute = 'name';

    public static function slotOptions(): array
    {
        return Slot::query()->with('fdt.olt:id,name')->where('is_active', true)->limit(5000)->get()
            ->mapWithKeys(fn (Slot $slot) => [$slot->id => $slot->path])->sort()->all();
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('slot_id')->label('Parent slot (OLT › FDT › Slot)')->options(fn () => static::slotOptions())->searchable()->required(),
            TextInput::make('name')->label('Port identifier')->required()->maxLength(60)
                ->unique(ignoreRecord: true, modifyRuleUsing: fn (Unique $rule, $get) => $rule->where('slot_id', $get('slot_id'))),
            AuditFields::description(),
            AuditFields::activeToggle(),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('slot.fdt.olt:id,name'))
            ->columns([
                TextColumn::make('slot.fdt.olt.name')->label('OLT'),
                TextColumn::make('slot.fdt.name')->label('FDT'),
                TextColumn::make('slot.name')->label('Slot'),
                TextColumn::make('name')->label('Port')->searchable()->sortable(),
                AuditFields::activeColumn(),
                ...AuditFields::columns(),
            ])
            ->filters([
                SelectFilter::make('slot_id')->label('Slot')->options(fn () => static::slotOptions())->searchable(),
                AuditFields::activeFilter(),
            ])
            ->recordActions([EditAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => ManagePorts::route('/')];
    }
}
