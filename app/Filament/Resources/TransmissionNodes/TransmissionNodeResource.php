<?php

namespace App\Filament\Resources\TransmissionNodes;

use App\Filament\Concerns\GuardsWithPermission;
use App\Filament\Resources\TransmissionNodes\Pages\ManageTransmissionNodes;
use App\Filament\Support\AuditFields;
use App\Models\Enodeb;
use App\Models\TransmissionNode;
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

/** 4G transmission / backhaul nodes: RSG → ASG → CSG → SSG, each SSG carrying one eNodeB. */
class TransmissionNodeResource extends Resource
{
    use GuardsWithPermission;

    protected static string $permission = 'mobility.configure';

    protected static ?string $viewPermission = 'mobility.view';

    protected static ?string $model = TransmissionNode::class;

    protected static ?string $slug = '4g/transmission-nodes';

    protected static ?string $navigationLabel = 'Transmission Nodes';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShare;

    protected static string|UnitEnum|null $navigationGroup = '4G NOC';

    protected static ?int $navigationSort = 7;

    protected static ?string $recordTitleAttribute = 'code';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('type')->options(TransmissionNode::TYPES)->required()->live(),
            TextInput::make('code')->required()->maxLength(40)->unique(ignoreRecord: true)->placeholder('e.g. SSG-1111')
                ->helperText('Type and number, as in the hostname: ssg-1111-… → SSG-1111.'),
            TextInput::make('name')->required()->maxLength(190)->columnSpanFull(),
            Select::make('parent_id')->label('Parent node (one level up)')->searchable()
                ->options(fn () => TransmissionNode::query()->where('type', '!=', TransmissionNode::SSG)->orderBy('code')->get()
                    ->mapWithKeys(fn (TransmissionNode $node) => [$node->id => $node->label()]))
                ->helperText('SSG → its CSG, CSG → its ASG, ASG → its RSG.'),
            Select::make('enodeb_id')->label('eNodeB site carried (SSG)')->searchable()
                ->options(fn () => Enodeb::query()->orderBy('name')->get()->mapWithKeys(fn (Enodeb $site) => [$site->id => $site->label()])),
            TextInput::make('hostname')->maxLength(120)->unique(ignoreRecord: true),
            TextInput::make('management_ip')->label('Management IP')->ip(),
            TextInput::make('gateway_ip')->label('Gateway')->ip(),
            TextInput::make('equipment')->label('Equipment / script profile')->maxLength(60),
            TextInput::make('status_note')->label('Label / status note')->maxLength(250)->columnSpanFull(),
            AuditFields::description(),
            AuditFields::activeToggle()->helperText('Inactive nodes (not deployed) cannot be selected on a ticket and are not counted below their parent.'),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('code')
            ->modifyQueryUsing(fn ($query) => $query->with(['parent:id,code,name', 'enodeb:id,site_id,name']))
            ->columns([
                TextColumn::make('code')->searchable()->sortable()->weight('bold'),
                TextColumn::make('type')->badge()->color(fn (string $state) => match ($state) {
                    TransmissionNode::RSG => 'danger', TransmissionNode::ASG => 'warning', TransmissionNode::CSG => 'info', default => 'gray',
                }),
                TextColumn::make('name')->searchable()->wrap(),
                TextColumn::make('parent.code')->label('Parent')->placeholder('—')->visibleFrom('md'),
                TextColumn::make('enodeb.site_id')->label('Site')->placeholder('—')->visibleFrom('md')
                    ->description(fn (TransmissionNode $record) => $record->tower_company),
                TextColumn::make('hostname')->searchable()->placeholder('—')->visibleFrom('lg'),
                TextColumn::make('management_ip')->label('Mgmt IP')->searchable()->placeholder('—')->visibleFrom('lg')
                    ->description(fn (TransmissionNode $record) => $record->gateway_ip ? 'gw '.$record->gateway_ip : null),
                TextColumn::make('equipment')->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('status_note')->label('Note')->placeholder('—')->wrap()->visibleFrom('xl'),
                TextColumn::make('children_count')->counts('children')->label('Below')->toggleable()->visibleFrom('xl'),
                AuditFields::activeColumn(),
                ...AuditFields::columns(),
            ])
            ->filters([
                SelectFilter::make('type')->options(array_combine(array_keys(TransmissionNode::TYPES), array_keys(TransmissionNode::TYPES))),
                SelectFilter::make('parent_id')->label('Under (CSG / ASG / RSG)')->searchable()
                    ->options(fn () => TransmissionNode::query()->where('type', '!=', TransmissionNode::SSG)->orderBy('code')->get()
                        ->mapWithKeys(fn (TransmissionNode $node) => [$node->id => $node->label()])),
                AuditFields::activeFilter()->default(true),
            ])
            ->recordActions([EditAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageTransmissionNodes::route('/')];
    }
}
