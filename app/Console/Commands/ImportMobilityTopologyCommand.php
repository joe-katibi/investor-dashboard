<?php

namespace App\Console\Commands;

use App\Services\Topology\MobilityTopologyImporter;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use InvalidArgumentException;

#[Signature('oms:import-4g-topology {file : Path to the 4G NOC workbook (.xlsx with the eNodeB and transmission sheets) or one sheet as .csv}
    {--dry-run : Preview what would change without saving}')]
#[Description('Import the 4G topology: eNodeB sites and transmission nodes RSG → ASG → CSG → SSG (safe to re-run)')]
class ImportMobilityTopologyCommand extends Command
{
    public function handle(MobilityTopologyImporter $importer): int
    {
        try {
            $report = $importer->import((string) $this->argument('file'), (bool) $this->option('dry-run'));
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

        $this->info($report['dry_run'] ? 'DRY RUN — nothing saved.' : 'Imported.');
        $this->table(['', 'Rows read', 'Created', 'Updated'], [
            ['eNodeB sites', $report['sites']['rows'], $report['sites']['created'], $report['sites']['updated']],
            ['Transmission nodes', $report['nodes']['rows'], $report['nodes']['created'], $report['nodes']['updated']],
        ]);
        $this->line("SSGs linked to a site: {$report['nodes']['linked']}. Nodes created inactive (no site yet): {$report['nodes']['inactive']}.");

        return self::SUCCESS;
    }
}
