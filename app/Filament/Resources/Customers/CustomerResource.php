<?php

namespace App\Filament\Resources\Customers;

use App\Filament\Concerns\GuardsWithPermission;
use App\Filament\Resources\Customers\Pages\ManageCustomers;
use App\Filament\Support\AuditFields;
use App\Models\Customer;
use App\Models\Fdt;
use App\Models\Olt;
use App\Models\Port;
use App\Models\Region;
use App\Models\Slot;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/** The customer database: accounts and where each one hangs on the FTTH network (OLT → FDT → Slot → Port). */
class CustomerResource extends Resource
{
    use GuardsWithPermission;

    protected static string $permission = 'customer.manage';

    protected static ?string $viewPermission = 'ticket.view';

    protected static ?string $model = Customer::class;

    protected static ?string $slug = 'customers';

    protected static ?string $navigationLabel = 'Customers';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static string|UnitEnum|null $navigationGroup = 'Customer Care';

    protected static ?int $navigationSort = 4;

    protected static ?string $recordTitleAttribute = 'account_number';

    public static function getGloballySearchableAttributes(): array
    {
        return ['account_number', 'name', 'primary_phone'];
    }

    public static function form(Schema $schema): Schema
    {
        $id = fn (mixed $value) => is_int($value) || (is_string($value) && ctype_digit($value)) ? (int) $value : null;

        return $schema->components([
            TextInput::make('account_number')->label('Account number (ONU name)')->required()->maxLength(60)->unique(ignoreRecord: true),
            TextInput::make('name')->label('Customer name (alias name)')->maxLength(190),
            TextInput::make('primary_phone')->label('Primary number')->tel()->maxLength(40),
            TextInput::make('alt_phone_1')->label('Alternative number 1')->tel()->maxLength(40),
            TextInput::make('alt_phone_2')->label('Alternative number 2')->tel()->maxLength(40),
            TextInput::make('email')->email()->maxLength(255),
            TextInput::make('address')->maxLength(250),
            TextInput::make('landmark')->maxLength(250),
            TextInput::make('bandwidth')->label('Bandwidth / package')->maxLength(60),
            Select::make('region_id')->label('Region')->options(fn () => Region::query()->orderBy('name')->pluck('name', 'id'))
                ->helperText('Taken from the OLT when left empty.'),
            Select::make('olt_id')->label('OLT')->searchable()->live()->options(fn () => Olt::query()->orderBy('name')->pluck('name', 'id'))
                ->afterStateUpdated(function (Set $set): void {
                    $set('fdt_id', null);
                    $set('slot_id', null);
                    $set('port_id', null);
                }),
            Select::make('fdt_id')->label('FDT')->searchable()->live()
                ->options(fn (Get $get) => $id($get('olt_id')) ? Fdt::query()->where('olt_id', $id($get('olt_id')))->orderBy('name')->pluck('name', 'id') : [])
                ->afterStateUpdated(function (Set $set): void {
                    $set('slot_id', null);
                    $set('port_id', null);
                }),
            Select::make('slot_id')->label('Slot')->live()
                ->options(fn (Get $get) => $id($get('fdt_id')) ? Slot::query()->where('fdt_id', $id($get('fdt_id')))->orderBy('id')->pluck('name', 'id') : [])
                ->afterStateUpdated(fn (Set $set) => $set('port_id', null)),
            Select::make('port_id')->label('Port')
                ->options(fn (Get $get) => $id($get('slot_id')) ? Port::query()->where('slot_id', $id($get('slot_id')))->orderBy('id')->pluck('name', 'id') : []),
            Textarea::make('notes')->rows(2)->maxLength(2000)->columnSpanFull(),
            AuditFields::activeToggle()->helperText('Inactive customers stay on their tickets.'),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['olt:id,name', 'fdt:id,name', 'slot:id,name', 'port:id,name', 'region:id,name'])->withCount('tickets'))
            ->defaultSort('account_number')
            ->columns([
                TextColumn::make('account_number')->label('Account')->searchable()->sortable()->weight('bold')->copyable(),
                TextColumn::make('name')->label('Customer')->searchable()->sortable()->wrap()->placeholder('—'),
                TextColumn::make('primary_phone')->label('Phone')->searchable()->placeholder('—')->visibleFrom('md'),
                TextColumn::make('position')->label('OLT › FDT › Slot › Port')->placeholder('Not on file')->wrap()->visibleFrom('md')
                    ->state(fn (Customer $record) => $record->topologyPath() ?: null),
                TextColumn::make('region.name')->label('Region')->placeholder('—')->visibleFrom('lg'),
                TextColumn::make('bandwidth')->placeholder('—')->visibleFrom('xl'),
                TextColumn::make('source')->badge()->visibleFrom('lg')
                    ->formatStateUsing(fn (Customer $record) => $record->isProvisional() ? 'Captured by hand' : ($record->source === Customer::SOURCE_IMPORT ? 'Customer file' : 'Manual'))
                    ->color(fn (Customer $record) => $record->isProvisional() ? 'warning' : 'success'),
                TextColumn::make('tickets_count')->label('Tickets')->sortable()->visibleFrom('lg'),
                AuditFields::activeColumn(),
                ...AuditFields::columns(),
            ])
            ->filters([
                SelectFilter::make('olt_id')->label('OLT')->relationship('olt', 'name')->searchable()->preload(),
                SelectFilter::make('region_id')->label('Region')->relationship('region', 'name'),
                SelectFilter::make('source')->label('Record')->options(['manual' => 'Captured by hand / manual', 'import' => 'From the customer file']),
                AuditFields::activeFilter(),
            ])
            ->recordActions([EditAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageCustomers::route('/')];
    }
}
