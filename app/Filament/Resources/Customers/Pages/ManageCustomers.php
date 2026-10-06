<?php

namespace App\Filament\Resources\Customers\Pages;

use App\Filament\Resources\Customers\CustomerResource;
use App\Services\Tickets\CustomerImportRun;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Radio;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ManageRecords;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

use function Illuminate\Support\defer;

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
                        ->maxSize(51200)
                        ->helperText('For a full customer list a .csv file uploads and reads faster than .xlsx. A file the site will not take can be imported on the server: php artisan oms:import-customers <file>'),
                    // No default: a preview must never be mistaken for an import.
                    Radio::make('dry_run')->label('What should happen?')->required()
                        ->options([0 => 'Import — save the file', 1 => 'Preview only — check the file; nothing is saved']),
                    Radio::make('keep_incomplete')->label('Rows with an incomplete network position')
                        ->helperText('A slot without an FDT, a port without a slot or an FDT without an OLT cannot be placed on the network.')
                        ->options([
                            0 => 'Stop and list them — nothing is imported',
                            1 => 'Import those customers with the part that is complete (for example the OLT only)',
                        ])->default(0)->required(),
                ])
                ->modalSubmitActionLabel('Import')
                ->action(function (array $data): void {
                    $user = Auth::user();
                    $lock = CustomerImportRun::lock();

                    if (! $lock) {
                        Storage::disk('local')->delete($data['file']);
                        Notification::make()->title('Another customer import is still running')
                            ->body('Wait for its notification, then start this one again.')->warning()->send();

                        return;
                    }

                    // A full customer list takes a minute or two — longer than a page may take to
                    // answer. It runs once this page has answered; the outcome arrives in the bell.
                    defer(fn () => app(CustomerImportRun::class)->run($data['file'], (bool) $data['dry_run'], (bool) $data['keep_incomplete'], $user, $lock));

                    Notification::make()
                        ->title($data['dry_run'] ? 'Checking the file…' : 'Import started')
                        ->body('The file is being read in the background. The result arrives as a notification (the bell, top right) — a few seconds for a small file, a minute or two for a full customer list. You can keep working meanwhile.')
                        ->info()->persistent()->send();
                }),
            // Entered here by someone managing customers: a confirmed record, not a provisional one from a ticket.
            CreateAction::make()->mutateDataUsing(fn (array $data) => $data + ['verified_at' => now()]),
        ];
    }
}
