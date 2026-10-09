<?php

namespace App\Filament\Resources\AuditLogs;

use App\Filament\Concerns\ReadOnlyResource;
use App\Filament\Resources\AuditLogs\Pages\ManageAuditLogs;
use App\Models\AuditLog;
use App\Support\AuditContext;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Infolists\Components\KeyValueEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use UnitEnum;

class AuditLogResource extends Resource
{
    use ReadOnlyResource;

    protected static ?string $model = AuditLog::class;

    protected static ?string $navigationLabel = 'Audit Trail';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedFingerPrint;

    protected static string|UnitEnum|null $navigationGroup = 'Monitoring';

    protected static ?int $navigationSort = 2;

    public static function canViewAny(): bool
    {
        return (bool) Auth::user()?->can('audit.view');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function infolist(Schema $schema): Schema
    {
        $flatten = fn (?array $values) => collect($values ?? [])->map(fn ($v) => is_scalar($v) || $v === null ? (string) $v : json_encode($v))->all();

        return $schema->components([
            TextEntry::make('created_at')->dateTime('Y-m-d H:i:s'),
            TextEntry::make('user.name')->label('User')->placeholder('System'),
            TextEntry::make('event')->badge()->formatStateUsing(fn (AuditLog $record) => $record->eventLabel()),
            TextEntry::make('subject')->state(fn (AuditLog $record) => $record->subjectLabel()),
            TextEntry::make('trigger')->label('Done from')->formatStateUsing(fn (?string $state) => AuditContext::label($state)),
            TextEntry::make('ip_address')->placeholder('—'),
            TextEntry::make('url')->columnSpanFull()->placeholder('—'),
            KeyValueEntry::make('old_values')->label('Before')->state(fn (AuditLog $record) => $flatten($record->old_values))->columnSpan(3),
            KeyValueEntry::make('new_values')->label('After')->state(fn (AuditLog $record) => $flatten($record->new_values))->columnSpan(3),
        ])->columns(6);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->modifyQueryUsing(fn (Builder $query) => $query->with('user:id,name'))
            ->columns([
                TextColumn::make('created_at')->dateTime('Y-m-d H:i:s')->sortable(),
                TextColumn::make('user.name')->label('User')->placeholder('System')->searchable(),
                TextColumn::make('auditable_type')->label('Record')->formatStateUsing(fn (string $state, AuditLog $record) => $record->subjectLabel()),
                TextColumn::make('event')->label('Action')->badge()->formatStateUsing(fn (AuditLog $record) => $record->eventLabel())
                    ->color(fn (string $state) => match (true) {
                        $state === 'created' => 'success',
                        $state === 'deleted' => 'danger',
                        str_starts_with($state, AuditLog::MOBILITY_PREFIX), str_starts_with($state, AuditLog::TICKET_PREFIX) => 'warning',
                        default => 'info',
                    }),
                TextColumn::make('changes')->label('What')->state(fn (AuditLog $record) => $record->changeLines())
                    ->listWithLineBreaks()->limitList(3)->expandableLimitedList()->wrap()->visibleFrom('lg'),
                TextColumn::make('trigger')->label('Done from')->badge()->formatStateUsing(fn (?string $state) => AuditContext::label($state))
                    ->color(fn (?string $state) => $state === AuditContext::MOBILE ? 'warning' : 'gray'),
                TextColumn::make('ip_address')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('auditable_type')->label('Record type')
                    ->options(fn () => AuditLog::query()->distinct()->orderBy('auditable_type')->pluck('auditable_type')
                        ->mapWithKeys(fn ($type) => [$type => class_basename($type)])->all()),
                // 4G NOC: ticket actions and changes, vendors, vendor updates, attachments, reopenings, sites,
                // transmission nodes, severity rules, vendor templates and the 4G settings.
                // Customer Care: ticket and site visit actions, customers, and the ticketing configuration.
                SelectFilter::make('scope')->label('Module')->options(['mobility' => '4G NOC', 'ticketing' => 'Customer tickets & site visits', 'other' => 'Everything else'])
                    ->query(fn (Builder $query, array $data) => match ($data['value'] ?? null) {
                        'mobility' => $query->mobility(),
                        'ticketing' => $query->ticketing(),
                        'other' => $query->whereNotIn('id', AuditLog::query()->mobility()->select('id'))->whereNotIn('id', AuditLog::query()->ticketing()->select('id')),
                        default => $query,
                    }),
                SelectFilter::make('event')->label('Action')->multiple()
                    ->options(fn () => AuditLog::query()->distinct()->orderBy('event')->pluck('event')
                        ->mapWithKeys(fn (string $event) => [$event => AuditLog::labelFor($event)])->all()),
                SelectFilter::make('user_id')->relationship('user', 'name')->searchable()->label('User'),
                // Where it was done: on the website, in the mobile app, or by the system itself.
                SelectFilter::make('source')->label('Done from')->options(['mobile' => 'Mobile app', 'user' => 'Website', 'system' => 'System (automatic)'])
                    ->query(fn (Builder $query, array $data) => match ($data['value'] ?? null) {
                        'mobile' => $query->where('trigger', AuditContext::MOBILE),
                        'user' => $query->where('trigger', 'user'),
                        'system' => $query->where('trigger', 'like', 'system%'),
                        default => $query,
                    }),
                Filter::make('period')->schema([DatePicker::make('from'), DatePicker::make('until')])
                    ->query(fn (Builder $query, array $data) => $query
                        ->when($data['from'] ?? null, fn ($q, $d) => $q->where('created_at', '>=', $d))
                        ->when($data['until'] ?? null, fn ($q, $d) => $q->where('created_at', '<=', $d.' 23:59:59'))),
            ])
            ->recordActions([ViewAction::make()->modalWidth('5xl')]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageAuditLogs::route('/')];
    }
}
