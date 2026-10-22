<?php

namespace App\Console\Commands;

use App\Services\Performance\HistoryImporter;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('oms:import-history
    {--incidents= : The incident log (.xlsx), e.g. "INCIDENTS JAN TO SEPT 2026.xlsx"}
    {--tickets= : The ticketing workbook (.xlsx) with its "Ticket Tracker" tab}
    {--technicians=* : Technician list(s) (.xlsx): NAMES, Username, Role, Department, Team, CONTACT, Mail, Type}')]
#[Description('Load the real history: incidents as outages, tracker tickets as site visits, technicians as accounts (safe to re-run: rows of the same source are replaced)')]
class ImportHistoryCommand extends Command
{
    public function handle(HistoryImporter $importer): int
    {
        if ($files = (array) $this->option('technicians')) {
            $r = $importer->technicians(...$files);
            $this->info("Technicians: {$r['people']} people — {$r['created']} accounts created, {$r['updated']} updated.");
            $this->line('  Each new account has a random password: the person sets their own with "Forgot your password?". The lists\' passwords were not used.');
            if ($r['unknown_role'] || $r['unknown_team']) {
                $this->warn("  {$r['unknown_role']} without a known role, {$r['unknown_team']} without a known team.");
            }
        }

        if ($file = $this->option('incidents')) {
            $r = $importer->incidents($file);
            $this->info("Incidents: {$r['imported']} of {$r['rows']} rows imported as outages ({$r['open']} have no valid clearance time and are not counted as restored; {$r['no_times']} without any date left out).");
        }

        if ($file = $this->option('tickets')) {
            $this->line('Reading the ticket tracker (a large file — a minute or two)…');
            $r = $importer->tickets($file);
            $this->info("Tickets: {$r['imported']} site visits imported ({$r['open']} open), {$r['duplicates']} duplicates left out, {$r['unmeasured']} without usable times (not counted in SLA figures).");
        }

        return self::SUCCESS;
    }
}
