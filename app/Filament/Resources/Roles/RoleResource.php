<?php

namespace App\Filament\Resources\Roles;

use App\Filament\Concerns\GuardsWithPermission;
use App\Filament\Resources\Roles\Pages\ManageRoles;
use App\Support\ActivityLog;
use App\Support\Permissions;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use UnitEnum;

/**
 * Roles are editable permission bundles. Access checks throughout the app use permissions,
 * never role names (except the Super Admin gate bypass).
 */
class RoleResource extends Resource
{
    use GuardsWithPermission;

    protected static string $permission = 'roles.manage';

    protected static ?string $model = Role::class;

    protected static ?string $navigationLabel = 'Roles & Permissions';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldCheck;

    protected static string|UnitEnum|null $navigationGroup = 'People';

    protected static ?int $navigationSort = 4;

    protected static ?string $recordTitleAttribute = 'name';

    public static function canEdit(Model $record): bool
    {
        return $record->name !== 'Super Admin' && static::allows('roles.manage');
    }

    public static function form(Schema $schema): Schema
    {
        $sections = [];

        foreach (Permissions::GROUPS as $group => $permissions) {
            $sections[] = Section::make($group)->schema([
                CheckboxList::make('permissions_'.str($group)->slug('_'))
                    ->hiddenLabel()
                    ->options($permissions)
                    ->columns(2)
                    ->bulkToggleable()
                    ->afterStateHydrated(function (CheckboxList $component, ?Role $record) use ($permissions): void {
                        $component->state($record ? $record->permissions->pluck('name')->intersect(array_keys($permissions))->values()->all() : []);
                    }),
            ])->collapsible()->compact();
        }

        return $schema->components([
            TextInput::make('name')->required()->maxLength(125)->unique(ignoreRecord: true),
            ...$sections,
        ]);
    }

    /** Collect the per-group checkbox lists into a single permission sync. */
    public static function syncPermissions(Role $role, array $state): void
    {
        $selected = collect(Permissions::GROUPS)->keys()
            ->flatMap(fn (string $group) => $state['permissions_'.str($group)->slug('_')] ?? [])
            ->intersect(Permissions::all())
            ->values()
            ->all();

        $before = $role->permissions()->pluck('name')->all();
        $role->syncPermissions(Permission::query()->whereIn('name', $selected)->get());

        // Who gave or took which permission: the roles decide what everyone may do.
        $added = array_values(array_diff($selected, $before));
        $removed = array_values(array_diff($before, $selected));

        if ($added !== [] || $removed !== []) {
            ActivityLog::record($role, 'permissions_changed', array_filter([
                'role' => $role->name, 'added' => implode(', ', $added) ?: null, 'removed' => implode(', ', $removed) ?: null,
                'permissions_now' => count($selected),
            ]), ['permissions_before' => count($before)]);
        }
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('permissions_count')->counts('permissions')->label('Permissions'),
                TextColumn::make('users_count')->counts('users')->label('Users'),
                TextColumn::make('updated_at')->dateTime()->sortable(),
            ])
            ->recordActions([
                EditAction::make()->modalWidth('4xl')
                    ->using(function (Role $record, array $data): Role {
                        $record->update(['name' => $data['name']]);
                        static::syncPermissions($record, $data);

                        return $record;
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageRoles::route('/')];
    }
}
