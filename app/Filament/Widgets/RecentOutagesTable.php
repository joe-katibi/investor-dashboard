<?php

namespace App\Filament\Widgets;

use App\Filament\Concerns\RefreshesOnOmsEvents;
use App\Filament\Resources\Outages\OutageResource;
use App\Models\Outage;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

class RecentOutagesTable extends TableWidget
{
    use RefreshesOnOmsEvents;

    protected static ?int $sort = 13;

    protected static ?string $heading = 'Recent outages';

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->query(fn () => Outage::query()->with(['outageType:id,name', 'priority:id,name,color', 'status:id,name,color', 'currentAssignee:id,name', 'team:id,name'])->latest('id'))
            ->paginated([5, 10])
            ->defaultPaginationPageOption(5)
            ->poll('60s')
            ->recordUrl(fn (Outage $record) => OutageResource::getUrl('view', ['record' => $record]))
            ->columns([
                TextColumn::make('ticket_number')->label('Ticket')->weight('bold')->wrap()->formatStateUsing(fn (?string $state) => str_replace('-', "\u{2011}", (string) $state))
                    ->description(fn (Outage $record) => str($record->title)->limit(40)),
                TextColumn::make('outageType.name')->label('Type')->visibleFrom('lg'),
                TextColumn::make('customers_affected')->label('Impact')->numeric()->visibleFrom('md'),
                TextColumn::make('status.name')->label('Status')->badge()->wrap()->color(fn (Outage $record) => $record->status?->color ?? 'gray'),
                TextColumn::make('owner')->state(fn (Outage $record) => $record->currentAssignee?->name ?? $record->team?->name)->placeholder('—')->visibleFrom('lg'),
                TextColumn::make('sla_state')->label('SLA')->badge()->visibleFrom('sm')->state(fn (Outage $record) => $record->currentSlaState()),
                TextColumn::make('created_at')->label('Reported')->since()->visibleFrom('md'),
            ]);
    }
}
