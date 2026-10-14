<?php

namespace App\Services\Outages\Concerns;

use App\Enums\NotificationEvent;
use App\Events\OutageUpdated;
use App\Models\Outage;
use App\Models\OutageAssignment;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorTemplate;
use App\Services\Mobility\VendorNotifier;
use App\Support\AuditContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Customer tickets of the 4G vertical, beyond what every customer ticket does:
 *
 *   CX Tier II → 4G NOC → IP / RF / Core team → (their vendor) → 4G NOC validates → CX Tier II
 *   confirms with the customer and closes.
 *
 * The desks hand the ticket on with the ordinary escalate / return actions (desk routes); this adds
 * what the engineering teams do with their vendors — the vendor is e-mailed from the editable vendor
 * templates, reminded when silent, and told when the ticket closes — and keeps everyone posted: an
 * update on a 4G ticket reaches everyone involved, and whoever holds it is reminded when nothing has
 * been said for a while (App\Services\Tickets\FourGTicketMonitor).
 */
trait ManagesFourGTickets
{
    /**
     * The IP, RF or Core team holding the ticket calls in one of its vendors. The ticket stays with the
     * team, which records the vendor's feedback and then sends it to the 4G NOC to validate.
     */
    public function escalateTicketToVendor(Outage $outage, User $by, int $vendorId, string $note): Outage
    {
        $this->requireText($note, 'note', 'Say what the vendor is asked to do.');

        $result = DB::transaction(function () use ($outage, $by, $vendorId, $note): Outage {
            $outage = $this->lock($outage);
            Gate::forUser($by)->authorize('ticketVendorEscalate', $outage);
            $ticket = $this->ticketOf($outage);
            $this->fifo->authorizeWork($outage, $ticket->desk, $by);

            $vendor = Vendor::query()->active()->where('vendor_type', $ticket->desk->vendorType())->find($vendorId)
                ?? throw ValidationException::withMessages(['vendor_id' => "Choose one of the {$ticket->desk->getLabel()}'s vendors (4G NOC → Vendors)."]);

            $changed = $outage->vendor_id !== null && (int) $outage->vendor_id !== $vendor->id;
            $outage->forceFill(['vendor_id' => $vendor->id])->save();
            $ticket->forceFill(['vendor_notified_at' => now(), 'vendor_feedback_at' => null, 'vendor_reminded_at' => null])->save();

            $text = "Escalated to vendor {$vendor->name} by the {$ticket->desk->getLabel()}: {$note}";
            $this->ticketEvent($outage, 'vendor_escalated', $by, $ticket->desk, Str::limit($note, 500), ['vendor' => $vendor->name]);
            $this->ticketUpdate($outage, $text, $by, 'escalation', 'vendor_escalated');
            $outage->forceFill(['latest_update' => Str::limit($text, 2000)])->save();

            AuditContext::as('system:vendor-mail', fn () => app(VendorNotifier::class)->send(
                $changed ? VendorTemplate::VENDOR_CHANGED : VendorTemplate::TICKET_CREATED, $outage->refresh(), ['reason' => $note], fallback: VendorTemplate::TICKET_CREATED,
            ));

            $this->tellTicketUsers(NotificationEvent::TicketVendorEscalated, $outage, $this->involvedInTicket($outage), ['vendor' => $vendor->name, 'reason' => $note]);
            event(new OutageUpdated($outage->id, $outage->ticket_number));

            return $outage->refresh();
        });

        return $result->refresh();
    }

    /** What the vendor came back with, written on the ticket by the team (from an e-mail, a call…). */
    public function recordTicketVendorFeedback(Outage $outage, User $by, string $feedback): Outage
    {
        $this->requireText($feedback, 'feedback', 'Write what the vendor said.');

        $result = DB::transaction(function () use ($outage, $by, $feedback): Outage {
            $outage = $this->lock($outage);
            Gate::forUser($by)->authorize('ticketVendorFeedback', $outage);
            $ticket = $this->ticketOf($outage);
            $this->fifo->authorizeWork($outage, $ticket->desk, $by);

            $vendor = $outage->vendor()->value('name') ?? 'the vendor';
            $ticket->forceFill(['vendor_feedback_at' => now(), 'vendor_reminded_at' => null, 'progress_reminded_at' => null])->save();

            $text = "Vendor feedback ({$vendor}): {$feedback}";
            $this->ticketEvent($outage, 'vendor_feedback', $by, $ticket->desk, Str::limit($feedback, 500), ['vendor' => $vendor]);
            $this->ticketUpdate($outage, $text, $by, 'progress', 'vendor_feedback');
            $outage->forceFill(['latest_update' => Str::limit($text, 2000)])->save();

            $this->tellTicketUsers(NotificationEvent::TicketVendorFeedback, $outage, $this->involvedInTicket($outage), ['vendor' => $vendor, 'reason' => $feedback]);
            event(new OutageUpdated($outage->id, $outage->ticket_number));

            return $outage->refresh();
        });

        return $result->refresh();
    }

    /**
     * An update was posted on a 4G ticket: everyone involved hears of it, and the clock of the next
     * update due starts again.
     */
    private function shareFourGProgress(Outage $outage, string $body): void
    {
        if (! $outage->isFourGTicket()) {
            return;
        }

        $outage->ticket()->update(['progress_reminded_at' => null]);
        $this->tellTicketUsers(NotificationEvent::UpdatePosted, $outage, $this->involvedInTicket($outage), ['reason' => $body]);
    }

    /** A 4G ticket that a vendor worked on is closed: the vendor is told. */
    private function closeFourGVendor(Outage $outage): void
    {
        if ($outage->isFourGTicket() && $outage->vendor_id) {
            AuditContext::as('system:vendor-mail', fn () => app(VendorNotifier::class)->send(VendorTemplate::CLOSED, $outage->refresh()));
        }
    }

    /**
     * Everyone involved in a ticket: who raised it, everyone who has held it, who holds it now.
     *
     * @return list<int>
     */
    private function involvedInTicket(Outage $outage): array
    {
        return collect([$outage->created_by, $outage->current_assignee_id])
            ->merge(OutageAssignment::query()->where('outage_id', $outage->id)->pluck('user_id'))
            ->filter()->map(fn ($id) => (int) $id)->unique()->values()->all();
    }
}
