<?php

namespace App\Console\Commands;

use App\Services\Topology\TopologyImporter;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use InvalidArgumentException;

#[Signature('oms:import-topology {file : Path to the .xlsx / .csv mapping (columns OLT, FDT, SLOT, PORT)}
    {--dry-run : Preview what would change without saving}
    {--missing=keep : OLTs / FDTs / slots / ports NOT in the file: keep, deactivate, or remove (deletes those no ticket uses, deactivates the rest)}')]
#[Description('Import the OLT → FDT → Slot → Port mapping from a spreadsheet (safe to re-run)')]
class ImportTopologyCommand extends Command
{
    public function handle(TopologyImporter $importer): int
    {
        try {
            $report = $importer->import((string) $this->argument('file'), (bool) $this->option('dry-run'), (string) $this->option('missing'));
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        if ($report['errors'] !== []) {
            $this->error('Nothing imported — fix these rows first:');
            foreach (array_slice($report['errors'], 0, 50) as $error) {
                $this->line('  '.$error);
            }

            return self::FAILURE;
        }

        $this->info(($report['dry_run'] ? 'DRY RUN — nothing saved. ' : '')."{$report['rows']} rows read.");
        $this->table(['', 'OLTs', 'FDTs', 'Slots', 'Ports'], [
            ['Created', ...array_values($report['created'])],
            ['Removed (not in file)', ...array_values($report['removed'])],
            ['Deactivated (not in file, still on tickets)', ...array_values($report['deactivated'])],
        ]);

        if ($report['reactivated']) {
            $this->line("Reactivated {$report['reactivated']} inactive item(s) that are in the file.");
        }

        return self::SUCCESS;
    }
}
