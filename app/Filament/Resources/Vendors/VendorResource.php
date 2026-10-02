<?php

namespace App\Filament\Resources\Vendors;

use App\Filament\Concerns\GuardsWithPermission;
use App\Filament\Resources\Vendors\Pages\ManageVendors;
use App\Filament\Support\AuditFields;
use App\Models\Vendor;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Vendor management. Vendors are records, not users: they have no login. The e-mail and CC
 * addresses here are where the automatic vendor notifications go.
 */
class VendorResource extends Resource
{
    use GuardsWithPermission;

    protected static string $permission = 'mobility.configure';

    protected static ?string $viewPermission = 'mobility.view';

    protected static ?string $model = Vendor::class;

    protected static ?string $slug = '4g/vendors';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingOffice2;

    protected static string|UnitEnum|null $navigationGroup = '4G NOC';

    protected static ?int $navigationSort = 5;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->label('Vendor name')->required()->maxLength(150)->unique(ignoreRecord: true),
            TextInput::make('code')->label('Vendor code')->required()->maxLength(30)->alphaDash()->unique(ignoreRecord: true)
                ->helperText('Short code, e.g. ATC. Sites whose tower company equals this code default to this vendor.'),
            Select::make('vendor_type')->label('Vendor type')->options(Vendor::TYPES)->default('power')->required(),
            Section::make('Contact — used for the automatic vendor e-mails')->schema([
                TextInput::make('contact_person')->maxLength(150),
                TextInput::make('phone')->label('Telephone number')->tel()->maxLength(50),
                TextInput::make('email')->label('E-mail address')->email()->maxLength(255)
                    ->helperText('Without an address the vendor notification cannot be sent.'),
                TagsInput::make('cc_emails')->label('CC e-mail addresses')->nestedRecursiveRules(['email'])->placeholder('name@vendor.com')
                    ->splitKeys(['Tab', ',', ' ']),
            ])->columns(2)->columnSpanFull(),
            AuditFields::description(),
            AuditFields::activeToggle()->helperText('Inactive vendors stay on past tickets but cannot be selected for a new one.'),
        ])->columns(3);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')->searchable()->sortable()->weight('bold'),
                TextColumn::make('code')->badge()->color('gray')->searchable(),
                TextColumn::make('vendor_type')->label('Type')->formatStateUsing(fn (Vendor $record) => $record->typeLabel())->badge(),
                TextColumn::make('contact_person')->label('Contact')->placeholder('—')->description(fn (Vendor $record) => $record->phone)->visibleFrom('md'),
                TextColumn::make('email')->placeholder('No e-mail address')->color(fn (Vendor $record) => $record->email ? null : 'danger')
                    ->description(fn (Vendor $record) => $record->ccList() ? 'CC '.implode(', ', $record->ccList()) : null)->wrap(),
                TextColumn::make('enodebs_count')->counts('enodebs')->label('Sites')->visibleFrom('lg'),
                TextColumn::make('open_outages_count')->label('Open tickets')->counts(['outages as open_outages_count' => fn ($q) => $q->where('is_closed', false)])
                    ->badge()->color(fn (int $state) => $state > 0 ? 'warning' : 'gray'),
                AuditFields::activeColumn(),
                ...AuditFields::columns(),
            ])
            ->filters([SelectFilter::make('vendor_type')->options(Vendor::TYPES), AuditFields::activeFilter()])
            ->recordActions([EditAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageVendors::route('/')];
    }
}
