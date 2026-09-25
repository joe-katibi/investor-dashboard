<?php

namespace App\Filament\Resources\AuditLogs;

use App\Filament\Resources\AuditLogs\Pages\ManageAuditLogs;
use App\Models\AuditLog;
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
            TextEntry::make('event')->badge(),
            TextEntry::make('subject')->state(fn (AuditLog $record) => $record->subjectLabel()),
            TextEntry::make('trigger'),
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
                TextColumn::make('event')->badge()->color(fn (string $state) => match ($state) {
                    'created' => 'success', 'deleted' => 'danger', default => 'info',
                }),
                TextColumn::make('trigger')->color('gray'),
                TextColumn::make('ip_address')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('auditable_type')->label('Record type')
                    ->options(fn () => AuditLog::query()->distinct()->orderBy('auditable_type')->pluck('auditable_type')
                        ->mapWithKeys(fn ($type) => [$type => class_basename($type)])->all()),
                SelectFilter::make('event')->options(['created' => 'Created', 'updated' => 'Updated', 'deleted' => 'Deleted', 'topology_updated' => 'Topology updated']),
                SelectFilter::make('user_id')->relationship('user', 'name')->searchable()->label('User'),
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
