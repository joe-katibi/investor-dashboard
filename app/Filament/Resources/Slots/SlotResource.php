<?php

namespace App\Filament\Resources\Slots;

use App\Filament\Concerns\GuardsWithPermission;
use App\Filament\Resources\Ports\PortResource;
use App\Filament\Resources\Slots\Pages\ManageSlots;
use App\Filament\Support\AuditFields;
use App\Models\Fdt;
use App\Models\Slot;
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

class SlotResource extends Resource
{
    use GuardsWithPermission;

    protected static string $permission = 'topology.manage';

    protected static ?string $viewPermission = 'outage.view';

    protected static ?string $model = Slot::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedViewColumns;

    protected static string|UnitEnum|null $navigationGroup = 'Topology';

    protected static ?int $navigationSort = 3;

    protected static ?string $recordTitleAttribute = 'name';

    public static function fdtOptions(): array
    {
        return Fdt::query()->with('olt:id,name')->where('is_active', true)->orderBy('olt_id')->orderBy('name')->get()
            ->mapWithKeys(fn (Fdt $fdt) => [$fdt->id => $fdt->path])->all();
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('fdt_id')->label('Parent FDT (OLT › FDT)')->options(fn () => static::fdtOptions())->searchable()->required(),
            TextInput::make('name')->label('Slot identifier')->required()->maxLength(60)
                ->unique(ignoreRecord: true, modifyRuleUsing: fn (Unique $rule, $get) => $rule->where('fdt_id', $get('fdt_id'))),
            AuditFields::description(),
            AuditFields::activeToggle(),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('fdt.olt:id,name'))
            ->columns([
                TextColumn::make('fdt.olt.name')->label('OLT')->sortable(),
                TextColumn::make('fdt.name')->label('FDT')->sortable(),
                TextColumn::make('name')->label('Slot')->searchable()->sortable(),
                TextColumn::make('ports_count')->counts('ports')->label('Ports'),
                AuditFields::activeColumn(),
                ...AuditFields::columns(),
            ])
            ->filters([
                SelectFilter::make('fdt_id')->label('FDT')->options(fn () => static::fdtOptions())->searchable(),
                AuditFields::activeFilter(),
            ])
            ->recordActions([
                Action::make('ports')->label('Ports')->icon(Heroicon::OutlinedArrowRight)->color('gray')
                    ->url(fn (Slot $record) => PortResource::getUrl('index', ['filters' => ['slot_id' => ['value' => $record->id]]])),
                EditAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageSlots::route('/')];
    }
}
