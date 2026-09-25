<?php

namespace App\Filament\Resources\Outages\RelationManagers;

use App\Models\AuditLog;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class AuditLogsRelationManager extends RelationManager
{
    protected static string $relationship = 'auditLogs';

    protected static ?string $title = 'Audit trail';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return (bool) Auth::user()?->can('audit.view');
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->modifyQueryUsing(fn ($query) => $query->with('user:id,name'))
            ->columns([
                TextColumn::make('created_at')->label('When')->dateTime('d M Y H:i:s'),
                TextColumn::make('user.name')->label('User')->placeholder('System'),
                TextColumn::make('event')->badge(),
                TextColumn::make('trigger')->color('gray'),
                TextColumn::make('changes')->state(fn (AuditLog $record) => collect($record->new_values ?? [])
                    ->map(fn ($new, $key) => $key.': '.$this->fmt($record->old_values[$key] ?? null).' → '.$this->fmt($new))
                    ->values()->all())
                    ->listWithLineBreaks()->limitList(8)->expandableLimitedList()->wrap(),
            ]);
    }

    private function fmt(mixed $value): string
    {
        return $value === null ? '∅' : (is_scalar($value) ? (string) $value : json_encode($value));
    }
}
