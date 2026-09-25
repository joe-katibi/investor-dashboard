<?php

namespace App\Filament\Resources\Users\RelationManagers;

use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

class ShiftAssignmentsRelationManager extends RelationManager
{
    protected static string $relationship = 'shiftAssignments';

    protected static ?string $title = 'Shifts';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return (bool) Auth::user()?->can('workforce.manage');
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            DatePicker::make('work_date')->required()->default(today()),
            Select::make('team_id')->relationship('team', 'name')->preload(),
            DateTimePicker::make('shift_start')->required()->seconds(false),
            DateTimePicker::make('shift_end')->required()->seconds(false)->after('shift_start'),
            Select::make('status')->options(['scheduled' => 'Scheduled', 'cancelled' => 'Cancelled'])->default('scheduled')->required(),
            TextInput::make('notes')->maxLength(255),
        ])->columns(2);
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('shift_start', 'desc')
            ->columns([
                TextColumn::make('work_date')->date()->sortable(),
                TextColumn::make('shift_start')->dateTime('Y-m-d H:i'),
                TextColumn::make('shift_end')->dateTime('Y-m-d H:i'),
                TextColumn::make('team.name')->placeholder('—'),
                TextColumn::make('status')->badge()->color(fn (string $state) => $state === 'scheduled' ? 'success' : 'gray'),
            ])
            ->headerActions([
                CreateAction::make(),
                Action::make('generate')
                    ->label('Generate recurring shifts')
                    ->schema([
                        DatePicker::make('from')->required()->default(today()),
                        DatePicker::make('to')->required()->default(today()->addDays(13))->afterOrEqual('from'),
                        TimePicker::make('start')->required()->seconds(false)->default('08:00'),
                        TimePicker::make('end')->required()->seconds(false)->default('17:00')
                            ->helperText('An end time before the start time creates an overnight shift.'),
                    ])
                    ->action(function (array $data): void {
                        $count = 0;
                        for ($day = Carbon::parse($data['from']); $day->lte(Carbon::parse($data['to'])); $day->addDay()) {
                            $start = $day->copy()->setTimeFromTimeString($data['start']);
                            $end = $day->copy()->setTimeFromTimeString($data['end']);
                            if ($end->lte($start)) {
                                $end->addDay();
                            }
                            $this->getOwnerRecord()->shiftAssignments()->firstOrCreate(
                                ['work_date' => $day->toDateString(), 'shift_start' => $start],
                                ['shift_end' => $end, 'status' => 'scheduled'],
                            );
                            $count++;
                        }
                        Notification::make()->title("{$count} shift(s) scheduled")->success()->send();
                    }),
            ])
            ->recordActions([EditAction::make(), DeleteAction::make()]);
    }
}
