<?php

namespace App\Filament\Resources\FinalReasons;

use App\Filament\Concerns\GuardsWithPermission;
use App\Filament\Resources\FinalReasons\Pages\ManageFinalReasons;
use App\Filament\Support\AuditFields;
use App\Models\FinalReason;
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

/** The pick-list of final reasons a ticket or site visit is closed with. */
class FinalReasonResource extends Resource
{
    use GuardsWithPermission;

    protected static string $permission = 'ticket.configure';

    protected static ?string $model = FinalReason::class;

    protected static ?string $slug = 'customer-care/final-reasons';

    protected static ?string $navigationLabel = 'Final Reasons';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentCheck;

    protected static string|UnitEnum|null $navigationGroup = 'Customer Care';

    protected static ?int $navigationSort = 24;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->required()->maxLength(190)->unique(ignoreRecord: true)->columnSpanFull(),
            Select::make('applies_to')->label('Offered on')->options(FinalReason::APPLIES_TO)->placeholder('Customer tickets and site visits'),
            Select::make('delay_reason_id')->label('Reason for delay')->relationship('delayReason', 'name')->preload()->searchable()->placeholder('None')
                ->helperText('The delay this outcome normally comes with: pre-selected when a late ticket is closed with this reason.'),
            TextInput::make('sort_order')->label('Order in lists')->numeric()->default(0)->minValue(0)->maxValue(9999),
            AuditFields::activeToggle()->label('Status: active'),
            AuditFields::description(),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('delayReason:id,name'))
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')->searchable()->sortable()->weight('bold')->wrap(),
                TextColumn::make('description')->wrap()->placeholder('—')->visibleFrom('md'),
                TextColumn::make('applies_to')->label('Offered on')->badge()->color('gray')->placeholder('Tickets and visits')
                    ->formatStateUsing(fn (?string $state) => FinalReason::APPLIES_TO[$state] ?? $state),
                TextColumn::make('delayReason.name')->label('Reason for delay')->placeholder('—')->visibleFrom('md'),
                AuditFields::activeColumn()->label('Status'),
                ...AuditFields::columns(),
            ])
            ->filters([
                SelectFilter::make('applies_to')->label('Offered on')->options(FinalReason::APPLIES_TO),
                AuditFields::activeFilter(),
            ])
            ->recordActions([EditAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageFinalReasons::route('/')];
    }
}
