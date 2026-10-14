<?php

namespace App\Console\Commands;

use App\Services\Topology\OltMerger;
use App\Support\AuditContext;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

#[Signature('oms:merge-olts
    {--pair=* : A duplicate spelt differently, "Duplicate=Kept" by name or id (e.g. --pair="Salama-OLT=Salaama")}
    {--apply : Make the changes; without it only the plan is shown}')]
#[Description('Fold duplicate OLTs (e.g. "Seguku-OLT" created by a customer import next to SEGUKU) into the original: FDTs, slots and ports merged by name; customers, tickets and outages re-pointed')]
class MergeOltsCommand extends Command
{
    public function handle(OltMerger $merger): int
    {
        try {
            $merges = $merger->plan((array) $this->option('pair'));
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        if ($merges === []) {
            $this->info('No duplicate OLTs: nothing to merge.');

            return self::SUCCESS;
        }

        $rows = array_map(function (array $merge) use ($merger): array {
            $c = $merger->preview($merge['from'], $merge['into']);

            return [
                "{$merge['from']->id} {$merge['from']->name}", "{$merge['into']->id} {$merge['into']->name}",
                "{$c['fdts_merged']} / {$c['fdts_moved']}", "{$c['slots_merged']} / {$c['slots_moved']}", "{$c['ports_merged']} / {$c['ports_moved']}",
                $c['customers'], $c['tickets'], $c['outages'],
            ];
        }, $merges);

        $this->table(['Duplicate (removed)', 'Kept', 'FDTs merged / moved', 'Slots merged / moved', 'Ports merged / moved', 'Customers', 'Tickets', 'Outages'], $rows);
        $this->line('Merged: the same name already exists under the OLT kept. Moved: only the duplicate had it; it goes across as it is.');

        if (! $this->option('apply')) {
            $this->warn('Preview only — nothing changed. Take a database backup, then run again with --apply.');

            return self::SUCCESS;
        }

        AuditContext::as('system:merge-olts', fn () => DB::transaction(function () use ($merges, $merger): void {
            foreach ($merges as $merge) {
                $merger->merge($merge['from'], $merge['into']->refresh());
            }
        }));

        $this->info(count($merges).' duplicate OLT(s) merged. Their names are now aliases of the OLTs kept, so the next customer import matches them.');

        return self::SUCCESS;
    }
}
