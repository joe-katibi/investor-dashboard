<?php

namespace App\Filament\Resources\TicketSubcategories;

use App\Enums\QueueType;
use App\Filament\Concerns\GuardsWithPermission;
use App\Filament\Resources\TicketSubcategories\Pages\ManageTicketSubcategories;
use App\Filament\Support\AuditFields;
use App\Models\Priority;
use App\Models\TicketSubcategory;
use App\Models\Vertical;
use App\Models\VisitType;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

/**
 * The issue reported, and how a ticket for it is routed: its first desk, the desk that approves
 * it and the desk that then carries it out. From the first desk on, people move a ticket by hand
 * along the desk routes (CX Tier II decides between NOC and a site visit after troubleshooting).
 */
class TicketSubcategoryResource extends Resource
{
    use GuardsWithPermission;

    protected static string $permission = 'ticket.configure';

    protected static ?string $model = TicketSubcategory::class;

    protected static ?string $slug = 'customer-care/subcategories';

    protected static ?string $modelLabel = 'subcategory';

    protected static ?string $pluralModelLabel = 'subcategories';

    protected static ?string $navigationLabel = 'Subcategories & Routing';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowsRightLeft;

    protected static string|UnitEnum|null $navigationGroup = 'Customer Care';

    protected static ?int $navigationSort = 22;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('ticket_category_id')->label('Category')->relationship('category', 'name')->required()->preload(),
            TextInput::make('name')->required()->maxLength(150),
            TextInput::make('code')->required()->maxLength(60)->unique(ignoreRecord: true)->alphaDash(),
            TextInput::make('sort_order')->label('Order in lists')->numeric()->default(0)->minValue(0)->maxValue(999),

            Section::make('Routing')->schema([
                Select::make('first_desk')->label('A new ticket goes to')->options(QueueType::deskOptions())->required()
                    ->helperText('The desk that picks the ticket up first (FIFO among the people of that desk).'),
                Select::make('approval_desk')->label('Approved by')->placeholder('No approval needed')
                    ->options(collect(QueueType::ticketing())->filter->isApprovalDesk()->mapWithKeys(fn (QueueType $desk) => [$desk->value => $desk->getLabel()])->all())
                    ->helperText('Refunds / credits: the desk that approves or declines. It can always be sent there, whatever the desk routes say.'),
                Select::make('fulfilment_desk')->label('Carried out by, once approved')->options(QueueType::deskOptions())->placeholder('Billing')
                    ->helperText('Where an approved request goes next.'),
            ])->columns(3)->columnSpanFull(),

            Section::make('Site visit, outage check and SLA')->schema([
                Toggle::make('allows_site_visit')->label('A site visit can be raised for it')->default(true),
                Select::make('visit_type_id')->label('Visit type by default')->placeholder('Chosen when the visit is raised')
                    ->options(fn () => VisitType::query()->orderBy('sort_order')->pluck('name', 'id')),
                Toggle::make('check_outage')->label('Check for an ongoing outage')
                    ->helperText('LOS-type issues: when the customer\'s OLT / FDT / slot / port is under an open outage, the agent is told and the ticket is linked to it.'),
                Select::make('priority_id')->label('Priority')->placeholder('None')
                    ->options(fn () => Priority::query()->active()->where('vertical_id', Vertical::query()->where('code', Vertical::FTTH)->value('id'))->orderBy('queue_rank')->pluck('name', 'id'))
                    ->helperText('Higher priorities are served first within a desk.'),
                TextInput::make('sla_minutes')->label('SLA target (minutes)')->numeric()->minValue(1)->maxValue(100000)->placeholder('Use the default')
                    ->helperText('Counted on the ticket SLA clock (working hours). Empty: the visit type\'s target, then the default in Ticket Settings.'),
            ])->columns(2)->columnSpanFull(),

            AuditFields::description(),
            AuditFields::activeToggle(),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['category:id,name', 'visitType:id,name', 'priority:id,name,color']))
            ->defaultSort('ticket_category_id')
            ->defaultGroup('category.name')
            ->columns([
                TextColumn::make('name')->searchable()->sortable()->weight('bold')->wrap(),
                TextColumn::make('category.name')->label('Category')->sortable()->toggleable(),
                TextColumn::make('first_desk')->label('First desk')->badge(),
                TextColumn::make('approval_desk')->label('Approved by')->badge()->placeholder('—')->visibleFrom('md'),
                TextColumn::make('fulfilment_desk')->label('Carried out by')->badge()->placeholder('—')->visibleFrom('lg'),
                TextColumn::make('visitType.name')->label('Visit type')->placeholder('—')->visibleFrom('md'),
                IconColumn::make('check_outage')->label('Outage check')->boolean()->visibleFrom('lg'),
                TextColumn::make('priority.name')->label('Priority')->badge()->color(fn (TicketSubcategory $record) => $record->priority?->color ?? 'gray')->placeholder('—')->visibleFrom('xl'),
                TextColumn::make('sla_minutes')->label('SLA (min)')->placeholder('Default')->visibleFrom('xl'),
                AuditFields::activeColumn(),
                ...AuditFields::columns(),
            ])
            ->filters([
                SelectFilter::make('ticket_category_id')->label('Category')->relationship('category', 'name'),
                SelectFilter::make('first_desk')->label('First desk')->options(QueueType::deskOptions()),
                AuditFields::activeFilter(),
            ])
            ->recordActions([EditAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageTicketSubcategories::route('/')];
    }
}
