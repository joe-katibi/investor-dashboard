<?php

namespace App\Console\Commands;

use App\Services\Performance\HistoryImporter;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('oms:import-history
    {--incidents= : The incident log (.xlsx), e.g. "INCIDENTS JAN TO SEPT 2026.xlsx"}
    {--tickets= : The ticketing workbook (.xlsx) with its "Ticket Tracker" tab}
    {--technicians=* : Technician list(s) (.xlsx): NAMES, Username, Role, Department, Team, CONTACT, Mail, Type}
    {--rescore : Only score the imported rows again against the SLA as configured now (after changing the SLA)}
    {--folder= : Import everything from one folder: incidents.xlsx, tickets.xlsx and technicians*.xlsx (e.g. storage/app/imports)}')]
#[Description('Load the real history: incidents as outages, tracker tickets as site visits, technicians as accounts (safe to re-run: rows of the same source are replaced)')]
class ImportHistoryCommand extends Command
{
    public function handle(HistoryImporter $importer): int
    {
        if ($folder = $this->option('folder')) {
            $folder = rtrim(str_replace('\\', '/', $folder), '/');
            if (! is_dir($folder)) {
                $this->error("No folder {$folder}.");

                return self::FAILURE;
            }

            $this->input->setOption('incidents', $this->option('incidents') ?: (is_file("{$folder}/incidents.xlsx") ? "{$folder}/incidents.xlsx" : null));
            $this->input->setOption('tickets', $this->option('tickets') ?: (is_file("{$folder}/tickets.xlsx") ? "{$folder}/tickets.xlsx" : null));
            $this->input->setOption('technicians', $this->option('technicians') ?: (glob("{$folder}/technicians*.xlsx") ?: []));
        }

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

        if ($this->option('incidents') || $this->option('tickets') || $this->option('rescore')) {
            $r = $importer->rescore();
            foreach ($r as $kind => $states) {
                $met = $states['met'] ?? 0;
                $measured = $met + ($states['missed'] ?? 0) + ($states['breached'] ?? 0);
                $this->info(sprintf('SLA %s: %s of %s within SLA (%s%%), %s not measured.', $kind === 'outages' ? 'outages' : 'site visits',
                    number_format($met), number_format($measured), $measured ? number_format(100 * $met / $measured, 1) : '—', number_format($states['not_applicable'] ?? 0)));
            }
        }

        return self::SUCCESS;
    }
}
