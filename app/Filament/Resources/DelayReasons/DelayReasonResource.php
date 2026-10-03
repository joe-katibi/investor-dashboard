<?php

namespace App\Filament\Resources\DelayReasons;

use App\Filament\Concerns\GuardsWithPermission;
use App\Filament\Resources\DelayReasons\Pages\ManageDelayReasons;
use App\Filament\Support\AuditFields;
use App\Models\DelayReason;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Why a ticket is / was late. "Within our control" marks the delays we could have prevented:
 * the hours lost on those are the preventable hours of the SLA reports.
 */
class DelayReasonResource extends Resource
{
    use GuardsWithPermission;

    protected static string $permission = 'ticket.configure';

    protected static ?string $model = DelayReason::class;

    protected static ?string $slug = 'customer-care/delay-reasons';

    protected static ?string $modelLabel = 'reason for delay';

    protected static ?string $pluralModelLabel = 'reasons for delay';

    protected static ?string $navigationLabel = 'Reasons for Delay';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClock;

    protected static string|UnitEnum|null $navigationGroup = 'Customer Care';

    protected static ?int $navigationSort = 25;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('code')->maxLength(10)->unique(ignoreRecord: true)->placeholder('D15')
                ->helperText('D01, D02, D03, D06 and D07 are set by the system (rescheduled, unreachable, not picking, outage, escalated to infrastructure).'),
            TextInput::make('name')->required()->maxLength(190)->unique(ignoreRecord: true),
            Toggle::make('within_control')->label('Within our control')->helperText('On: we could have prevented it (goes on the coaching list).'),
            TextInput::make('sort_order')->label('Order in lists')->numeric()->default(0)->minValue(0)->maxValue(999),
            Textarea::make('description')->label('What fixes it')->rows(2)->maxLength(2000)->columnSpanFull(),
            AuditFields::activeToggle(),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('sort_order')
            ->columns([
                TextColumn::make('code')->badge()->color('gray')->placeholder('—'),
                TextColumn::make('name')->searchable()->sortable()->weight('bold')->wrap(),
                IconColumn::make('within_control')->label('Within our control')->boolean(),
                TextColumn::make('description')->label('What fixes it')->wrap()->placeholder('—')->visibleFrom('md'),
                AuditFields::activeColumn(),
                ...AuditFields::columns(),
            ])
            ->filters([TernaryFilter::make('within_control')->label('Within our control'), AuditFields::activeFilter()])
            ->recordActions([EditAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageDelayReasons::route('/')];
    }
}
