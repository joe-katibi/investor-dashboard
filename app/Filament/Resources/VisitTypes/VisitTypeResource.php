<?php

namespace App\Filament\Resources\VisitTypes;

use App\Filament\Concerns\GuardsWithPermission;
use App\Filament\Resources\VisitTypes\Pages\ManageVisitTypes;
use App\Filament\Support\AuditFields;
use App\Models\Priority;
use App\Models\Vertical;
use App\Models\VisitType;
use App\Support\VisitPhotos;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

/** Kinds of site visit. The prefix numbers the visits (INS-1, WiE-1, SUP-1, SHI-1), each with its own counter. */
class VisitTypeResource extends Resource
{
    use GuardsWithPermission;

    protected static string $permission = 'ticket.configure';

    protected static ?string $model = VisitType::class;

    protected static ?string $slug = 'customer-care/visit-types';

    protected static ?string $navigationLabel = 'Visit Types';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMapPin;

    protected static string|UnitEnum|null $navigationGroup = 'Customer Care';

    protected static ?int $navigationSort = 23;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->required()->maxLength(120)->unique(ignoreRecord: true),
            TextInput::make('code_prefix')->label('Number prefix')->required()->maxLength(10)->alphaDash()->unique(ignoreRecord: true)
                ->helperText('Visits are numbered PREFIX-1, PREFIX-2… Changing it starts a new series.'),
            TextInput::make('default_sla_minutes')->label('SLA target (minutes)')->numeric()->minValue(1)->maxValue(100000)->placeholder('Use the default')
                ->helperText('Counted on the ticket SLA clock. 180 = 3 hours, 4320 = 72 hours.'),
            Select::make('priority_id')->label('Priority')->placeholder('None')
                ->options(fn () => Priority::query()->active()->where('vertical_id', Vertical::query()->where('code', Vertical::FTTH)->value('id'))->orderBy('queue_rank')->pluck('name', 'id')),
            Toggle::make('requires_evidence')->label('Needs evidence to complete')->default(true)
                ->helperText('The FAT number and the photos below before the technician can complete the visit.'),
            Select::make('photo_set')->label('Photos to complete')->options(VisitPhotos::SETS)->default(VisitPhotos::STANDARD)->selectablePlaceholder(false)->required()
                ->helperText('The installation set: FAT outside (geotagged), inside and its power; cable entry (geotagged); ATB and ONU mounted; power at the ATB; ONU serial number; speed tests; devices working. Taken with the camera in the mobile app only.'),
            Select::make('technician_permission')->label('Technicians')->options(VisitType::TECHNICIAN_PERMISSIONS)->placeholder('Every site visit technician')
                ->helperText('Kept for some technicians: only those given the permission (Roles, or a person\'s own permissions) are offered these visits, by name or through the technician queue.'),
            Select::make('dispatcher_permission')->label('Dispatchers')->options(VisitType::DISPATCHER_PERMISSIONS)->placeholder('Every site visit dispatcher')
                ->helperText('Kept for some dispatchers: only those given the permission receive these visits in the dispatch queue and may assign, reassign, reschedule, relocate and verify them.'),
            Toggle::make('is_relocation')->label('Relocation (Shifting)')
                ->helperText('The technician sends the visit to the FTTH NOC for provisioning before the speed tests and the smart-device photos; the dispatcher can move it to another OLT / region.'),
            TextInput::make('sort_order')->label('Order in lists')->numeric()->default(0)->minValue(0)->maxValue(999),
            AuditFields::description(),
            AuditFields::activeToggle(),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('sort_order')
            ->columns([
                TextColumn::make('name')->searchable()->sortable()->weight('bold'),
                TextColumn::make('code_prefix')->label('Prefix')->badge()->color('info'),
                TextColumn::make('default_sla_minutes')->label('SLA (min)')->placeholder('Default'),
                TextColumn::make('priority.name')->label('Priority')->placeholder('—')->visibleFrom('md'),
                IconColumn::make('requires_evidence')->label('Evidence')->boolean(),
                IconColumn::make('is_relocation')->label('Relocation')->boolean()->visibleFrom('md'),
                TextColumn::make('photo_set')->label('Photos')->badge()->color(fn (?string $state) => $state === VisitPhotos::INSTALLATION ? 'warning' : 'gray')
                    ->formatStateUsing(fn (?string $state) => $state === VisitPhotos::INSTALLATION ? '9 photos (app only)' : '2 photos'),
                TextColumn::make('technician_permission')->label('Technicians')->badge()->color('warning')->placeholder('All')
                    ->formatStateUsing(fn (?string $state) => VisitType::TECHNICIAN_PERMISSIONS[$state] ?? $state),
                TextColumn::make('dispatcher_permission')->label('Dispatchers')->badge()->color('warning')->placeholder('All')
                    ->formatStateUsing(fn (?string $state) => VisitType::DISPATCHER_PERMISSIONS[$state] ?? $state),
                TextColumn::make('description')->wrap()->placeholder('—')->visibleFrom('lg'),
                AuditFields::activeColumn(),
                ...AuditFields::columns(),
            ])
            ->filters([AuditFields::activeFilter()])
            ->recordActions([EditAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageVisitTypes::route('/')];
    }
}
