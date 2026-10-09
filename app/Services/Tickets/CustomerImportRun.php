<?php

namespace App\Services\Tickets;

use App\Models\User;
use Filament\Notifications\Events\DatabaseNotificationsSent;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

/**
 * A customer import started from the website. A full customer list (a hundred thousand rows and
 * more) takes a minute or two — longer than a page may take to answer — so the import runs after
 * the page has answered and the person is told the outcome by a notification (the bell).
 * One import at a time.
 */
class CustomerImportRun
{
    public const LOCK = 'oms:customer-import';

    /** Longer than any import; a lock left behind by a stopped server frees itself after this. */
    public const LOCK_SECONDS = 1800;

    public function __construct(private readonly CustomerImporter $importer) {}

    /** Null when another import is still running. */
    public static function lock(): ?Lock
    {
        $lock = Cache::lock(self::LOCK, self::LOCK_SECONDS);

        return $lock->get() ? $lock : null;
    }

    /**
     * @param  string  $file  path on the private "local" disk; deleted when the import is over
     */
    public function run(string $file, bool $dryRun, bool $keepIncomplete, User $user, ?Lock $lock = null): void
    {
        // The page has answered already: closing the browser must not stop the import half-way.
        ignore_user_abort(true);
        $disk = Storage::disk('local');

        try {
            $outcome = self::outcome($this->importer->import($disk->path($file), $dryRun, $keepIncomplete));
        } catch (InvalidArgumentException $exception) {
            $outcome = ['title' => 'Customer import failed', 'lines' => [$exception->getMessage()], 'level' => 'danger'];
        } catch (Throwable $exception) {
            report($exception);
            $outcome = ['title' => 'Customer import failed', 'lines' => ['The import stopped unexpectedly and nothing was saved. The details are in the application log.'], 'level' => 'danger'];
        } finally {
            $disk->delete($file);
            $lock?->release();
        }

        // The import as a whole is on the audit trail (each customer it created or changed has its own entry).
        $user->audit($dryRun ? 'customers_import_previewed' : 'customers_imported', ['result' => $outcome['title'], 'details' => Str::limit(implode(' ', $outcome['lines']), 600)], [], $user->id);

        $user->notifyNow(self::notification($outcome)->toDatabase());
        DatabaseNotificationsSent::dispatch($user);
    }

    /**
     * What an import report means, in words: for the notification and for the command line.
     *
     * @param  array<string, mixed>  $report  of {@see CustomerImporter::import()}
     * @return array{title: string, lines: list<string>, level: string}
     */
    public static function outcome(array $report): array
    {
        if ($report['errors'] !== []) {
            $listed = array_slice($report['errors'], 0, 15);
            $more = $report['error_rows'] - count($listed);
            $gaps = collect($report['errors'])->contains(fn (string $error) => str_contains($error, ' without a'));

            return [
                'title' => 'Nothing imported — '.number_format($report['error_rows']).' of '.number_format($report['rows']).' rows need fixing',
                'lines' => [
                    ...$listed,
                    ...($more > 0 ? ['… and '.number_format($more).' more.'] : []),
                    ...($gaps ? ['Rows with a slot but no FDT (or a port but no slot) can be imported anyway: choose “Import those customers with the part that is complete”.'] : []),
                ],
                'level' => 'danger',
            ];
        }

        $topology = $report['topology'];
        $number = fn (string $key) => number_format($report[$key]);

        return [
            'title' => $report['dry_run'] ? 'PREVIEW ONLY — nothing was saved' : 'Customers imported and saved',
            'lines' => [
                "{$number('rows')} rows: {$number('created')} new customers, {$number('updated')} updated, {$number('unchanged')} unchanged.",
                'New on the network: '.number_format($topology['olts']).' OLTs, '.number_format($topology['fdts']).' FDTs, '
                    .number_format($topology['slots']).' slots, '.number_format($topology['ports']).' ports'
                    .($report['regions'] ? ", {$number('regions')} regions" : '').'.',
                ...($report['incomplete'] ? ["{$number('incomplete')} customers have an incomplete position in the file (for example a slot without an FDT): they were ".($report['dry_run'] ? 'kept' : 'saved').' with the part that is complete.'] : []),
                ...($report['shortened'] ? ["{$number('shortened')} value(s) were longer than the field holds (a name over 190 characters, for example) and were cut to fit."] : []),
                "{$number('tickets_linked')} earlier ticket(s) linked to their customer.",
                ...($report['dry_run'] ? ['Run it again and choose “Import” to save.'] : []),
            ],
            'level' => $report['dry_run'] ? 'warning' : 'success',
        ];
    }

    /** @param array{title: string, lines: list<string>, level: string} $outcome */
    public static function notification(array $outcome): Notification
    {
        return Notification::make()
            ->title($outcome['title'])
            ->body(implode('<br>', array_map('e', $outcome['lines'])))
            ->color($outcome['level'])
            ->icon(match ($outcome['level']) {
                'success' => 'heroicon-o-check-circle',
                'warning' => 'heroicon-o-eye',
                default => 'heroicon-o-x-circle',
            })
            ->iconColor($outcome['level']);
    }
}
