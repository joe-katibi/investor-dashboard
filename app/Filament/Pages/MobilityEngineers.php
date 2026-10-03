<?php

namespace App\Filament\Pages;

use App\Enums\QueueType;
use App\Filament\Resources\MobilityOutages\MobilityOutageResource;
use App\Models\OutageType;
use App\Models\User;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use UnitEnum;

/**
 * The people 4G tickets can be assigned to (everyone holding the "receive 4G tickets as engineer /
 * technician" permission), with their availability and current load. Accounts and roles are
 * managed under People → Users.
 */
class MobilityEngineers extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedWrenchScrewdriver;

    protected static string|UnitEnum|null $navigationGroup = '4G NOC';

    protected static ?int $navigationSort = 3;

    protected static ?string $slug = '4g/engineers';

    protected static ?string $navigationLabel = 'Engineers / Technicians';

    protected static ?string $title = '4G engineers & technicians';

    protected string $view = 'filament.pages.my-work';

    public static function canAccess(): bool
    {
        return (bool) Auth::user()?->can('mobility.view');
    }

    public function getSubheading(): ?string
    {
        return '4G Engineers receive transmission outages, 4G Technicians receive eNodeB outages (see “Handles”). Add or change people under People → Users; what a role receives is set under People → Roles.';
    }

    public function table(Table $table): Table
    {
        $internal = fn ($query) => $query->where('workflow', OutageType::WORKFLOW_MOBILITY_INTERNAL);

        return $table
            ->query(fn (): Builder => User::permission(QueueType::MobilityEngineer->defaultPermission())
                ->with(['roles:id,name', 'roles.permissions:id,name', 'permissions:id,name', 'department:id,name'])
                ->withCount([
                    'activeAssignments',
                    'technicianOutages as open_tickets_count' => fn ($q) => $internal($q)->where('is_closed', false),
                    'technicianOutages as resolved_30_count' => fn ($q) => $internal($q)->whereNotNull('restored_at')->where('restored_at', '>=', now()->subDays(30)),
                ]))
            ->defaultSort('name')
            ->recordUrl(fn (User $record) => MobilityOutageResource::getUrl('index', ['tab' => 'all', 'filters' => ['technician_id' => ['value' => $record->id]]]))
            ->emptyStateHeading('No 4G engineers or technicians yet')
            ->emptyStateDescription('Give a user the “4G Engineer” or “4G Technician” role under People → Users.')
            ->columns([
                TextColumn::make('name')->searchable()->sortable()->weight('bold')
                    ->description(fn (User $record) => $record->email),
                TextColumn::make('roles.name')->label('Role')->badge()->color('gray'),
                // Which outages FIFO gives this person (and the NOC may assign to them).
                TextColumn::make('handles')->label('Handles')->badge()->color('info')->placeholder('Nothing — no outage type')
                    ->state(fn (User $record) => collect(OutageType::HANDLERS)
                        ->filter(fn (array $handler) => $record->checkPermissionTo($handler[0]))
                        ->keys()->map(fn (string $domain) => OutageType::FAULT_DOMAINS[$domain])->all()),
                TextColumn::make('department.name')->label('Department')->placeholder('—')->visibleFrom('lg'),
                TextColumn::make('phone')->placeholder('—')->visibleFrom('md'),
                TextColumn::make('availability_status')->label('Availability')->badge(),
                TextColumn::make('is_active')->label('Account')->badge()
                    ->formatStateUsing(fn (bool $state) => $state ? 'Active' : 'Inactive')->color(fn (bool $state) => $state ? 'success' : 'gray'),
                TextColumn::make('open_tickets_count')->label('Open tickets')->badge()->color(fn (int $state) => $state > 0 ? 'warning' : 'gray')->sortable(),
                TextColumn::make('resolved_30_count')->label('Resolved (30 days)')->sortable()->visibleFrom('md'),
                TextColumn::make('last_assigned_at')->label('Last assigned')->since()->placeholder('Never')->visibleFrom('lg'),
            ]);
    }
}
