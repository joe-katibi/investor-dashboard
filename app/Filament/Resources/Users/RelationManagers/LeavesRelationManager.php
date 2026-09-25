<?php

namespace App\Filament\Resources\Users\RelationManagers;

use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class LeavesRelationManager extends RelationManager
{
    protected static string $relationship = 'leaves';

    protected static ?string $title = 'Leave';

    public const STATUSES = ['approved' => 'Approved', 'pending' => 'Pending', 'rejected' => 'Rejected', 'cancelled' => 'Cancelled'];

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return (bool) Auth::user()?->can('workforce.manage');
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            DateTimePicker::make('starts_at')->required()->seconds(false),
            DateTimePicker::make('ends_at')->required()->seconds(false)->after('starts_at'),
            Select::make('leave_type')->options(['annual' => 'Annual', 'sick' => 'Sick', 'training' => 'Training', 'other' => 'Other'])->default('annual')->required(),
            Select::make('status')->options(self::STATUSES)->default('approved')->required()
                ->helperText('Users on approved leave are excluded from FIFO and their open tickets are requeued.'),
            TextInput::make('reason')->maxLength(255)->columnSpanFull(),
        ])->columns(2);
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('starts_at', 'desc')
            ->columns([
                TextColumn::make('starts_at')->dateTime('Y-m-d H:i'),
                TextColumn::make('ends_at')->dateTime('Y-m-d H:i'),
                TextColumn::make('leave_type')->badge()->color('gray'),
                TextColumn::make('status')->badge()->color(fn (string $state) => $state === 'approved' ? 'success' : 'gray'),
                TextColumn::make('reason')->placeholder('—')->limit(40),
            ])
            ->headerActions([
                CreateAction::make()->mutateDataUsing(fn (array $data) => $data + ['approved_by' => $data['status'] === 'approved' ? Auth::id() : null]),
            ])
            ->recordActions([EditAction::make(), DeleteAction::make()]);
    }
}
