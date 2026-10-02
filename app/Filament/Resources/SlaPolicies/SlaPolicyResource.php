<?php

namespace App\Filament\Resources\SlaPolicies;

use App\Filament\Concerns\GuardsWithPermission;
use App\Filament\Resources\SlaPolicies\Pages\ManageSlaPolicies;
use App\Filament\Support\AuditFields;
use App\Models\SlaPolicy;
use App\Models\User;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Spatie\Permission\Models\Role;
use UnitEnum;

class SlaPolicyResource extends Resource
{
    use GuardsWithPermission;

    protected static string $permission = 'sla.manage';

    protected static ?string $model = SlaPolicy::class;

    protected static ?string $modelLabel = 'SLA policy';

    protected static ?string $pluralModelLabel = 'SLA policies';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClock;

    protected static string|UnitEnum|null $navigationGroup = 'Configuration';

    protected static ?int $navigationSort = 20;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        $roles = fn () => Role::query()->orderBy('name')->pluck('name', 'name');

        return $schema->components([
            TextInput::make('name')->required()->maxLength(150)->columnSpanFull(),
            Section::make('Applies to')
                ->description('Leave a matcher empty to match any value. The most specific active policy wins. FTTH and 4G have their own priorities, so a policy on an FTTH priority never applies to a 4G ticket and the other way round; a 4G policy can also be narrowed to one outage type (eNodeB / Transmission / Power) or one vendor.')
                ->schema([
                    Select::make('major_outage_type_id')->relationship('majorOutageType', 'name')->label('Major type')->preload(),
                    Select::make('outage_type_id')->relationship('outageType', 'name')->label('Outage type')->preload(),
                    Select::make('vertical_id')->relationship('vertical', 'name')->preload(),
                    Select::make('priority_id')->label('Priority / severity')->relationship('priority', 'name')->preload(),
                    Select::make('service_id')->relationship('service', 'name')->searchable()->preload(),
                    Select::make('vendor_id')->label('Vendor (4G vendor tickets)')->relationship('vendor', 'name')->preload(),
                ])->columns(3)->columnSpanFull(),
            Section::make('Clock')->schema([
                TextInput::make('resolution_minutes')->label('Resolution target (min)')->numeric()->minValue(1)->required(),
                TextInput::make('pre_breach_minutes')->label('Pre-breach warning (min before)')->numeric()->minValue(0)->default(30)->required(),
                TextInput::make('ack_timeout_minutes')->label('FIFO acknowledgement timeout (min)')->numeric()->minValue(0)
                    ->helperText('Overrides the queue / global timeout for outages on this policy.'),
                Toggle::make('allow_pause')->label('Statuses flagged "pauses SLA" may pause this clock')->default(true),
            ])->columns(2)->columnSpanFull(),
            Section::make('Notifications & escalation')->schema([
                Select::make('notify_roles')->label('Notify roles')->multiple()->options($roles),
                Select::make('notify_user_ids')->label('Notify users')->multiple()->searchable()
                    ->options(fn () => User::query()->active()->orderBy('name')->pluck('name', 'id')),
                TagsInput::make('notify_emails')->label('Notify email addresses')->nestedRecursiveRules(['email']),
                TextInput::make('escalation_minutes')->label('Escalate after (min from start)')->numeric()->minValue(1),
                Select::make('escalation_roles')->label('Escalate to roles')->multiple()->options($roles),
                Toggle::make('notify_credit')->label('Credit-due notification on breach')->default(true),
                Toggle::make('notify_sms')->label('SMS tracking reminders')->default(true),
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
                TextColumn::make('majorOutageType.name')->label('Major')->placeholder('Any'),
                TextColumn::make('outageType.name')->label('Type')->placeholder('Any'),
                TextColumn::make('vertical.name')->placeholder('Any'),
                TextColumn::make('priority.name')->placeholder('Any')->badge(),
                TextColumn::make('vendor.name')->label('Vendor')->placeholder('Any')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('resolution_minutes')->label('Target')->formatStateUsing(fn (int $state) => $state >= 60 ? round($state / 60, 1).'h' : $state.'m'),
                TextColumn::make('pre_breach_minutes')->label('Pre-breach')->suffix('m'),
                TextColumn::make('ack_timeout_minutes')->label('Ack')->suffix('m')->placeholder('default'),
                AuditFields::activeColumn(),
                ...AuditFields::columns(),
            ])
            ->recordActions([EditAction::make()->modalWidth('5xl')]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageSlaPolicies::route('/')];
    }
}
