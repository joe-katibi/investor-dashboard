<?php

namespace App\Filament\Resources\DeskRoutes;

use App\Enums\QueueType;
use App\Filament\Concerns\GuardsWithPermission;
use App\Filament\Resources\DeskRoutes\Pages\ManageDeskRoutes;
use App\Filament\Support\AuditFields;
use App\Models\DeskRoute;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Validation\Rules\Unique;
use UnitEnum;

/**
 * Which desk may hand a ticket to which other desk ("Escalate / hand over" only offers these).
 * Returning a ticket to the desk it came from needs no route.
 */
class DeskRouteResource extends Resource
{
    use GuardsWithPermission;

    protected static string $permission = 'ticket.configure';

    protected static bool $allowsDelete = true;

    protected static ?string $model = DeskRoute::class;

    protected static ?string $slug = 'customer-care/desk-routes';

    protected static ?string $navigationLabel = 'Desk Routes';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowsRightLeft;

    protected static string|UnitEnum|null $navigationGroup = 'Customer Care';

    protected static ?int $navigationSort = 26;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('from_desk')->label('From desk')->options(QueueType::deskOptions())->required(),
            Select::make('to_desk')->label('May send to')->options(QueueType::deskOptions())->required()->different('from_desk')
                ->unique(ignoreRecord: true, modifyRuleUsing: fn (Unique $rule, Get $get) => $rule->where('from_desk', $get('from_desk') instanceof QueueType ? $get('from_desk')->value : $get('from_desk')))
                ->validationMessages(['unique' => 'This route already exists.']),
            TextInput::make('label')->label('Label in the menu (optional)')->maxLength(120)->placeholder('The name of the desk'),
            TextInput::make('sort_order')->label('Order in the menu')->numeric()->default(0)->minValue(0)->maxValue(999),
            AuditFields::activeToggle()->helperText('An inactive route is no longer offered.'),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('from_desk')
            ->columns([
                TextColumn::make('from_desk')->label('From desk')->badge()->sortable(),
                TextColumn::make('to_desk')->label('May send to')->badge(),
                TextColumn::make('label')->placeholder('—')->visibleFrom('md'),
                TextColumn::make('sort_order')->label('Order')->visibleFrom('md'),
                AuditFields::activeColumn(),
                ...AuditFields::columns(),
            ])
            ->filters([
                SelectFilter::make('from_desk')->label('From desk')->options(QueueType::deskOptions()),
                AuditFields::activeFilter(),
            ])
            ->recordActions([EditAction::make(), DeleteAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageDeskRoutes::route('/')];
    }
}
