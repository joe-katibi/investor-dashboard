<?php

namespace App\Console\Commands;

use App\Services\Outages\OutageTransfer;
use App\Support\AuditContext;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use RuntimeException;

#[Signature('oms:import-outages
    {folder : The folder written by oms:export-outages on the other server}
    {--apply : Import; without it only the plan is shown}')]
#[Description('Import the outages exported from another OMS server that are not here yet (those raised there after the database was copied); ticket numbers already used here are replaced')]
class ImportOutagesCommand extends Command
{
    public function handle(OutageTransfer $transfer): int
    {
        $folder = rtrim((string) $this->argument('folder'), '/');

        try {
            $plan = $transfer->plan($folder);
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->line("Export from {$plan['export']['server']} at {$plan['export']['exported_at']} (outages raised or changed since {$plan['export']['since']}).");
        $this->line(count($plan['present']).' outage(s) are already here (same ticket number and time raised): left as they are.');

        if ($plan['changed'] !== []) {
            $this->warn(count($plan['changed']).' outage(s) that are here were changed on the other server after the copy. Those changes are NOT imported — update them by hand:');
            $this->table(['Ticket', 'Title', 'Changed there', 'Last changed here'], array_map(fn (array $l) => [$l['ticket'], mb_strimwidth($l['title'], 0, 50, '…'), $l['updated_there'], $l['updated_here']], $plan['changed']));
        }

        if ($plan['new'] === []) {
            $this->info('Nothing new to import.');

            return self::SUCCESS;
        }

        $this->info(count($plan['new']).' outage(s) to import:');
        $this->table(['Ticket', 'Title', 'Raised', 'Ticket number'], array_map(fn (array $l) => [$l['ticket'], mb_strimwidth($l['title'], 0, 50, '…'), $l['raised'], $l['number']], $plan['new']));

        if ($plan['unresolved'] !== []) {
            $this->error('These do not exist on this server and the outages need them — create them first (users with the same e-mail address), then run again:');
            foreach ($plan['unresolved'] as $table => $items) {
                $this->line("  {$table}: ".implode(', ', $items));
            }

            return self::FAILURE;
        }

        if (! $this->option('apply')) {
            $this->warn('Preview only — nothing changed. Take a database backup, then run again with --apply.');

            return self::SUCCESS;
        }

        $imported = AuditContext::as('system:import-outages', fn () => $transfer->import($folder));

        $this->info(count($imported).' outage(s) imported:');
        $this->table(['Ticket on the old server', 'Ticket here'], array_map(fn (array $i) => [$i['from'], $i['to']], $imported));

        return self::SUCCESS;
    }
}
