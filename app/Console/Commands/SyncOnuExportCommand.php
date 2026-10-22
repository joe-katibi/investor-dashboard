<?php

namespace App\Console\Commands;

use App\Models\CustomerOnuChange;
use App\Services\Customers\OnuExportSync;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('oms:sync-onu-export
    {--source=fiberhome : The export (config oms.onu_exports)}
    {--file=* : Read these files instead of the newest dump in the folder (all parts of one dump)}
    {--apply : Write the changes; without it only the plan is shown}
    {--force : Accept a dump the guards refuse (much smaller than the last one, many OLT moves), or read one again}')]
#[Description('Bring the customers up to date from the newest ONU export (OLT, slot, port, ONU type and physical address), keeping a history of every change')]
class SyncOnuExportCommand extends Command
{
    public function handle(OnuExportSync $sync): int
    {
        $source = (string) $this->option('source');
        $config = config("oms.onu_exports.{$source}");

        if (! is_array($config)) {
            $this->error("Unknown source “{$source}”. Sources: ".implode(', ', array_keys((array) config('oms.onu_exports'))));

            return self::FAILURE;
        }

        try {
            if ($files = (array) $this->option('file')) {
                $dump = ['files' => $files, 'dumped_at' => OnuExportSync::stamp(basename($files[0])), 'name' => basename($files[0])];
            } elseif (blank($config['path'] ?? null)) {
                $this->warn("No folder is set for {$config['label']} (OMS_ONU_EXPORT_".strtoupper($source).'_PATH in .env): nothing to read.');

                return self::SUCCESS;
            } elseif (! $dump = $sync->latestDump((string) $config['path'])) {
                $this->info("No complete dump in {$config['path']} yet.");

                return self::SUCCESS;
            }

            $this->line("{$config['label']}: {$dump['name']}".(count($dump['files']) > 1 ? ' (+'.(count($dump['files']) - 1).' parts)' : '')
                .($dump['dumped_at'] ? ', dumped '.$dump['dumped_at']->format('d M Y H:i') : ''));
            $report = $sync->sync($source, $dump['files'], $dump['dumped_at'], (bool) $this->option('apply'), (bool) $this->option('force'));
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        if ($report['status'] === 'skipped') {
            $this->info($report['message']);

            return self::SUCCESS;
        }

        $this->table(['ONU export', 'Count'], [
            ['Rows', $report['rows']],
            ['Rows without an account number (left out)', $report['no_account']],
            ['Accounts on more than one ONU (the newest ONU used)', $report['duplicates']],
            ['Customers on file', $report['matched']],
            ['   changed', $report['updated']],
            ['   unchanged', $report['unchanged']],
            ['   moved to another OLT', $report['olt_moves']],
            ['   ONU details filled in for the first time', $report['filled']],
            ['New customers (not on file yet)', $report['created']],
            ['Slots / ports added to FDTs', $report['new_slots'].' / '.$report['new_ports']],
            ...($report['outside_olts'] ? [['On OLTs this source does not update (left out)', $report['outside_olts']]] : []),
        ]);

        if ($report['changes']) {
            $this->table(['Changed', 'Customers'], collect($report['changes'])->map(fn (int $n, string $field) => [CustomerOnuChange::FIELDS[$field] ?? $field, $n])->values()->all());
        }

        if ($report['unknown_olts']) {
            $this->warn('OLT names not found in OMS — their customers keep their OLT. Add the name as an alias of the right OLT (Topology → OLTs):');
            $this->table(['Name in the export', 'Rows'], collect($report['unknown_olts'])->sortDesc()->map(fn (int $n, string $name) => [$name, $n])->values()->all());
        }

        if ($this->getOutput()->isVerbose() && $report['samples']) {
            $this->line('Examples:');
            foreach ($report['samples'] as $sample) {
                $this->line("  {$sample}");
            }
        }

        if ($report['status'] === 'refused') {
            $this->error($report['message']);

            return self::FAILURE;
        }

        $report['status'] === 'preview'
            ? $this->warn('Preview only — nothing changed. Add -v for examples; run again with --apply to write the changes.')
            : $this->info('Done: '.array_sum($report['changes'])." change(s) recorded (run #{$report['run_id']}).");

        return self::SUCCESS;
    }
}
