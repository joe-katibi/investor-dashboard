<?php

namespace App\Console\Commands;

use App\Services\Users\UserImporter;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use InvalidArgumentException;

#[Signature('oms:import-users {file : Path to the .xlsx / .csv staff list. Only an e-mail column is needed; optional: Name, Username, Password, Department, Roles, Team, Status, Phone}
    {--preview : Check the file and show what would happen; nothing is saved}
    {--department= : Department for rows that do not name one (name or code, e.g. "Customer Experience" or CX)}
    {--role= : Role(s) for rows that do not name any, separated by commas (e.g. "CX Agent")}
    {--team= : Team(s) for rows that do not name any, separated by commas}
    {--random-passwords : Ignore the Password column: every new account gets a long random password and the person sets their own with "Forgot your password?" on the sign-in page}
    {--allow-weak-passwords : Accept passwords from the file that do not meet the system\'s password rule}
    {--skip-existing : Leave out rows whose username already belongs to an account with another e-mail address (as a rule the same person, registered earlier) instead of stopping; they are listed}
    {--update : Also update people who already have an account (name, username, phone, department, status from the file; roles and teams are added). Their password is never changed}')]
#[Description('Create user accounts from a staff list of e-mail addresses — the name and username are read from the address when the file does not give them (safe to re-run)')]
class ImportUsersCommand extends Command
{
    public function handle(UserImporter $importer): int
    {
        try {
            $report = $importer->import((string) $this->argument('file'), [
                'dry_run' => (bool) $this->option('preview'),
                'update' => (bool) $this->option('update'),
                'department' => $this->option('department'),
                'roles' => $this->option('role'),
                'teams' => $this->option('team'),
                'random_passwords' => (bool) $this->option('random-passwords'),
                'allow_weak_passwords' => (bool) $this->option('allow-weak-passwords'),
                'skip_taken_usernames' => (bool) $this->option('skip-existing'),
            ]);
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        if ($report['error_rows'] > 0) {
            $this->error("Nothing imported — {$report['error_rows']} of {$report['rows']} rows need fixing:");
            foreach (array_slice($report['errors'], 0, 25) as $error) {
                $this->line('  '.$error);
            }

            if ($report['error_rows'] > 25) {
                $this->line('  … and '.($report['error_rows'] - 25).' more.');
                $this->line('  Kinds of problem:');
                foreach (collect($report['errors'])->flatMap(fn (string $error) => explode('; ', (string) preg_replace('/^Row \d+: /', '', $error)))->countBy()->sortDesc() as $problem => $count) {
                    $this->line("    {$count} × {$problem}");
                }
            }

            if (collect($report['errors'])->contains(fn (string $error) => str_contains($error, 'is already used by another person'))) {
                $this->newLine();
                $this->line('  A username that is already used is, as a rule, the same person registered earlier under another address:');
                $this->line('    --skip-existing          leave those rows out and import the rest.');
            }

            if ($report['weak_passwords'] > 0) {
                $this->newLine();
                $this->line("  {$report['weak_passwords']} password(s) in the file do not meet this system's password rule. Either:");
                $this->line('    --random-passwords       each person sets their own with "Forgot your password?" (recommended), or');
                $this->line('    --allow-weak-passwords   use the passwords of the file as they are.');
            }

            return self::FAILURE;
        }

        $this->info($report['dry_run'] ? 'PREVIEW ONLY — nothing was saved.' : 'Users imported.');
        $this->line("  {$report['rows']} people in the file: {$report['created']} account(s) ".($report['dry_run'] ? 'would be created' : 'created')
            .", {$report['updated']} updated, {$report['skipped']} already in the system and left as they are.");

        if ($report['skipped_usernames'] !== []) {
            $this->line('  Left out, because the username already belongs to an account with another e-mail address ('.count($report['skipped_usernames']).'):');
            foreach ($report['skipped_usernames'] as $skipped) {
                $this->line('    '.$skipped);
            }
        }

        if ($report['names_from_email'] || $report['usernames_made']) {
            $this->line("  {$report['names_from_email']} name(s) and {$report['usernames_made']} username(s) were read from the e-mail address.");
        }

        if ($report['random_passwords']) {
            $this->line("  {$report['random_passwords']} account(s) have a random password nobody knows: each person opens the sign-in page, chooses “Forgot your password?” and sets their own (outgoing e-mail must work).");
        }

        if ($report['weak_passwords']) {
            $this->warn("  {$report['weak_passwords']} password(s) from the file do not meet the system's password rule and were accepted as they are. Ask these people to change theirs (Profile) after the first sign-in.");
        }

        if (! $report['dry_run'] && $report['created'] && ! $report['random_passwords']) {
            $this->line('  The passwords now live in the system only as hashes. The file still holds them in the clear: delete it, or at least its Password column.');
        }

        if ($report['dry_run']) {
            $this->line('  Run it again without --preview to save.');
        }

        return self::SUCCESS;
    }
}
