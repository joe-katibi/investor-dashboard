<?php

namespace App\Filament\Pages;

use App\Http\Controllers\ReportExportController;
use App\Models\ReportExport;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use UnitEnum;

/**
 * Where exported reports are collected. An export is prepared in the background (see
 * GenerateReportExportJob), listed here when it is ready, and removed six hours later.
 */
class ReportDownloads extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowDownTray;

    protected static string|UnitEnum|null $navigationGroup = 'Reporting';

    protected static ?int $navigationSort = 2;

    protected static ?string $slug = 'report-downloads';

    protected static ?string $title = 'Report Downloads';

    protected string $view = 'filament.pages.report-downloads';

    public static function canAccess(): bool
    {
        return (bool) Auth::user()?->can('reports.export');
    }

    public function getSubheading(): ?string
    {
        return 'Reports you export are prepared in the background and appear here. Each file is kept for '.ReportExport::KEEP_HOURS.' hours, then removed automatically.';
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('reports')->label('Export another report')->icon(Heroicon::OutlinedDocumentChartBar)->color('gray')->url(Reports::getUrl()),
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => ReportExport::query()->visibleTo(Auth::user())->where('expires_at', '>', now())->with('user:id,name'))
            ->defaultSort('created_at', 'desc')
            // Only while something is still being prepared: the list then updates by itself.
            ->poll(fn (): ?string => ReportExport::query()->visibleTo(Auth::user())->whereIn('status', [ReportExport::QUEUED, ReportExport::RUNNING])->exists() ? '5s' : null)
            ->emptyStateHeading('No report exports')
            ->emptyStateDescription('Open Reports, choose a report and a period, and press Export. The file appears here when it is ready.')
            ->columns([
                TextColumn::make('period')->label('Date range')->state(fn (ReportExport $record) => $record->periodLabel()),
                TextColumn::make('title')->label('Report')->weight('bold')->searchable()->wrap()
                    ->description(fn (ReportExport $record) => $record->statusLabel())
                    ->color(fn (ReportExport $record) => $record->status === ReportExport::FAILED ? 'danger' : null),
                TextColumn::make('user.name')->label('Pulled by')->searchable(),
                TextColumn::make('created_at')->label('Date pulled')->dateTime('d M Y H:i')->sortable(),
            ])
            ->recordActions([
                Action::make('download')
                    ->label(fn (ReportExport $record) => match (true) {
                        $record->isPending() => 'Preparing…',
                        $record->status === ReportExport::FAILED => 'Not available',
                        default => 'Download'.($record->size_bytes ? ' ('.self::size($record->size_bytes).')' : ''),
                    })
                    ->icon(Heroicon::OutlinedArrowDownTray)
                    ->button()
                    ->color(fn (ReportExport $record) => $record->isReady() ? 'primary' : 'gray')
                    ->disabled(fn (ReportExport $record) => ! $record->downloadableBy(Auth::user()))
                    ->tooltip(fn (ReportExport $record) => $record->isReady() && ! $record->downloadableBy(Auth::user()) ? 'You cannot run this report yourself, so you cannot download it.' : null)
                    // The file is handed to the browser as a download. (A plain link would be opened by the
                    // panel as if it were one of its pages — it runs as a single-page application.)
                    ->action(function (ReportExport $record) {
                        $record->refresh();

                        if (! $record->downloadableBy(Auth::user()) || ! Storage::disk(ReportExport::DISK)->exists($record->path)) {
                            Notification::make()->title('This report is no longer kept')->body('Export it again from Reports.')->warning()->send();

                            return null;
                        }

                        return ReportExportController::send($record, Auth::user());
                    }),
            ])
            ->recordActionsColumnLabel('Action');
    }

    private static function size(int $bytes): string
    {
        return $bytes >= 1_048_576 ? number_format($bytes / 1_048_576, 1).' MB' : max(1, (int) round($bytes / 1024)).' KB';
    }
}
