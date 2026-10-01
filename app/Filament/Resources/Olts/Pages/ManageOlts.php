<?php

namespace App\Filament\Resources\Olts\Pages;

use App\Filament\Resources\Olts\OltResource;
use App\Services\Topology\TopologyImporter;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ManageRecords;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;

class ManageOlts extends ManageRecords
{
    protected static string $resource = OltResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('importTopology')
                ->label('Import topology')
                ->icon(Heroicon::OutlinedArrowUpTray)
                ->color('gray')
                ->visible(fn () => Auth::user()?->can('topology.manage'))
                ->modalDescription('Spreadsheet (.xlsx or .csv) with the header OLT | FDT | SLOT | PORT, one row per port. Existing items are reused and only missing ones are created, so the same file can be imported again after changes.')
                ->schema([
                    FileUpload::make('file')->label('Mapping file')->required()
                        ->disk('local')->directory('topology-imports')->visibility('private')
                        ->acceptedFileTypes([
                            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'text/csv', 'text/plain', 'application/vnd.ms-excel',
                        ])
                        ->maxSize(20480),
                    Toggle::make('dry_run')->label('Preview only (nothing is saved)')->default(true),
                    Radio::make('missing')->label('OLTs / FDTs / slots / ports that are NOT in the file')->required()->default(TopologyImporter::KEEP)
                        ->options([
                            TopologyImporter::KEEP => 'Keep them',
                            TopologyImporter::DEACTIVATE => 'Deactivate them (hidden from selection lists)',
                            TopologyImporter::REMOVE => 'Remove them (those still on tickets are deactivated instead, so ticket history is kept)',
                        ]),
                ])
                ->modalSubmitActionLabel('Import')
                ->action(function (array $data): void {
                    $disk = Storage::disk('local');

                    try {
                        $report = app(TopologyImporter::class)->import($disk->path($data['file']), (bool) $data['dry_run'], $data['missing'] ?? TopologyImporter::KEEP);
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

                    $c = $report['created'];
                    $d = $report['deactivated'];
                    Notification::make()
                        ->title(($report['dry_run'] ? 'Preview — nothing saved: ' : 'Imported: ')."{$report['rows']} rows")
                        ->body("Created {$c['olts']} OLTs, {$c['fdts']} FDTs, {$c['slots']} slots, {$c['ports']} ports."
                            .($report['reactivated'] ? " Reactivated {$report['reactivated']}." : '')
                            .(array_sum($r = $report['removed']) ? " Removed {$r['olts']} OLTs, {$r['fdts']} FDTs, {$r['slots']} slots, {$r['ports']} ports." : '')
                            .(array_sum($d) ? " Deactivated (still on tickets) {$d['olts']} OLTs, {$d['fdts']} FDTs, {$d['slots']} slots, {$d['ports']} ports." : '')
                            .($report['dry_run'] ? ' Import again with "Preview only" off to save.' : ''))
                        ->success()->persistent()->send();
                }),
            CreateAction::make(),
        ];
    }
}
