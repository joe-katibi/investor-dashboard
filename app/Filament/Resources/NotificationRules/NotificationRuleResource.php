<?php

namespace App\Filament\Resources\NotificationRules;

use App\Enums\NotificationChannel;
use App\Enums\NotificationEvent;
use App\Filament\Concerns\GuardsWithPermission;
use App\Filament\Resources\NotificationRules\Pages\ManageNotificationRules;
use App\Filament\Support\AuditFields;
use App\Models\NotificationRule;
use App\Models\User;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Spatie\Permission\Models\Role;
use UnitEnum;

class NotificationRuleResource extends Resource
{
    use GuardsWithPermission;

    protected static string $permission = 'notifications.manage';

    protected static ?string $model = NotificationRule::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBellAlert;

    protected static string|UnitEnum|null $navigationGroup = 'Configuration';

    protected static ?int $navigationSort = 22;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->required()->maxLength(150),
            Select::make('event')->options(NotificationEvent::class)->required()->searchable(),
            Section::make('Conditions')->description('Empty = any.')->schema([
                Select::make('major_outage_type_id')->relationship('majorOutageType', 'name')->label('Major type'),
                Select::make('outage_type_id')->relationship('outageType', 'name')->label('Outage type'),
                Select::make('vertical_id')->relationship('vertical', 'name'),
                Select::make('priority_id')->relationship('priority', 'name'),
                Select::make('status_id')->relationship('status', 'name')->label('Status'),
            ])->columns(3)->columnSpanFull(),
            Section::make('Delivery')->schema([
                CheckboxList::make('channels')->options(NotificationChannel::class)->required()->columns(3)->columnSpanFull(),
                Select::make('recipient_roles')->label('Roles')->multiple()->options(fn () => Role::query()->orderBy('name')->pluck('name', 'name')),
                Select::make('recipient_user_ids')->label('Users')->multiple()->searchable()
                    ->options(fn () => User::query()->active()->orderBy('name')->pluck('name', 'id')),
                TagsInput::make('recipient_emails')->label('Extra email addresses')->nestedRecursiveRules(['email']),
                TagsInput::make('recipient_phones')->label('Extra SMS numbers'),
                Toggle::make('notify_assignee')->label('Current FIFO assignee'),
                Toggle::make('notify_team_leaders')->label('Team leaders / managers of the assigned team'),
            ])->columns(2)->columnSpanFull(),
            AuditFields::description(),
            AuditFields::activeToggle(),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->wrap(),
                TextColumn::make('event')->badge(),
                TextColumn::make('channels')->badge()->formatStateUsing(fn (string $state) => NotificationChannel::tryFrom($state)?->getLabel() ?? $state),
                TextColumn::make('recipient_roles')->label('Roles')->badge()->color('gray'),
                TextColumn::make('priority.name')->placeholder('Any'),
                AuditFields::activeColumn(),
                ...AuditFields::columns(),
            ])
            ->filters([SelectFilter::make('event')->options(NotificationEvent::class), AuditFields::activeFilter()])
            ->recordActions([EditAction::make()->modalWidth('4xl')]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageNotificationRules::route('/')];
    }
}
