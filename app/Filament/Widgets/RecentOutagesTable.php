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
                TextColumn::make('ticket_number')->label('Ticket')->weight('bold'),
                TextColumn::make('outageType.name')->label('Type'),
                TextColumn::make('title')->limit(40),
                TextColumn::make('customers_affected')->label('Impact')->numeric(),
                TextColumn::make('status.name')->label('Status')->badge()->color(fn (Outage $record) => $record->status?->color ?? 'gray'),
                TextColumn::make('owner')->state(fn (Outage $record) => $record->currentAssignee?->name ?? $record->team?->name)->placeholder('—'),
                TextColumn::make('sla_state')->label('SLA')->badge(),
                TextColumn::make('created_at')->label('Reported')->since(),
            ]);
    }
}
