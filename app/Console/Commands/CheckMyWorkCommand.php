<?php

namespace App\Console\Commands;

use App\Services\Fifo\FifoAssignmentEngine;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('oms:check-my-work {--fix : Repair what is found; without it only the findings are shown}')]
#[Description('Tickets "with" someone that are not in their My Work (no active assignment), and assignments left open on closed tickets')]
class CheckMyWorkCommand extends Command
{
    public function handle(FifoAssignmentEngine $engine): int
    {
        $apply = (bool) $this->option('fix');
        $found = $engine->repairMyWork($apply);

        if ($found['missing'] === [] && $found['closed'] === []) {
            $this->info('Every ticket that is with someone is in their My Work.');

            return self::SUCCESS;
        }

        if ($found['missing'] !== []) {
            $this->warn(count($found['missing']).' ticket(s) with someone but not in their My Work:');
            $this->table(['Ticket', 'Desk', 'With', 'Fix'], array_map(fn (array $row) => [
                $row['outage']->ticket_number, $row['outage']->current_queue?->getLabel(), $row['user']?->name ?? '?', $row['fix'],
            ], $found['missing']));
        }

        if ($found['closed'] !== []) {
            $this->warn(count($found['closed']).' assignment(s) still in My Work on closed tickets (closed by the fix):');
            $this->table(['Ticket', 'Desk', 'Person'], array_map(fn ($assignment) => [
                $assignment->outage?->ticket_number, $assignment->queue_type?->getLabel(), $assignment->user?->name,
            ], $found['closed']));
        }

        $apply ? $this->info('Repaired.') : $this->line('Nothing changed. Run again with --fix to repair (it also runs on its own every 10 minutes).');

        return self::SUCCESS;
    }
}
