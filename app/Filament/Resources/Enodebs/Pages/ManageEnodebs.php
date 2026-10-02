<?php

namespace App\Filament\Resources\Enodebs\Pages;

use App\Filament\Resources\Enodebs\EnodebResource;
use App\Services\Topology\MobilityTopologyImporter;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ManageRecords;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;

class ManageEnodebs extends ManageRecords
{
    protected static string $resource = EnodebResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('importMobilityTopology')
                ->label('Import 4G topology')
                ->icon(Heroicon::OutlinedArrowUpTray)
                ->color('gray')
                ->visible(fn () => Auth::user()?->can('mobility.configure'))
                ->modalDescription('The 4G NOC workbook (.xlsx) with the “eNodeB site list” and “Transmission nodes” sheets, or one of the sheets as .csv. Sheets are recognised by their header row. Existing sites and nodes are updated, never duplicated or removed, so the workbook can be imported again after changes.')
                ->schema([
                    FileUpload::make('file')->label('Workbook')->required()
                        ->disk('local')->directory('topology-imports')->visibility('private')
                        ->acceptedFileTypes([
                            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'text/csv', 'text/plain', 'application/vnd.ms-excel',
                        ])
                        ->maxSize(20480),
                    Toggle::make('dry_run')->label('Preview only (nothing is saved)')->default(true),
                ])
                ->modalSubmitActionLabel('Import')
                ->action(function (array $data): void {
                    $disk = Storage::disk('local');

                    try {
                        $report = app(MobilityTopologyImporter::class)->import($disk->path($data['file']), (bool) $data['dry_run']);
                    } catch (InvalidArgumentException $exception) {
                        Notification::make()->title('Import failed')->body($exception->getMessage())->danger()->send();

                        return;
                    } finally {
                        $disk->delete($data['file']);
                    }

                    if ($report['errors'] !== []) {
                        Notification::make()->title('Nothing imported — fix these rows first')
                            ->body(implode("\n", array_slice($report['errors'], 0, 15)).(count($report['errors']) > 15 ? "\n…" : ''))
                            ->danger()->persistent()->send();

                        return;
                    }

                    [$sites, $nodes] = [$report['sites'], $report['nodes']];
                    Notification::make()
                        ->title($report['dry_run'] ? 'Preview — nothing saved' : '4G topology imported')
                        ->body("eNodeB sites: {$sites['rows']} rows, {$sites['created']} created, {$sites['updated']} updated. "
                            ."Transmission nodes: {$nodes['rows']} rows, {$nodes['created']} created (incl. CSG / ASG / RSG), {$nodes['updated']} updated; "
                            ."{$nodes['linked']} SSGs linked to a site, {$nodes['inactive']} created inactive (no site yet)."
                            .($report['dry_run'] ? ' Import again with “Preview only” off to save.' : ''))
                        ->success()->persistent()->send();
                }),
            CreateAction::make(),
        ];
    }
}
