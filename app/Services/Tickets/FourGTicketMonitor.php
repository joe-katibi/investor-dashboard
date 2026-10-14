<?php

namespace App\Services\Tickets;

use App\Enums\NotificationChannel;
use App\Enums\NotificationEvent;
use App\Models\Outage;
use App\Models\OutageUpdate;
use App\Models\Ticket;
use App\Models\User;
use App\Models\VendorTemplate;
use App\Models\Vertical;
use App\Services\Mobility\VendorNotifier;
use App\Services\Notifications\NotificationDispatcher;
use App\Support\AuditContext;
use App\Support\Settings;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Keeps 4G customer tickets moving, every minute (oms:ticket-progress):
 *
 *   - an update is due at every step: when nothing has been posted on an open 4G ticket for the
 *     set time (20 minutes by default), whoever holds it — or the people of its desk, while nobody
 *     does — is reminded, and again after each further stretch of silence;
 *   - a vendor called in by the IP, RF or Core team that has said nothing for the set time (60
 *     minutes by default) is reminded by e-mail, while the ticket is still with that team.
 */
class FourGTicketMonitor
{
    public function __construct(
        private readonly Settings $settings,
        private readonly NotificationDispatcher $notifier,
        private readonly VendorNotifier $vendors,
    ) {}

    /** @return array{updates_due: int, vendors_reminded: int} */
    public function check(): array
    {
        $fourG = Vertical::idFor(Vertical::MOBILITY_4G);
        $counts = ['updates_due' => 0, 'vendors_reminded' => 0];

        if (! $fourG) {
            return $counts;
        }

        $updateMinutes = $this->settings->int('ticketing.mobility_update_minutes');
        $vendorMinutes = $this->settings->int('ticketing.mobility_vendor_reminder_minutes');

        Outage::query()->cxTickets()->active()->where('vertical_id', $fourG)->whereNotNull('current_queue')
            ->with('ticket')
            ->chunkById(200, function ($tickets) use ($updateMinutes, $vendorMinutes, &$counts): void {
                foreach ($tickets as $outage) {
                    $ticket = $outage->ticket;

                    if (! $ticket) {
                        continue;
                    }

                    if ($updateMinutes > 0 && $this->updateDue($outage, $ticket, $updateMinutes)) {
                        $counts['updates_due']++;
                    }

                    if ($vendorMinutes > 0 && $this->remindVendor($outage, $ticket, $vendorMinutes)) {
                        $counts['vendors_reminded']++;
                    }
                }
            });

        return $counts;
    }

    /** Nothing said for $minutes since the last update, the arrival at the desk or the last reminder: remind. */
    private function updateDue(Outage $outage, Ticket $ticket, int $minutes): bool
    {
        $lastUpdate = OutageUpdate::query()->where('outage_id', $outage->id)->whereNotNull('user_id')->max('created_at');
        $since = $this->latest([$lastUpdate, $ticket->desk_since, $ticket->progress_reminded_at, $outage->created_at]);

        if ($since === null || $since->greaterThan(now()->subMinutes($minutes))) {
            return false;
        }

        $people = $outage->current_assignee_id
            ? User::query()->active()->whereKey($outage->current_assignee_id)->get()
            : User::permission($outage->current_queue->defaultPermission())->active()->get();

        AuditContext::as('system:ticket-progress', fn () => $this->notifier->notifyUsers(
            NotificationEvent::TicketProgressDue, $outage, $people, [NotificationChannel::InApp->value],
            ['minutes' => (int) $since->diffInMinutes(now()), 'queue' => $outage->current_queue->getLabel(),
                'reason' => 'No update for '.(int) $since->diffInMinutes(now()).' minutes at '.$outage->current_queue->getLabel().'. Post an update on the progress.'],
        ));

        $ticket->forceFill(['progress_reminded_at' => now()])->save();

        return true;
    }

    /** The vendor of a ticket still with its engineering team has said nothing for $minutes: an e-mail reminder. */
    private function remindVendor(Outage $outage, Ticket $ticket, int $minutes): bool
    {
        if (! $outage->vendor_id || ! $outage->current_queue->isEngineeringTeam() || $ticket->vendor_notified_at === null) {
            return false;
        }

        $since = $this->latest([$ticket->vendor_notified_at, $ticket->vendor_feedback_at, $ticket->vendor_reminded_at]);

        if ($since === null || $since->greaterThan(now()->subMinutes($minutes))) {
            return false;
        }

        AuditContext::as('system:vendor-mail', fn () => $this->vendors->send(VendorTemplate::REMINDER, $outage, [
            'reason' => 'No update received for '.(int) $since->diffInMinutes(now()).' minutes.',
        ]));

        $ticket->forceFill(['vendor_reminded_at' => now()])->save();

        return true;
    }

    /** @param list<mixed> $moments */
    private function latest(array $moments): ?CarbonInterface
    {
        return collect($moments)->filter()->map(fn ($moment) => Carbon::parse($moment))->sortDesc()->first();
    }
}
