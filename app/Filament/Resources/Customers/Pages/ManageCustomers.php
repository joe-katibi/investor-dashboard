<?php

namespace App\Filament\Resources\Customers\Pages;

use App\Filament\Resources\Customers\CustomerResource;
use App\Services\Tickets\CustomerImporter;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Radio;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ManageRecords;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;

class ManageCustomers extends ManageRecords
{
    protected static string $resource = CustomerResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('importCustomers')
                ->label('Import customers')
                ->icon(Heroicon::OutlinedArrowUpTray)
                ->color('gray')
                ->visible(fn () => Auth::user()?->can('customer.manage'))
                ->modalDescription('An .xlsx or .csv file with a header row: Alias Name (customer name), ONU Name (account number), OLT, FDT, Slot, Port, Bandwidth — and optionally Phone, Alt Phone, Address, Landmark, Region, Email. Customers are matched on the account number (also those captured by hand on a ticket); OLTs, FDTs, slots and ports that already exist are reused, only new ones are created. Nothing is removed, so the file can be imported again after changes.')
                ->schema([
                    FileUpload::make('file')->label('Customer file')->required()
                        ->disk('local')->directory('customer-imports')->visibility('private')
                        ->acceptedFileTypes(['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'text/csv', 'text/plain', 'application/vnd.ms-excel'])
                        ->maxSize(51200),
                    // No default: a preview must never be mistaken for an import.
                    Radio::make('dry_run')->label('What should happen?')->required()
                        ->options([0 => 'Import — save the file', 1 => 'Preview only — check the file; nothing is saved']),
                ])
                ->modalSubmitActionLabel('Import')
                ->action(function (array $data): void {
                    $disk = Storage::disk('local');

                    try {
                        $report = app(CustomerImporter::class)->import($disk->path($data['file']), (bool) $data['dry_run']);
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

                    $topology = $report['topology'];
                    Notification::make()
                        ->title($report['dry_run'] ? 'PREVIEW ONLY — nothing was saved' : 'Customers imported and saved')
                        ->body("{$report['rows']} rows: {$report['created']} new customers, {$report['updated']} updated, {$report['unchanged']} unchanged. "
                            ."New on the network: {$topology['olts']} OLTs, {$topology['fdts']} FDTs, {$topology['slots']} slots, {$topology['ports']} ports"
                            .($report['regions'] ? ", {$report['regions']} regions" : '').". {$report['tickets_linked']} earlier ticket(s) linked to their customer."
                            .($report['dry_run'] ? ' Run it again and choose “Import” to save.' : ''))
                        ->color($report['dry_run'] ? 'warning' : 'success')->icon($report['dry_run'] ? 'heroicon-o-eye' : 'heroicon-o-check-circle')
                        ->persistent()->send();
                }),
            // Entered here by someone managing customers: a confirmed record, not a provisional one from a ticket.
            CreateAction::make()->mutateDataUsing(fn (array $data) => $data + ['verified_at' => now()]),
        ];
    }
}
