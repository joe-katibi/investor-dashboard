<?php

namespace App\Filament\Resources\TicketCategories;

use App\Filament\Concerns\GuardsWithPermission;
use App\Filament\Resources\TicketCategories\Pages\ManageTicketCategories;
use App\Filament\Support\AuditFields;
use App\Filament\Support\VerticalFields;
use App\Models\TicketCategory;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

/** Top level of "issue reported": Technical Support, Billing, Refund, Credit… */
class TicketCategoryResource extends Resource
{
    use GuardsWithPermission;

    protected static string $permission = 'ticket.configure';

    protected static ?string $model = TicketCategory::class;

    protected static ?string $slug = 'customer-care/categories';

    protected static ?string $modelLabel = 'category';

    protected static ?string $navigationLabel = 'Categories';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTag;

    protected static string|UnitEnum|null $navigationGroup = 'Customer Care';

    protected static ?int $navigationSort = 21;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->required()->maxLength(150)->unique(ignoreRecord: true),
            VerticalFields::select('Raising a ticket of this vertical offers this category and its subcategories.'),
            TextInput::make('code')->required()->maxLength(40)->unique(ignoreRecord: true)->alphaDash(),
            TextInput::make('sort_order')->label('Order in lists')->numeric()->default(0)->minValue(0)->maxValue(999),
            AuditFields::activeToggle(),
            AuditFields::description(),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('sort_order')
            ->columns([
                TextColumn::make('name')->searchable()->sortable()->weight('bold'),
                TextColumn::make('code')->badge()->color('gray'),
                VerticalFields::column(),
                TextColumn::make('subcategories.name')->label('Subcategories')->badge()->color('info')->limitList(8)->wrap()->placeholder('None yet'),
                TextColumn::make('sort_order')->label('Order')->sortable()->visibleFrom('md'),
                AuditFields::activeColumn(),
                ...AuditFields::columns(),
            ])
            ->modifyQueryUsing(fn ($query) => $query->with('vertical:id,name,code'))
            ->filters([VerticalFields::filter(), AuditFields::activeFilter()])
            ->recordActions([EditAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageTicketCategories::route('/')];
    }
}
