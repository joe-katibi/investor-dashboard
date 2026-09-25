<?php

namespace App\Filament\Resources\Teams;

use App\Enums\TeamType;
use App\Filament\Concerns\GuardsWithPermission;
use App\Filament\Resources\Teams\Pages\ManageTeams;
use App\Filament\Support\AuditFields;
use App\Models\Team;
use App\Models\User;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class TeamResource extends Resource
{
    use GuardsWithPermission;

    protected static string $permission = 'teams.manage';

    protected static ?string $model = Team::class;

    protected static ?string $navigationLabel = 'Teams & Sub-teams';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static string|UnitEnum|null $navigationGroup = 'People';

    protected static ?int $navigationSort = 2;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->label('Team name')->required()->maxLength(120),
            Select::make('parent_id')->label('Parent team (for sub-teams)')->live()
                ->options(fn (?Team $record) => Team::query()->topLevel()->when($record, fn ($q) => $q->whereKeyNot($record->id))->orderBy('name')->pluck('name', 'id'))
                ->helperText('Sub-teams inherit department and team type from their parent.'),
            Select::make('department_id')->relationship('department', 'name')->required(fn (Get $get) => ! $get('parent_id'))
                ->hidden(fn (Get $get) => (bool) $get('parent_id')),
            Select::make('team_type')->label('Team type')->options(TeamType::class)->default(TeamType::InHouse->value)
                ->required(fn (Get $get) => ! $get('parent_id'))->hidden(fn (Get $get) => (bool) $get('parent_id')),
            Select::make('leader_id')->label('Team leader')->options(fn () => User::query()->active()->orderBy('name')->pluck('name', 'id'))->searchable(),
            Select::make('manager_id')->label('Manager')->options(fn () => User::query()->active()->orderBy('name')->pluck('name', 'id'))->searchable(),
            Select::make('members')->relationship('members', 'name', fn (Builder $query) => $query->where('is_active', true))
                ->multiple()->searchable()->preload()->columnSpanFull(),
            Select::make('olts')->label('OLT coverage (optional)')->relationship('olts', 'name')->multiple()->preload()->columnSpanFull()
                ->helperText('When set, FIFO only offers outages on these OLTs to this team\'s members.'),
            AuditFields::description(),
            AuditFields::activeToggle(),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['parent:id,name', 'department:id,name', 'leader:id,name', 'manager:id,name']))
            ->columns([
                TextColumn::make('name')->searchable()->sortable()
                    ->description(fn (Team $record) => $record->parent ? 'Sub-team of '.$record->parent->name : null),
                TextColumn::make('department.name')->sortable(),
                TextColumn::make('team_type')->badge(),
                TextColumn::make('leader.name')->label('Leader')->placeholder('—'),
                TextColumn::make('manager.name')->label('Manager')->placeholder('—'),
                TextColumn::make('members_count')->counts('members')->label('Members'),
                AuditFields::activeColumn(),
                ...AuditFields::columns(),
            ])
            ->filters([
                SelectFilter::make('department_id')->relationship('department', 'name'),
                SelectFilter::make('team_type')->options(TeamType::class),
                TernaryFilter::make('parent_id')->label('Level')->nullable()->trueLabel('Sub-teams')->falseLabel('Top-level teams'),
                AuditFields::activeFilter(),
            ])
            ->recordActions([EditAction::make()->modalWidth('4xl')]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageTeams::route('/')];
    }
}
