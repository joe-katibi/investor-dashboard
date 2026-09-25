<?php

namespace App\Filament\Resources\Outages\Pages;

use App\Enums\SlaState;
use App\Filament\Concerns\RefreshesOnOmsEvents;
use App\Filament\Resources\Outages\OutageResource;
use App\Models\MajorOutageType;
use App\Models\Priority;
use App\Services\Reports\OutageQuery;
use App\Services\Reports\ReportExporter;
use App\Services\Reports\ReportRegistry;
use App\Services\Reports\ReportResult;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

class ListOutages extends ListRecords
{
    use RefreshesOnOmsEvents;

    protected static string $resource = OutageResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('exportExcel')
                ->label('Export Excel')
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->color('gray')
                ->visible(fn () => Auth::user()?->can('reports.export'))
                ->action(fn () => $this->exportFiltered()),
            CreateAction::make()->label('Report outage')->icon(Heroicon::OutlinedPlus),
        ];
    }

    public function getTabs(): array
    {
        return [
            'active' => Tab::make('Active')->modifyQueryUsing(fn (Builder $query) => $query->where('is_closed', false)),
            'mine' => Tab::make('My tickets')->icon(Heroicon::OutlinedUser)
                ->modifyQueryUsing(fn (Builder $query) => $query->where('current_assignee_id', Auth::id())),
            'network' => Tab::make('Network')->modifyQueryUsing(fn (Builder $query) => $query->where('is_closed', false)->ofMajorType(MajorOutageType::NETWORK)),
            'core' => Tab::make('Core')->modifyQueryUsing(fn (Builder $query) => $query->where('is_closed', false)->ofMajorType(MajorOutageType::CORE)),
            'system' => Tab::make('System')->modifyQueryUsing(fn (Builder $query) => $query->where('is_closed', false)->ofMajorType(MajorOutageType::SYSTEM)),
            'critical' => Tab::make('Critical')->modifyQueryUsing(fn (Builder $query) => $query->where('is_closed', false)->whereHas('priority', fn ($p) => $p->where('code', Priority::CRITICAL))),
            'breached' => Tab::make('SLA breached / at risk')->modifyQueryUsing(fn (Builder $query) => $query->where('is_closed', false)->whereIn('sla_state', [SlaState::Breached->value, SlaState::AtRisk->value])),
            'closed' => Tab::make('Closed')->modifyQueryUsing(fn (Builder $query) => $query->where('is_closed', true)),
            'all' => Tab::make('All'),
        ];
    }

    /** Export exactly what the user is looking at (tab + filters + search + sort) to XLSX. */
    public function exportFiltered(): ?Response
    {
        $key = 'export:'.Auth::id();
        if (RateLimiter::tooManyAttempts($key, 10)) {
            return null;
        }
        RateLimiter::hit($key, 60);

        $registry = app(ReportRegistry::class);
        $query = $this->getFilteredSortedTableQuery()->with(OutageQuery::RELATIONS);

        $report = new ReportResult('Outage Register', $registry->registerColumns(), function () use ($query, $registry) {
            foreach ($query->lazyById(500, 'outages.id') as $outage) {
                yield $registry->outageRow($outage);
            }
        });

        return app(ReportExporter::class)->xlsx($report);
    }
}
