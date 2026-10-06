<?php

namespace App\Console\Commands;

use App\Services\Tickets\CustomerImporter;
use App\Services\Tickets\CustomerImportRun;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use InvalidArgumentException;

#[Signature('oms:import-customers {file : Path to the .xlsx / .csv customer list (header row: Alias Name, ONU Name, OLT, FDT, Slot, Port, Bandwidth…)}
    {--preview : Check the file and show what would change; nothing is saved}
    {--keep-incomplete : Import rows with an incomplete network position (a slot without an FDT, a port without a slot) with the part that is complete, instead of stopping}')]
#[Description('Import the customer list from a spreadsheet — the same import as Customers → Import customers, for files too large to upload (safe to re-run)')]
class ImportCustomersCommand extends Command
{
    public function handle(CustomerImporter $importer): int
    {
        $started = microtime(true);

        try {
            $report = $importer->import((string) $this->argument('file'), (bool) $this->option('preview'), (bool) $this->option('keep-incomplete'));
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $outcome = CustomerImportRun::outcome($report);
        $failed = $outcome['level'] === 'danger';

        $failed ? $this->error($outcome['title']) : $this->info($outcome['title']);
        foreach ($outcome['lines'] as $line) {
            $this->line('  '.$line);
        }

        if ($failed && $report['error_rows'] > 15) {
            $this->line('  Kinds of problem among the first '.count($report['errors']).' rows listed:');
            foreach (collect($report['errors'])->map(fn (string $error) => preg_replace('/^Row \d+: /', '', $error))->countBy()->sortDesc() as $problem => $count) {
                $this->line("    {$count} × {$problem}");
            }
        }

        $this->line('  ('.round(microtime(true) - $started).' s, '.round(memory_get_peak_usage(true) / 1048576).' MB)');

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
