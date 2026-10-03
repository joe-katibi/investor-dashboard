<?php

namespace App\Filament\Resources\Users;

use App\Enums\AvailabilityStatus;
use App\Filament\Concerns\GuardsWithPermission;
use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Filament\Resources\Users\RelationManagers\AvailabilityHistoryRelationManager;
use App\Filament\Resources\Users\RelationManagers\LeavesRelationManager;
use App\Filament\Resources\Users\RelationManagers\ShiftAssignmentsRelationManager;
use App\Filament\Resources\Users\RelationManagers\SkillsRelationManager;
use App\Filament\Support\AuditFields;
use App\Models\User;
use App\Services\Fifo\FifoAssignmentEngine;
use App\Services\Notifications\Sms\PhoneNumber;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password;
use UnitEnum;

class UserResource extends Resource
{
    use GuardsWithPermission;

    protected static string $permission = 'users.manage';

    protected static ?string $viewPermission = 'workforce.manage';

    protected static ?string $model = User::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static string|UnitEnum|null $navigationGroup = 'People';

    protected static ?int $navigationSort = 3;

    protected static ?string $recordTitleAttribute = 'name';

    public static function getGloballySearchableAttributes(): array
    {
        return ['name', 'username', 'email'];
    }

    public static function canEdit($record): bool
    {
        return static::allows('users.manage') || static::allows('workforce.manage');
    }

    public static function form(Schema $schema): Schema
    {
        $canManageUsers = fn () => (bool) Auth::user()?->can('users.manage');

        return $schema->components([
            Section::make('Account')->schema([
                TextInput::make('name')->required()->maxLength(255),
                TextInput::make('username')->required()->maxLength(60)->alphaDash()->unique(ignoreRecord: true),
                TextInput::make('email')->email()->required()->maxLength(255)->unique(ignoreRecord: true),
                TextInput::make('phone')->tel()->maxLength(30)
                    ->placeholder('+256772123456')
                    ->helperText('Used for SMS alerts. Local numbers (0772…) are stored in international format.')
                    ->rule(fn () => function (string $attribute, mixed $value, \Closure $fail): void {
                        if (filled($value) && ! PhoneNumber::isValid((string) $value)) {
                            $fail('Enter a valid phone number, e.g. +256772123456.');
                        }
                    })
                    ->dehydrateStateUsing(fn (?string $state) => PhoneNumber::normalise($state)),
                TextInput::make('password')->password()->revealable()
                    ->rule(Password::defaults())
                    ->required(fn (string $operation) => $operation === 'create')
                    ->dehydrated(fn (?string $state) => filled($state))
                    ->helperText(fn (string $operation) => $operation === 'edit' ? 'Leave blank to keep the current password.' : null),
                Toggle::make('is_active')->label('Active')->default(true)
                    ->disabled(fn (?User $record) => $record?->is(Auth::user()))
                    ->helperText('Inactive users cannot sign in and are excluded from FIFO.'),
            ])->columns(2)->disabled(fn () => ! $canManageUsers()),
            Section::make('Organisation')->schema([
                Select::make('department_id')->relationship('department', 'name')->preload(),
                Select::make('roles')->relationship('roles', 'name')->multiple()->preload()
                    ->disabled(fn (?User $record) => ! $canManageUsers() || $record?->is(Auth::user()))
                    ->helperText('Roles are bundles of permissions (People → Roles & Permissions).'),
                Select::make('teams')->relationship('teams', 'name')->multiple()->preload()->searchable()->columnSpanFull(),
                TextInput::make('max_active_assignments')->label('Max active FIFO tickets')->numeric()->minValue(0)
                    ->placeholder('Global default'),
                AuditFields::description(),
            ])->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['department:id,name', 'roles:id,name', 'teams:id,name'])->withCount('activeAssignments'))
            ->columns([
                TextColumn::make('name')->searchable()->sortable()->description(fn (User $record) => $record->username),
                TextColumn::make('email')->searchable()->toggleable(),
                TextColumn::make('department.name')->sortable(),
                TextColumn::make('roles.name')->badge()->color('gray'),
                TextColumn::make('teams.name')->badge()->color('info')->limitList(2)->toggleable(),
                TextColumn::make('availability_status')->label('Availability')->badge()->sortable(),
                TextColumn::make('active_assignments_count')->label('Active tickets')->sortable(),
                TextColumn::make('last_login_at')->label('Last login')->since()->sortable()->toggleable(),
                AuditFields::activeColumn(),
                ...AuditFields::columns(),
            ])
            ->filters([
                SelectFilter::make('department_id')->relationship('department', 'name'),
                SelectFilter::make('roles')->relationship('roles', 'name')->multiple(),
                SelectFilter::make('availability_status')->options(AvailabilityStatus::class),
                AuditFields::activeFilter(),
            ])
            ->recordActions([
                EditAction::make(),
                ActionGroup::make([
                    Action::make('availability')
                        ->label('Set availability')
                        ->icon(Heroicon::OutlinedSignal)
                        ->visible(fn () => Auth::user()?->can('workforce.manage'))
                        ->schema([
                            Select::make('status')->options(AvailabilityStatus::class)->required(),
                            TextInput::make('reason')->maxLength(255),
                        ])
                        ->fillForm(fn (User $record) => ['status' => $record->availability_status])
                        ->action(function (User $record, array $data): void {
                            // An enum-backed Select hands back the enum itself, not its value.
                            $status = $data['status'] instanceof AvailabilityStatus ? $data['status'] : AvailabilityStatus::from((string) $data['status']);
                            app(FifoAssignmentEngine::class)->setAvailability($record, $status, Auth::user(), $data['reason'] ?? 'Set by manager');
                            Notification::make()->title('Availability updated')->success()->send();
                        }),
                    Action::make('resetMfa')
                        ->label('Reset MFA')
                        ->icon(Heroicon::OutlinedKey)
                        ->color('danger')
                        ->requiresConfirmation()
                        ->visible(fn (User $record) => Auth::user()?->can('users.manage') && filled($record->app_authentication_secret))
                        ->action(function (User $record): void {
                            $record->forceFill(['app_authentication_secret' => null, 'app_authentication_recovery_codes' => null])->save();
                            Notification::make()->title('Authenticator reset — the user must enrol again')->success()->send();
                        }),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            SkillsRelationManager::class,
            ShiftAssignmentsRelationManager::class,
            LeavesRelationManager::class,
            AvailabilityHistoryRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListUsers::route('/'),
            'create' => CreateUser::route('/create'),
            'edit' => EditUser::route('/{record}/edit'),
        ];
    }
}
