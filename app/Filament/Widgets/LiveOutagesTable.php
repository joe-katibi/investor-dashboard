<?php

namespace App\Filament\Widgets;

use App\Filament\Concerns\RefreshesOnOmsEvents;
use App\Filament\Resources\Outages\OutageResource;
use App\Models\Outage;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

/**
 * Base for the live outage tables on the command centre: priority, impact, SLA, elapsed
 * time, current owner and latest action (spec §1).
 */
abstract class LiveOutagesTable extends TableWidget
{
    use RefreshesOnOmsEvents;

    protected int|string|array $columnSpan = 'full';

    abstract protected function scope(Builder $query): void;

    public function table(Table $table): Table
    {
        return $table
            ->query(function (): Builder {
                $query = Outage::query()->active()->with([
                    'priority:id,name,color', 'status:id,name,color', 'vertical:id,name', 'currentAssignee:id,name', 'olts:id,name', 'fdts:id,name,olt_id',
                ]);
                $this->scope($query);

                return $query;
            })
            ->defaultSort('start_time', 'asc')
            ->paginated([5, 10, 25])
            ->defaultPaginationPageOption(5)
            ->poll('60s')
            ->recordUrl(fn (Outage $record) => OutageResource::getUrl('view', ['record' => $record]))
            ->emptyStateHeading('No live outages')
            ->emptyStateIcon('heroicon-o-check-circle')
            ->columns([
                TextColumn::make('ticket_number')->label('Ticket')->weight('bold'),
                TextColumn::make('title')->limit(35)->description(fn (Outage $record) => $record->topologyPath() ?: $record->location),
                TextColumn::make('vertical.name')->label('Vertical'),
                TextColumn::make('priority.name')->label('Priority')->badge()->color(fn (Outage $record) => $record->priority?->color ?? 'gray'),
                TextColumn::make('customers_affected')->label('Customers')->numeric(),
                TextColumn::make('sla_state')->label('SLA')->badge(),
                TextColumn::make('elapsed')->state(fn (Outage $record) => $record->elapsedForHumans()),
                TextColumn::make('status.name')->label('Status')->badge()->color(fn (Outage $record) => $record->status?->color ?? 'gray'),
                TextColumn::make('currentAssignee.name')->label('Owner')->placeholder('Queue'),
                TextColumn::make('latest_update')->label('Latest action')->limit(40)->placeholder('—'),
            ]);
    }
}
