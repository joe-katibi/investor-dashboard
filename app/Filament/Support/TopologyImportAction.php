<?php

namespace App\Filament\Support;

use App\Filament\Resources\Fdts\FdtResource;
use App\Filament\Resources\Ports\PortResource;
use App\Filament\Resources\Slots\SlotResource;
use App\Models\Olt;
use App\Services\Topology\TopologyImporter;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Radio;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

/**
 * "Import topology" (OLT | FDT | SLOT | PORT spreadsheet), offered on the OLT, FDT, Slot and Port
 * screens. The person must choose between a preview and a real import — a preview says in so many
 * words that nothing was saved — and after an import the result lists what each OLT of the file
 * now has, so it can be checked at a glance.
 */
final class TopologyImportAction
{
    public static function make(): Action
    {
        return Action::make('importTopology')
            ->label('Import topology')
            ->icon(Heroicon::OutlinedArrowUpTray)
            ->color('gray')
            ->visible(fn () => Auth::user()?->can('topology.manage'))
            ->modalDescription('Spreadsheet (.xlsx or .csv) with the header OLT | FDT | SLOT | PORT, one row per port. One file fills all four levels: the OLT, its FDTs, their slots and ports. Existing items are reused and only missing ones are created, so the same file can be imported again after changes.')
            ->schema([
                FileUpload::make('file')->label('Mapping file')->required()
                    ->disk('local')->directory('topology-imports')->visibility('private')
                    ->acceptedFileTypes([
                        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'text/csv', 'text/plain', 'application/vnd.ms-excel',
                    ])
                    ->maxSize(20480),
                // No default: a preview must never be mistaken for an import.
                Radio::make('dry_run')->label('What should happen?')->required()
                    ->options([
                        0 => 'Import — save the OLTs, FDTs, slots and ports of the file',
                        1 => 'Preview only — check the file and show what would be created; nothing is saved',
                    ]),
                Radio::make('missing')->label('OLTs / FDTs / slots / ports that are NOT in the file')->required()->default(TopologyImporter::KEEP)
                    ->options([
                        TopologyImporter::KEEP => 'Keep them',
                        TopologyImporter::DEACTIVATE => 'Deactivate them (hidden from selection lists)',
                        TopologyImporter::REMOVE => 'Remove them (those still on tickets are deactivated instead, so ticket history is kept)',
                    ]),
            ])
            ->modalSubmitActionLabel('Continue')
            ->action(function (array $data): void {
                $disk = Storage::disk('local');

                try {
                    $report = app(TopologyImporter::class)->import($disk->path($data['file']), (bool) $data['dry_run'], $data['missing'] ?? TopologyImporter::KEEP);
                } catch (InvalidArgumentException $exception) {
                    Notification::make()->title('Import failed — nothing was saved')->body($exception->getMessage())->danger()->persistent()->send();

                    return;
                } catch (Throwable $exception) {
                    // The import runs in one transaction: a database error leaves the topology exactly as it was.
                    report($exception);
                    Notification::make()->title('Import failed — nothing was saved')
                        ->body('The database refused the import: '.Str::limit($exception->getMessage(), 400))
                        ->danger()->persistent()->send();

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
                $changes = "{$report['rows']} rows read. ".($report['dry_run'] ? 'Would create' : 'Created')." {$c['olts']} OLTs, {$c['fdts']} FDTs, {$c['slots']} slots, {$c['ports']} ports."
                    .($report['reactivated'] ? " Reactivated {$report['reactivated']}." : '')
                    .(array_sum($r = $report['removed']) ? " Removed {$r['olts']} OLTs, {$r['fdts']} FDTs, {$r['slots']} slots, {$r['ports']} ports." : '')
                    .(array_sum($d) ? " Deactivated (still on tickets) {$d['olts']} OLTs, {$d['fdts']} FDTs, {$d['slots']} slots, {$d['ports']} ports." : '');

                // What each OLT of the file has once the import is in: the check that everything arrived.
                $totals = collect($report['olts'])->take(8)
                    ->map(fn (array $t, string $olt) => "{$olt}: {$t['fdts']} FDTs, {$t['slots']} slots, {$t['ports']} ports")->implode("\n")
                    .(count($report['olts']) > 8 ? "\n… and ".(count($report['olts']) - 8).' more OLTs' : '');

                if ($report['dry_run']) {
                    Notification::make()->title('PREVIEW ONLY — nothing was saved')
                        ->body($changes."\nAfter an import:\n".$totals."\nRun it again and choose “Import” to save.")
                        ->warning()->persistent()->send();

                    return;
                }

                // New rows sit at the end of long lists: open the lists already filtered to the OLT of the file.
                $oltId = count($report['olts']) === 1 ? Olt::query()->where('name', array_key_first($report['olts']))->value('id') : null;
                $filter = $oltId ? ['filters' => ['olt_id' => ['value' => $oltId]]] : [];
                $oltFilter = $oltId ? ['filters' => ['olt' => ['value' => $oltId]]] : [];

                Notification::make()->title('Topology imported and saved')
                    ->body($changes."\nNow on file:\n".$totals)
                    ->actions([
                        Action::make('fdts')->label('Show its FDTs')->url(FdtResource::getUrl('index', $filter)),
                        Action::make('slots')->label('Slots')->url(SlotResource::getUrl('index', $oltFilter)),
                        Action::make('ports')->label('Ports')->url(PortResource::getUrl('index', $oltFilter)),
                    ])
                    ->success()->persistent()->send();
            });
    }
}
