<?php

namespace App\Services\Outages\Concerns;

use App\Enums\AssignmentStatus;
use App\Enums\AssignmentType;
use App\Enums\AvailabilityStatus;
use App\Enums\NotificationChannel;
use App\Enums\NotificationEvent;
use App\Enums\QueueType;
use App\Enums\SlaState;
use App\Enums\StatusCode;
use App\Events\OutageCreated;
use App\Events\OutageUpdated;
use App\Jobs\SendNotificationJob;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\DelayReason;
use App\Models\DeskRoute;
use App\Models\FinalReason;
use App\Models\MajorOutageType;
use App\Models\NotificationLog;
use App\Models\Olt;
use App\Models\Outage;
use App\Models\OutageAttachment;
use App\Models\OutageReopening;
use App\Models\OutageStatus;
use App\Models\OutageType;
use App\Models\OutageUpdate;
use App\Models\Ticket;
use App\Models\TicketDeskMove;
use App\Models\TicketEvent;
use App\Models\TicketFollowUp;
use App\Models\TicketSubcategory;
use App\Models\User;
use App\Models\Vertical;
use App\Models\VisitType;
use App\Services\Fifo\EligibilityEvaluator;
use App\Services\Geo\PhotoLocation;
use App\Services\Notifications\Sms\PhoneNumber;
use App\Services\Tickets\OutageLocator;
use App\Support\AuditContext;
use App\Support\Permissions;
use App\Support\Settings;
use App\Support\VisitPhotos;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Customer tickets and site visits.
 *
 * A ticket always sits at one DESK (a FIFO queue): the people holding that desk's permission get
 * it first-in-first-out, and move it on by hand along the configured desk routes.
 *
 *   CUSTOMER TICKET  CX raises it → the subcategory's first desk (CX Tier II, Billing, Commercial…)
 *   → escalate / return between desks (Billing Manager, NOC, CX call-back…), approve or decline
 *   refunds and credits → close with a final reason, or raise a SITE VISIT.
 *
 *   SITE VISIT  Dispatcher → technician (named, or FIFO) → the customer is texted → en route →
 *   on premise → completed with FAT number + photos → dispatcher verifies → CX Field Support
 *   confirms with the customer → closed. A visit that cannot be completed goes back to the
 *   dispatcher, who escalates it (Infrastructure Support, Design, NOC, Access) or sends it to
 *   CX Field Support (unreachable, reschedule, call back).
 *
 * Every step lands on the ticket timeline, the structured event log (TAT reports) and the audit trail.
 */
trait ManagesTickets
{
    // ------------------------------------------------------------------ create

    /**
     * @param  array<string, mixed>  $data  subcategory_id, account_number, customer_name, calling_number, primary_number, alt_number_1,
     *                                      alt_number_2, address, landmark, region_id, olt_id, fdt_id, slot_id, port_id, bandwidth, description,
     *                                      ticket_type, source, external_reference, preferred_time, amount, reported_at
     */
    public function createTicket(array $data, User $by): Outage
    {
        Gate::forUser($by)->authorize('createTicket', Outage::class);

        $subcategory = TicketSubcategory::query()->active()->with('category')->find($data['subcategory_id'] ?? 0)
            ?? throw ValidationException::withMessages(['subcategory_id' => 'Select what the customer reported (category and subcategory).']);

        $this->requireText($data['calling_number'] ?? ($data['primary_number'] ?? null), 'calling_number', 'Enter the number the customer is calling from (or their primary number).');
        $reported = $this->pastMoment($data['reported_at'] ?? null, 'reported_at');
        $verticalId = $this->ticketVertical($data, $subcategory);

        $result = DB::transaction(function () use ($data, $by, $subcategory, $reported, $verticalId): Outage {
            $type = $this->ticketType(OutageType::CODE_CX_TICKET);
            $fields = $this->ticketCustomerFields(['vertical_id' => $verticalId] + $data, $by);
            $who = trim(($fields['customer_name'] ?: 'Customer').' '.($fields['account_number'] ? "({$fields['account_number']})" : ''));

            $outage = $this->newTicketOutage($type, $by, [
                'title' => Str::limit("{$subcategory->name} — {$who}", 250, ''),
                'description' => $data['description'] ?? null,
                'location' => Str::limit(collect([$fields['address'], $fields['landmark']])->filter()->implode(' — '), 250, '') ?: null,
                'priority_id' => $subcategory->priority_id,
                'start_time' => $reported,
                'ticket_number' => $this->tickets->next($type->prefix, OutageType::TICKET_DIGITS),
                // FTTH, 4G…: which desks, categories and reasons the ticket follows.
                'vertical_id' => $verticalId,
            ], StatusCode::TicketOpen);

            $ticket = Ticket::query()->create($fields + [
                'outage_id' => $outage->id,
                'category_id' => $subcategory->ticket_category_id,
                'subcategory_id' => $subcategory->id,
                'ticket_type' => array_key_exists($data['ticket_type'] ?? '', Ticket::TYPES) ? $data['ticket_type'] : 'reactive',
                'source' => array_key_exists($data['source'] ?? '', Ticket::SOURCES) ? $data['source'] : null,
                'external_reference' => filled($data['external_reference'] ?? null) ? trim((string) $data['external_reference']) : null,
                'preferred_time' => filled($data['preferred_time'] ?? null) ? trim((string) $data['preferred_time']) : null,
                'amount' => is_numeric($data['amount'] ?? null) ? $data['amount'] : null,
                'home_desk' => $subcategory->first_desk,
                'approval_state' => $subcategory->first_desk->isApprovalDesk() ? Ticket::APPROVAL_PENDING : null,
            ])->refresh();

            $this->openTicket($outage, $ticket, $by, "Ticket raised: {$subcategory->category?->name} / {$subcategory->name}."
                .(filled($outage->description) ? ' '.Str::limit((string) $outage->description, 300) : ''));
            $this->attachOnRaising($outage, $by, $data);

            if ($subcategory->check_outage) {
                $this->linkOngoingOutage($outage, $ticket, $by);
            }

            $this->moveTicket($outage, $ticket, $subcategory->first_desk, $by, 'route', 'New ticket', announce: false);

            return $outage->refresh();
        });

        return $result->refresh();
    }

    /**
     * @param  array<string, mixed>  $data  visit_type_id, subcategory_id, customer fields (as createTicket), description, scheduled_at,
     *                                      preferred_time, technician_id, team_id, ticket_type, source, external_reference, reported_at
     */
    public function createSiteVisit(array $data, User $by, ?Outage $parent = null): Outage
    {
        Gate::forUser($by)->authorize('createVisit', Outage::class);

        $visitType = VisitType::query()->active()->find($data['visit_type_id'] ?? 0)
            ?? throw ValidationException::withMessages(['visit_type_id' => 'Select the type of site visit.']);
        $subcategory = filled($data['subcategory_id'] ?? null) ? TicketSubcategory::query()->with('category')->find($data['subcategory_id']) : null;

        $this->requireText($data['calling_number'] ?? ($data['primary_number'] ?? null), 'calling_number', 'A contact number for the customer is required to book a visit.');
        $scheduled = $this->visitMoment($data['scheduled_at'] ?? null);
        $reported = $this->pastMoment($data['reported_at'] ?? null, 'reported_at');

        $result = DB::transaction(function () use ($data, $by, $parent, $visitType, $subcategory, $scheduled, $reported): Outage {
            $type = $this->ticketType(OutageType::CODE_SITE_VISIT);
            $fields = $this->ticketCustomerFields($data, $by);
            $who = trim(($fields['customer_name'] ?: 'Customer').' '.($fields['account_number'] ? "({$fields['account_number']})" : ''));

            $outage = $this->newTicketOutage($type, $by, [
                'title' => Str::limit(($subcategory?->name ?? $visitType->name)." — {$who}", 250, ''),
                'description' => $data['description'] ?? null,
                'location' => Str::limit(collect([$fields['address'], $fields['landmark']])->filter()->implode(' — '), 250, '') ?: null,
                'priority_id' => $visitType->priority_id ?? $subcategory?->priority_id,
                'start_time' => $reported,
                // INS-1, WiE-1, SUP-1, SHI-1: a locked counter per visit type, never MAX(id) + 1.
                'ticket_number' => $this->tickets->next($visitType->code_prefix, 1, keepCase: true),
            ], StatusCode::VisitOpen);

            $ticket = Ticket::query()->create($fields + [
                'outage_id' => $outage->id,
                'category_id' => $subcategory?->ticket_category_id,
                'subcategory_id' => $subcategory?->id,
                'visit_type_id' => $visitType->id,
                'ticket_type' => array_key_exists($data['ticket_type'] ?? '', Ticket::TYPES) ? $data['ticket_type'] : 'reactive',
                'source' => array_key_exists($data['source'] ?? '', Ticket::SOURCES) ? $data['source'] : null,
                'external_reference' => filled($data['external_reference'] ?? null) ? trim((string) $data['external_reference']) : null,
                'preferred_time' => filled($data['preferred_time'] ?? null) ? trim((string) $data['preferred_time']) : null,
                'scheduled_at' => $scheduled,
                'parent_outage_id' => $parent?->id,
                'home_desk' => $visitType->dispatcherPermission() ? QueueType::ShiftingDispatch : QueueType::VisitDispatch,
            ])->refresh();

            $this->openTicket($outage, $ticket, $by, "{$visitType->name} visit raised".($subcategory ? " for “{$subcategory->name}”" : '')
                .($parent ? " from ticket {$parent->ticket_number}" : '')
                .($scheduled ? ', booked for '.$scheduled->format('d M Y H:i') : ($ticket->preferred_time ? ", customer prefers: {$ticket->preferred_time}" : '')).'.'
                .(filled($outage->description) ? ' '.Str::limit((string) $outage->description, 300) : ''));
            $this->attachOnRaising($outage, $by, $data);

            if ($subcategory?->check_outage) {
                $this->linkOngoingOutage($outage, $ticket, $by);
            }

            $this->moveTicket($outage, $ticket, QueueType::VisitDispatch, $by, 'route', 'New site visit', announce: false);

            // Booked straight to a technician / team by someone who may dispatch (this kind of visit).
            if ((filled($data['technician_id'] ?? null) || filled($data['team_id'] ?? null)) && $by->can('visit.dispatch')) {
                if (Gate::forUser($by)->denies('visitAssign', $outage->refresh())) {
                    $needs = EligibilityEvaluator::visitDispatcherPermission($outage);
                    throw ValidationException::withMessages(['technician_id' => 'This kind of visit is dispatched only by dispatchers with “'.Permissions::label((string) $needs)
                        .'”. Leave the technician and team empty: one of them assigns it.']);
                }

                $this->dispatchVisit($outage, $ticket->refresh(), $by, $data);
            }

            return $outage->refresh();
        });

        return $result->refresh();
    }

    /** CX Tier II (or whoever holds the ticket) books a site visit for a customer ticket; the ticket waits for it. */
    public function raiseSiteVisit(Outage $outage, User $by, array $data): Outage
    {
        $visit = DB::transaction(function () use ($outage, $by, $data): Outage {
            $outage = $this->lock($outage);
            Gate::forUser($by)->authorize('ticketRaiseVisit', $outage);
            $ticket = $this->ticketOf($outage);

            if ($ticket->desk) {
                $this->fifo->authorizeWork($outage, $ticket->desk, $by);
            }

            $visit = $this->createSiteVisit(array_merge([
                'account_number' => $ticket->account_number, 'customer_name' => $ticket->customer_name, 'calling_number' => $ticket->calling_number,
                'primary_number' => $ticket->primary_number, 'alt_number_1' => $ticket->alt_number_1, 'alt_number_2' => $ticket->alt_number_2,
                'address' => $ticket->address, 'landmark' => $ticket->landmark, 'region_id' => $ticket->region_id, 'olt_id' => $ticket->olt_id,
                'fdt_id' => $ticket->fdt_id, 'slot_id' => $ticket->slot_id, 'port_id' => $ticket->port_id, 'bandwidth' => $ticket->bandwidth,
                'subcategory_id' => $ticket->subcategory_id, 'ticket_type' => $ticket->ticket_type, 'source' => $ticket->source,
                'external_reference' => $ticket->external_reference, 'description' => $outage->description,
            ], array_filter($data, fn ($value) => $value !== null && $value !== '')), $by, $outage);

            // The customer ticket leaves its desk and waits for the visit; it closes with it.
            if ($ticket->desk) {
                $this->fifo->complete($outage, $ticket->desk, $by, "Site visit {$visit->ticket_number} raised");
            }

            $ticket->forceFill(['desk' => null, 'desk_since' => now()])->save();
            $this->transition($outage, StatusCode::TicketSiteVisit, $by, "Site visit {$visit->ticket_number} raised");
            $this->ticketEvent($outage, 'site_visit_raised', $by, note: $visit->ticket_number);
            $this->ticketUpdate($outage, "Site visit {$visit->ticket_number} raised".(filled($data['note'] ?? null) ? ": {$data['note']}" : '.').' This ticket closes when the visit is completed and confirmed.', $by, 'action', 'site_visit_raised');
            $this->notifier->dispatch(NotificationEvent::SiteVisitRaised, $outage, ['reason' => "Site visit {$visit->ticket_number}"]);

            return $visit;
        });

        return $visit->refresh();
    }

    // ------------------------------------------------------------------ desk to desk

    /** Escalate / hand the ticket to another desk (allowed by the desk routes), optionally to a named person there. */
    /**
     * @param  array{0?: ?string, 1?: ?string, 2?: ?array}  $photo  a photo taken on site [path, original name, position] (the app's escalation to Infrastructure Support)
     */
    public function routeTicket(Outage $outage, User $by, QueueType $to, string $note, ?int $delayReasonId = null, ?int $userId = null, array $photo = []): Outage
    {
        $this->requireText($note, 'note', 'Say why the ticket is handed over.');

        $result = DB::transaction(function () use ($outage, $by, $to, $note, $delayReasonId, $userId, $photo): Outage {
            $outage = $this->lock($outage);
            Gate::forUser($by)->authorize('ticketRoute', $outage);
            $ticket = $this->ticketOf($outage);
            $this->fifo->authorizeWork($outage, $ticket->desk, $by);

            // The desk that approves this kind of request can always be asked, whatever the routes say.
            if (! $to->serves($outage->verticalCode())) {
                throw ValidationException::withMessages(['desk' => "{$to->getLabel()} does not work {$outage->verticalCode()} tickets."]);
            }

            if (! DeskRoute::allows($ticket->desk, $to) && $to !== $ticket->subcategory?->approval_desk) {
                throw ValidationException::withMessages(['desk' => "{$ticket->desk->getLabel()} cannot send a ticket to {$to->getLabel()} (see Customer Care → Desk Routes)."]);
            }

            $target = $userId ? $this->deskUser($userId, $to, $outage) : null;

            if ($to->isApprovalDesk()) {
                $ticket->forceFill(['approval_state' => Ticket::APPROVAL_PENDING, 'approval_by' => null, 'approval_at' => null, 'approval_note' => null]);
            }

            // Handing a visit to Infrastructure is a delay we own: it is recorded as such unless said otherwise.
            $delayReasonId ??= $to === QueueType::InfraSupportDispatch ? DelayReason::idForCode(DelayReason::ESCALATED_INFRA) : null;

            if ($delayReasonId) {
                $ticket->forceFill(['delay_reason_id' => $delayReasonId]);
            }

            // The geotagged photo of the site that goes with an escalation from the app: on the ticket, with where and when.
            if (filled($photo[0] ?? null)) {
                $this->storeTicketPhotos($outage, $by, ['escalation_geo' => [$photo[0], $photo[1] ?? null, 'Escalation to '.$to->getLabel(), $photo[2] ?? null]]);
            }

            $this->moveTicket($outage, $ticket, $to, $by, 'route', $note, ['user' => $target, 'delay_reason_id' => $delayReasonId]);
            $this->notifier->dispatch(NotificationEvent::TicketRouted, $outage, ['reason' => $note, 'queue' => $to->getLabel()]);

            return $outage->refresh();
        });

        return $result->refresh();
    }

    /** Send the ticket back to the desk it came from (work done / advice given). */
    public function returnTicket(Outage $outage, User $by, string $note): Outage
    {
        $this->requireText($note, 'note', 'Say what was done / found before returning the ticket.');

        $result = DB::transaction(function () use ($outage, $by, $note): Outage {
            $outage = $this->lock($outage);
            Gate::forUser($by)->authorize('ticketReturn', $outage);
            $ticket = $this->ticketOf($outage);
            $this->fifo->authorizeWork($outage, $ticket->desk, $by);

            $previous = $this->previousDesk($outage, $ticket)
                ?? throw ValidationException::withMessages(['desk' => 'This ticket was not handed over from another desk, so there is nothing to return it to.']);

            $outage->forceFill(['latest_update' => $note])->save();
            $this->moveTicket($outage, $ticket, $previous['desk'], $by, 'return', $note);
            $this->notifier->dispatch(NotificationEvent::TicketReturned, $outage, ['reason' => $note, 'queue' => $previous['desk']->getLabel()]);
            $this->tellTicketUsers(NotificationEvent::TicketReturned, $outage, [$previous['user_id']], ['reason' => $note]);

            return $outage->refresh();
        });

        return $result->refresh();
    }

    /**
     * Approve a refund / credit / billing request. The ticket goes on to the desk that carries it
     * out (Billing by default), or back to the desk that asked when a Billing Manager approves.
     */
    public function approveTicket(Outage $outage, User $by, ?string $note = null, mixed $amount = null): Outage
    {
        $result = DB::transaction(function () use ($outage, $by, $note, $amount): Outage {
            $outage = $this->lock($outage);
            Gate::forUser($by)->authorize('ticketApprove', $outage);
            $ticket = $this->ticketOf($outage);
            $this->fifo->authorizeWork($outage, $ticket->desk, $by);

            $ticket->forceFill([
                'approval_state' => Ticket::APPROVAL_APPROVED,
                'approval_by' => $by->id,
                'approval_at' => now(),
                'approval_note' => filled($note) ? trim((string) $note) : null,
                'amount' => is_numeric($amount) ? $amount : $ticket->amount,
            ])->save();

            $desk = $ticket->desk;
            $previous = $this->previousDesk($outage, $ticket);
            $next = $desk === QueueType::BillingManager && $previous
                ? $previous['desk']
                : ($ticket->subcategory?->fulfilment_desk ?? QueueType::Billing);

            $text = "Approved by {$by->name} ({$desk->getLabel()})".($ticket->amount !== null ? ', amount '.number_format((float) $ticket->amount, 2) : '').(filled($note) ? ": {$note}" : '.');
            $this->ticketEvent($outage, 'approved', $by, $desk, $note);
            $this->moveTicket($outage, $ticket, $next, $by, 'approve', $text);
            $this->notifier->dispatch(NotificationEvent::TicketApproval, $outage, ['reason' => $text, 'queue' => $next->getLabel()]);
            $this->tellTicketUsers(NotificationEvent::TicketApproval, $outage, [$outage->created_by], ['reason' => $text]);

            return $outage->refresh();
        });

        return $result->refresh();
    }

    /** Decline the request: back to the desk that asked (or the CX call-back desk) to advise the customer and close. */
    public function declineTicket(Outage $outage, User $by, string $reason): Outage
    {
        $this->requireText($reason, 'reason', 'A reason is required to decline.');

        $result = DB::transaction(function () use ($outage, $by, $reason): Outage {
            $outage = $this->lock($outage);
            Gate::forUser($by)->authorize('ticketApprove', $outage);
            $ticket = $this->ticketOf($outage);
            $this->fifo->authorizeWork($outage, $ticket->desk, $by);

            $desk = $ticket->desk;
            $ticket->forceFill(['approval_state' => Ticket::APPROVAL_DECLINED, 'approval_by' => $by->id, 'approval_at' => now(), 'approval_note' => trim($reason)])->save();

            $next = $this->previousDesk($outage, $ticket)['desk'] ?? QueueType::CxCallback;
            $text = "Declined by {$by->name} ({$desk->getLabel()}): {$reason}. Advise the customer and close the ticket.";
            $this->ticketEvent($outage, 'declined', $by, $desk, $reason);
            $this->moveTicket($outage, $ticket, $next, $by, 'decline', $text);
            $this->notifier->dispatch(NotificationEvent::TicketApproval, $outage, ['reason' => $text, 'queue' => $next->getLabel()]);
            $this->tellTicketUsers(NotificationEvent::TicketApproval, $outage, [$outage->created_by], ['reason' => $text]);

            return $outage->refresh();
        });

        return $result->refresh();
    }

    // ------------------------------------------------------------------ customer contact

    /**
     * A call to the customer: outcome and notes. Does not move the ticket (use Return / Close /
     * Reschedule for that); it clears the "pending follow-up" queue item when there is one.
     *
     * @param  array{outcome: string, notes?: ?string, called_at?: mixed, kind?: ?string}  $data
     */
    public function recordTicketFollowUp(Outage $outage, User $by, array $data): TicketFollowUp
    {
        if (! array_key_exists($data['outcome'] ?? '', TicketFollowUp::OUTCOMES)) {
            throw ValidationException::withMessages(['outcome' => 'Select the outcome of the call.']);
        }

        $calledAt = $this->pastMoment($data['called_at'] ?? null, 'called_at');

        return DB::transaction(function () use ($outage, $by, $data, $calledAt): TicketFollowUp {
            $outage = $this->lock($outage);
            Gate::forUser($by)->authorize('ticketFollowUp', $outage);
            $ticket = $this->ticketOf($outage);

            $pending = $outage->openQueueEntries()->where('queue_type', QueueType::TicketFollowUp->value)->exists();
            $kind = array_key_exists($data['kind'] ?? '', TicketFollowUp::KINDS) ? $data['kind'] : ($pending ? 'pending' : 'callback');

            $followUp = TicketFollowUp::query()->create([
                'outage_id' => $outage->id,
                'user_id' => $by->id,
                'kind' => $kind,
                'outcome' => $data['outcome'],
                'notes' => filled($data['notes'] ?? null) ? trim((string) $data['notes']) : null,
                'called_at' => $calledAt,
            ]);

            $ticket->forceFill([
                'followed_up_at' => $calledAt,
                'followed_up_by' => $by->id,
                'follow_up_outcome' => $followUp->outcome,
                'follow_up_count' => $ticket->follow_up_count + 1,
            ]);

            // Nobody answered: that is the delay, unless a reason is already recorded.
            if (! $followUp->reached() && ! $ticket->delay_reason_id) {
                $ticket->forceFill(['delay_reason_id' => DelayReason::idForCode($followUp->outcome === 'unreachable' ? DelayReason::UNREACHABLE : 'D03')]);
            }

            $ticket->save();

            if ($pending) {
                $this->fifo->complete($outage, QueueType::TicketFollowUp, $by, 'Customer follow-up done');
            }

            $summary = 'Customer follow-up — '.$followUp->outcomeLabel().($followUp->notes ? ": {$followUp->notes}" : '.');
            $outage->forceFill(['latest_update' => $summary, 'cx_followed_up_at' => now()])->save();
            $this->ticketEvent($outage, 'follow_up', $by, $ticket->desk, $followUp->outcomeLabel());
            $this->ticketUpdate($outage, $summary, $by, 'action', 'customer_follow_up');
            event(new OutageUpdated($outage->id, $outage->ticket_number));

            return $followUp;
        });
    }

    // ------------------------------------------------------------------ site visit: dispatch

    /**
     * Dispatcher assigns the visit: a named technician, or — without one — the technician FIFO
     * queue (optionally one team). A visit booked for a later day is only offered on that day.
     *
     * @param  array{technician_id?: ?int, team_id?: ?int, scheduled_at?: mixed, note?: ?string}  $data
     */
    public function assignVisit(Outage $outage, User $by, array $data): Outage
    {
        $result = DB::transaction(function () use ($outage, $by, $data): Outage {
            $outage = $this->lock($outage);
            Gate::forUser($by)->authorize('visitAssign', $outage);
            $ticket = $this->ticketOf($outage);

            if ($ticket->desk?->isVisitDispatch()) {
                $this->fifo->authorizeWork($outage, $ticket->desk, $by);
            }

            $this->dispatchVisit($outage, $ticket, $by, $data);

            return $outage->refresh();
        });

        return $result->refresh();
    }

    /**
     * Move the visit to the date / time the customer asked for and text them the new time.
     */
    public function rescheduleVisit(Outage $outage, User $by, mixed $scheduledAt, string $reason, bool $notifyCustomer = true): Outage
    {
        $this->requireText($reason, 'reason', 'Say why the visit is rescheduled.');
        $scheduled = $this->visitMoment($scheduledAt)
            ?? throw ValidationException::withMessages(['scheduled_at' => 'Enter the new date and time of the visit.']);

        $result = DB::transaction(function () use ($outage, $by, $scheduled, $reason, $notifyCustomer): Outage {
            $outage = $this->lock($outage);
            Gate::forUser($by)->authorize('visitReschedule', $outage);
            $ticket = $this->ticketOf($outage);
            $previous = $ticket->scheduled_at;

            $ticket->forceFill([
                'scheduled_at' => $scheduled,
                'rescheduled_at' => now(),
                'reschedule_count' => $ticket->reschedule_count + 1,
                'delay_reason_id' => $ticket->delay_reason_id ?? DelayReason::idForCode(DelayReason::RESCHEDULED),
            ])->save();

            // Waiting in the technician queue: only offered again on the new day.
            $outage->openQueueEntries()->where('queue_type', QueueType::VisitTechnician->value)->where('status', 'pending')
                ->update(['eligible_from' => $scheduled->isToday() ? null : $scheduled->copy()->startOfDay(), 'updated_at' => now()]);

            $text = 'Visit rescheduled'.($previous ? ' from '.$previous->format('d M Y H:i') : '').' to '.$scheduled->format('d M Y H:i').": {$reason}";
            $outage->forceFill(['latest_update' => $text])->save();
            $this->ticketEvent($outage, 'rescheduled', $by, $ticket->desk, $reason, ['from' => $previous?->toDateTimeString(), 'to' => $scheduled->toDateTimeString()]);
            $this->ticketUpdate($outage, $text, $by, 'action', 'rescheduled');

            if ($notifyCustomer) {
                $this->smsTicketCustomer($outage, $ticket, 'ticketing.sms_rescheduled', ['visit_time' => $scheduled->format('D d M Y, H:i')]);
            }

            $this->notifier->dispatch(NotificationEvent::VisitRescheduled, $outage, ['reason' => $text]);
            $this->tellTicketUsers(NotificationEvent::VisitRescheduled, $outage, [$outage->technician_id], ['reason' => $text]);

            // CX Field Support agreed the new time with the customer: the visit goes back to dispatch.
            if ($ticket->desk === QueueType::CxFieldSupport && ($back = $this->previousDesk($outage, $ticket))) {
                $this->moveTicket($outage, $ticket, $back['desk'], $by, 'return', $text);
            } else {
                event(new OutageUpdated($outage->id, $outage->ticket_number));
            }

            return $outage->refresh();
        });

        return $result->refresh();
    }

    // ------------------------------------------------------------------ site visit: technician

    public function visitEnRoute(Outage $outage, User $by, ?string $note = null): Outage
    {
        return $this->visitStep($outage, $by, 'visitEnRoute', function (Outage $outage, Ticket $ticket) use ($by, $note): void {
            $ticket->forceFill(['en_route_at' => now(), 'accepted_at' => $ticket->accepted_at ?? now()])->save();
            $outage->forceFill(['work_started_at' => $outage->work_started_at ?? now(), 'acknowledged_at' => $outage->acknowledged_at ?? now()])->save();
            $this->ticketEvent($outage, 'en_route', $by, $ticket->desk, $note);
            $this->ticketUpdate($outage, 'On the way to the customer.'.($note ? " {$note}" : ''), $by, 'progress', 'en_route');
        });
    }

    public function visitArrive(Outage $outage, User $by, ?string $note = null): Outage
    {
        return $this->visitStep($outage, $by, 'visitArrive', function (Outage $outage, Ticket $ticket) use ($by, $note): void {
            $ticket->forceFill(['arrived_at' => now(), 'en_route_at' => $ticket->en_route_at ?? now(), 'accepted_at' => $ticket->accepted_at ?? now()])->save();
            $outage->forceFill(['work_started_at' => $outage->work_started_at ?? now(), 'acknowledged_at' => $outage->acknowledged_at ?? now()])->save();
            $this->ticketEvent($outage, 'arrived', $by, $ticket->desk, $note);
            $this->ticketUpdate($outage, 'Arrived at the customer premise — work started.'.($note ? " {$note}" : ''), $by, 'progress', 'arrived');
        });
    }

    public function visitPause(Outage $outage, User $by, string $reason): Outage
    {
        $this->requireText($reason, 'reason', 'A reason is mandatory to pause the work.');

        return $this->visitStep($outage, $by, 'visitPause', function (Outage $outage, Ticket $ticket) use ($by, $reason): void {
            $ticket->forceFill(['paused_at' => now(), 'pause_reason' => Str::limit(trim($reason), 250, '')])->save();
            $this->ticketEvent($outage, 'paused', $by, $ticket->desk, $reason);
            $this->ticketUpdate($outage, "Work paused: {$reason}", $by, 'progress', 'paused');
        });
    }

    public function visitResume(Outage $outage, User $by, ?string $note = null): Outage
    {
        return $this->visitStep($outage, $by, 'visitResume', function (Outage $outage, Ticket $ticket) use ($by, $note): void {
            $this->resumeTicketTimer($ticket);
            $ticket->save();
            $this->ticketEvent($outage, 'resumed', $by, $ticket->desk, $note);
            $this->ticketUpdate($outage, 'Work resumed.'.($note ? " {$note}" : ''), $by, 'progress', 'resumed');
        });
    }

    /**
     * Technician: the work is done. Needs the final reason, what was done and — for visit types
     * that ask for evidence — the FAT number, a geolocation photo and a speed-test photo. The visit
     * returns to the dispatcher for the final close.
     *
     * @param  array<string, mixed>  $data  final_reason_id, resolution_notes, fat_number, geo_photo, speed_photo, geo_photo_name,
     *                                      speed_photo_name, onu_type, drop_cable_m, ethernet_m, patch_cord, atb, extender, completed_at
     */
    public function completeVisit(Outage $outage, User $by, array $data): Outage
    {
        $this->requireText($data['resolution_notes'] ?? null, 'resolution_notes', 'Describe the work done.');
        $reason = FinalReason::query()->active()->find($data['final_reason_id'] ?? 0)
            ?? throw ValidationException::withMessages(['final_reason_id' => 'Select the final reason.']);
        $completedAt = $this->pastMoment($data['completed_at'] ?? null, 'completed_at');

        $result = DB::transaction(function () use ($outage, $by, $data, $reason, $completedAt): Outage {
            $outage = $this->lock($outage);
            Gate::forUser($by)->authorize('visitComplete', $outage);
            $ticket = $this->ticketOf($outage);
            $this->reasonOfDesk($reason, $outage->current_queue, $outage->vertical_id);
            $this->fifo->authorizeWork($outage, QueueType::VisitTechnician, $by);

            // The customer's ONU stayed, or a new one went in — which the NOC provisions first (requestProvisioning).
            if (! VisitPhotos::isRelocation($outage) && in_array($data['onu_status'] ?? null, array_keys(VisitPhotos::ONU_STATUSES), true)) {
                if ($data['onu_status'] === VisitPhotos::ONU_REPLACED && $ticket->provisioned_at === null) {
                    throw ValidationException::withMessages(['onu_status' => 'ONU replaced: first send the visit to the NOC for provisioning (with the photo of the ONU MAC address).']);
                }

                $ticket->forceFill(['onu_status' => $ticket->onu_status === VisitPhotos::ONU_REPLACED ? VisitPhotos::ONU_REPLACED : $data['onu_status']]);
            }

            $evidence = app(Settings::class)->bool('ticketing.require_evidence') && (bool) $ticket->visitType()->value('requires_evidence');
            // Which photos depends on the kind of visit: two, or the nine of an installation / relocation.
            $photos = $this->storeTicketPhotos($outage, $by, $this->visitPhotosFrom($outage, $data));

            if ($evidence) {
                $this->requireText($data['fat_number'] ?? $ticket->fat_number, 'fat_number', 'The FAT number is required before closing.');

                // Every photo of the set, each named: the technician sees at once what is still to take.
                $locked = VisitPhotos::locked($outage);
                $missing = collect(VisitPhotos::missing($outage))->mapWithKeys(fn (string $label, string $kind) => [VisitPhotos::field($kind) => match (true) {
                    in_array($kind, $locked, true) => "After the NOC has provisioned the service (Send to NOC for provisioning): {$label}.",
                    VisitPhotos::appOnly($outage) => "Still to take in the mobile app: {$label}.",
                    default => 'Add the '.lcfirst($label).' before closing.',
                }])->all();

                if ($missing !== []) {
                    throw ValidationException::withMessages($missing);
                }
            }

            $this->resumeTicketTimer($ticket);
            $ticket->forceFill(array_filter(Arr::only($data, ['fat_number', 'onu_type', 'drop_cable_m', 'ethernet_m', 'patch_cord', 'atb', 'extender']), fn ($value) => filled($value)) + [
                'final_reason_id' => $reason->id,
                'resolution_notes' => trim((string) $data['resolution_notes']),
                'delay_reason_id' => $ticket->delay_reason_id ?? $reason->delay_reason_id,
                'completed_at' => $completedAt,
                'completed_by' => $by->id,
                'arrived_at' => $ticket->arrived_at ?? $completedAt,
                'verified_at' => null, 'verified_by' => null,
            ])->save();

            $summary = "Work completed — {$reason->name}: {$ticket->resolution_notes}"
                .($ticket->fat_number ? " FAT {$ticket->fat_number}." : '')
                .($photos > 0 ? " {$photos} photo(s) attached." : '');

            $outage->forceFill(['restored_at' => $completedAt, 'restored_by' => $by->id, 'latest_update' => $summary])->save();
            $this->ticketEvent($outage, 'completed', $by, QueueType::VisitTechnician, $reason->name);
            $this->ticketUpdate($outage, $summary, $by, 'restoration', 'completed');

            // Back to the dispatcher for the final close.
            $this->moveTicket($outage, $ticket, QueueType::VisitDispatch, $by, 'return', $summary, announce: false);
            $this->notifier->dispatch(NotificationEvent::VisitCompleted, $outage, ['reason' => $summary]);

            return $outage->refresh();
        });

        return $result->refresh();
    }

    /**
     * Technician could not finish. Either the dispatcher decides (escalate to Infrastructure
     * Support, Design, NOC, Access…), or — customer unreachable / asked to reschedule / asked for a
     * call back — CX Field Support takes it up with the customer.
     */
    public function visitNotCompleted(Outage $outage, User $by, string $reason, ?int $delayReasonId = null, bool $toFieldSupport = false): Outage
    {
        $this->requireText($reason, 'reason', 'Explain why the visit could not be completed.');

        $result = DB::transaction(function () use ($outage, $by, $reason, $delayReasonId, $toFieldSupport): Outage {
            $outage = $this->lock($outage);
            Gate::forUser($by)->authorize('visitNotCompleted', $outage);
            $ticket = $this->ticketOf($outage);
            $this->fifo->authorizeWork($outage, QueueType::VisitTechnician, $by);

            $this->resumeTicketTimer($ticket);
            $ticket->forceFill(['delay_reason_id' => $delayReasonId ?: $ticket->delay_reason_id])->save();
            $outage->forceFill(['latest_update' => $reason])->save();
            $this->ticketEvent($outage, 'not_completed', $by, QueueType::VisitTechnician, $reason);

            $to = $toFieldSupport ? QueueType::CxFieldSupport : QueueType::VisitDispatch;
            $this->moveTicket($outage, $ticket, $to, $by, $toFieldSupport ? 'route' : 'return', "Not completed: {$reason}", ['delay_reason_id' => $delayReasonId]);
            $this->notifier->dispatch(NotificationEvent::TicketRouted, $outage, ['reason' => "Not completed: {$reason}", 'queue' => $to->getLabel()]);

            return $outage->refresh();
        });

        return $result->refresh();
    }

    // ------------------------------------------------------------------ site visit: verification / confirmation

    /**
     * Dispatcher's final close of a completed visit. With "confirm with the customer" on, CX Field
     * Support then calls the customer; otherwise the visit closes here.
     */
    public function verifyVisit(Outage $outage, User $by, ?string $note = null, ?int $delayReasonId = null): Outage
    {
        $result = DB::transaction(function () use ($outage, $by, $note, $delayReasonId): Outage {
            $outage = $this->lock($outage);
            Gate::forUser($by)->authorize('visitVerify', $outage);
            $ticket = $this->ticketOf($outage);
            $this->fifo->authorizeWork($outage, $ticket->desk ?? QueueType::VisitDispatch, $by);

            $ticket->forceFill(['delay_reason_id' => $delayReasonId ?: $ticket->delay_reason_id]);
            $this->requireDelayReason($outage, $ticket, $ticket->completed_at);
            $ticket->forceFill(['verified_at' => now(), 'verified_by' => $by->id])->save();

            $this->ticketEvent($outage, 'verified', $by, $ticket->desk ?? QueueType::VisitDispatch, $note);
            $this->ticketUpdate($outage, 'Dispatcher verified the completed work.'.($note ? " {$note}" : ''), $by, 'action', 'verified');

            if (app(Settings::class)->bool('ticketing.confirm_closure')) {
                $this->moveTicket($outage, $ticket, QueueType::CxFieldSupport, $by, 'confirm', 'Completed and verified — confirm with the customer', announce: false);
            } else {
                $this->finishTicket($outage, $ticket, $by, $this->visitClosedStatus($outage), 'Closed by the dispatcher.'.($note ? " {$note}" : ''));
            }

            return $outage->refresh();
        });

        return $result->refresh();
    }

    /** Dispatcher: the work reported is not right. The completion is withdrawn; the visit is dispatched again. */
    public function rejectVisitCompletion(Outage $outage, User $by, string $reason): Outage
    {
        $this->requireText($reason, 'reason', 'Say what is not right with the completed work.');

        $result = DB::transaction(function () use ($outage, $by, $reason): Outage {
            $outage = $this->lock($outage);
            Gate::forUser($by)->authorize('visitVerify', $outage);
            $ticket = $this->ticketOf($outage);
            $this->fifo->authorizeWork($outage, $ticket->desk ?? QueueType::VisitDispatch, $by);

            $technician = $ticket->completed_by;
            $this->withdrawCompletion($outage, $ticket);
            $this->ticketUpdate($outage, "Completion not accepted by the dispatcher: {$reason}", $by, 'action', 'completion_rejected');
            $this->syncTicketStatus($outage, $ticket, $by, $reason);

            // Straight back to the technician who did the work, when still around.
            if ($technician && User::query()->active()->whereKey($technician)->exists()) {
                $this->dispatchVisit($outage, $ticket, $by, ['technician_id' => $technician, 'note' => "Rework: {$reason}"]);
            }

            return $outage->refresh();
        });

        return $result->refresh();
    }

    /**
     * CX Field Support calls the customer after the dispatcher's close.
     *
     * @param  array{outcome: string, notes?: ?string, close_unconfirmed?: bool}  $data  outcome "confirmed" closes, "not_resolved"
     *                                                                                   reopens to dispatch; not reached keeps it for another call unless close_unconfirmed
     */
    public function confirmVisit(Outage $outage, User $by, array $data): Outage
    {
        if (! array_key_exists($data['outcome'] ?? '', TicketFollowUp::OUTCOMES)) {
            throw ValidationException::withMessages(['outcome' => 'Select the outcome of the call.']);
        }

        $result = DB::transaction(function () use ($outage, $by, $data): Outage {
            $outage = $this->lock($outage);
            Gate::forUser($by)->authorize('visitConfirm', $outage);
            $ticket = $this->ticketOf($outage);
            $this->fifo->authorizeWork($outage, QueueType::CxFieldSupport, $by);

            $followUp = TicketFollowUp::query()->create([
                'outage_id' => $outage->id, 'user_id' => $by->id, 'kind' => 'confirmation', 'outcome' => $data['outcome'],
                'notes' => filled($data['notes'] ?? null) ? trim((string) $data['notes']) : null, 'called_at' => now(),
            ]);
            $summary = 'Closure call — '.$followUp->outcomeLabel().($followUp->notes ? ": {$followUp->notes}" : '.');

            $ticket->forceFill([
                'followed_up_at' => now(), 'followed_up_by' => $by->id, 'follow_up_outcome' => $followUp->outcome, 'follow_up_count' => $ticket->follow_up_count + 1,
            ])->save();
            $outage->forceFill(['latest_update' => $summary, 'cx_followed_up_at' => now()])->save();
            $this->ticketEvent($outage, 'follow_up', $by, QueueType::CxFieldSupport, $followUp->outcomeLabel());
            $this->ticketUpdate($outage, $summary, $by, 'action', 'closure_call');

            if ($followUp->outcome === 'confirmed' || (! $followUp->reached() && ! empty($data['close_unconfirmed']))) {
                $confirmed = $followUp->outcome === 'confirmed';
                $ticket->forceFill(['confirmed_at' => now(), 'confirmed_by' => $by->id, 'customer_confirmed' => $confirmed])->save();
                $this->ticketEvent($outage, 'confirmed', $by, QueueType::CxFieldSupport, $confirmed ? 'Customer confirmed' : 'Closed without reaching the customer');
                $this->finishTicket($outage, $ticket, $by, $this->visitClosedStatus($outage), $confirmed ? 'Customer confirmed the service is working.' : 'Customer not reached; closed on the dispatcher\'s verification.');
            } elseif ($followUp->outcome === 'not_resolved') {
                // Not done properly: back to the dispatcher as a reopened visit.
                $this->withdrawCompletion($outage, $ticket);
                $outage->forceFill(['reopened_at' => now(), 'reopen_count' => $outage->reopen_count + 1])->save();
                $this->ticketEvent($outage, 'reopened', $by, QueueType::CxFieldSupport, $followUp->notes);
                $this->moveTicket($outage, $ticket, QueueType::VisitDispatch, $by, 'reopen', 'Customer says it is not resolved'.($followUp->notes ? ": {$followUp->notes}" : ''));
                $this->notifier->dispatch(NotificationEvent::TicketReopened, $outage, ['reason' => $summary]);
            } else {
                event(new OutageUpdated($outage->id, $outage->ticket_number));
            }

            return $outage->refresh();
        });

        return $result->refresh();
    }

    // ------------------------------------------------------------------ close / cancel / reopen

    /**
     * Close a ticket at its desk with a final reason (customer tickets; and site visits closed by
     * the dispatcher or CX Field Support without field work — "client confirmed uptime on call").
     *
     * @param  array{final_reason_id: int, resolution_notes?: ?string, delay_reason_id?: ?int, customer_confirmed?: ?bool, declined?: bool}  $data
     */
    public function closeTicket(Outage $outage, User $by, array $data): Outage
    {
        $reason = FinalReason::query()->active()->find($data['final_reason_id'] ?? 0)
            ?? throw ValidationException::withMessages(['final_reason_id' => 'Select the final reason.']);

        $result = DB::transaction(function () use ($outage, $by, $data, $reason): Outage {
            $outage = $this->lock($outage);
            Gate::forUser($by)->authorize('ticketClose', $outage);
            $ticket = $this->ticketOf($outage);
            $this->reasonOfDesk($reason, $outage->current_queue, $outage->vertical_id);

            if ($ticket->desk) {
                $this->fifo->authorizeWork($outage, $ticket->desk, $by);
            }

            $ticket->forceFill([
                'final_reason_id' => $reason->id,
                'resolution_notes' => filled($data['resolution_notes'] ?? null) ? trim((string) $data['resolution_notes']) : $ticket->resolution_notes,
                'delay_reason_id' => ($data['delay_reason_id'] ?? null) ?: ($ticket->delay_reason_id ?? $reason->delay_reason_id),
                'customer_confirmed' => array_key_exists('customer_confirmed', $data) ? (bool) $data['customer_confirmed'] : $ticket->customer_confirmed,
            ]);
            $this->requireDelayReason($outage, $ticket, now());
            $ticket->save();

            $declined = ! empty($data['declined']) || ($outage->isCxTicket() && $ticket->approval_state === Ticket::APPROVAL_DECLINED);
            $status = match (true) {
                $declined => StatusCode::TicketDeclined,
                $outage->isSiteVisit() => $this->visitClosedStatus($outage),
                default => StatusCode::TicketClosed,
            };

            $this->finishTicket($outage, $ticket, $by, $status, $reason->name.($ticket->resolution_notes ? ": {$ticket->resolution_notes}" : '.'));
            $this->closeFourGVendor($outage);

            return $outage->refresh();
        });

        return $result->refresh();
    }

    /** Cancel a ticket, or mark it a duplicate. No SLA outcome. */
    public function cancelTicket(Outage $outage, User $by, string $reason, bool $duplicate = false): Outage
    {
        $this->requireText($reason, 'reason', 'A reason is required.');

        $result = DB::transaction(function () use ($outage, $by, $reason, $duplicate): Outage {
            $outage = $this->lock($outage);
            Gate::forUser($by)->authorize('ticketCancel', $outage);
            $ticket = $this->ticketOf($outage);

            $outage->forceFill(['cancelled_at' => now(), 'cancelled_by' => $by->id]);
            $ticket->forceFill(['resolution_notes' => trim($reason)])->save();
            $this->finishTicket($outage, $ticket, $by, $duplicate ? StatusCode::TicketDuplicate : StatusCode::TicketCancelled,
                ($duplicate ? 'Duplicate: ' : 'Cancelled: ').$reason, event: 'cancelled');

            return $outage->refresh();
        });

        return $result->refresh();
    }

    /** Reopen a closed ticket: it returns to its home desk (customer tickets) or to dispatch (site visits). */
    public function reopenTicket(Outage $outage, User $by, string $reason): Outage
    {
        $this->requireText($reason, 'reason', 'A reason is required to reopen a ticket.');

        $result = DB::transaction(function () use ($outage, $by, $reason): Outage {
            $outage = $this->lock($outage);
            Gate::forUser($by)->authorize('ticketReopen', $outage);
            $this->reopenClosedTicket($outage, $this->ticketOf($outage), $by, $reason);

            return $outage->refresh();
        });

        return $result->refresh();
    }

    /** The reopening itself (the caller has checked who may): back to the first desk, history kept. */
    private function reopenClosedTicket(Outage $outage, Ticket $ticket, User $by, string $reason): void
    {
        $previous = $outage->status?->name;

        OutageReopening::query()->create([
            'outage_id' => $outage->id,
            'user_id' => $by->id,
            'reason' => trim($reason),
            'previous_status_id' => $outage->status_id,
            'previous_closed_at' => $outage->resolved_at ?? $outage->cancelled_at,
            'previous_closed_by' => $outage->resolved_by ?? $outage->cancelled_by,
            'previous_end_time' => $outage->end_time,
            'previous_sla_state' => $outage->sla_state?->value,
            'previous_resolution' => $outage->final_reason,
        ]);

        $outage->forceFill([
            'reopened_at' => now(), 'reopen_count' => $outage->reopen_count + 1, 'end_time' => null,
            'resolved_at' => null, 'resolved_by' => null, 'restored_at' => null, 'restored_by' => null,
            'cancelled_at' => null, 'cancelled_by' => null, 'final_reason' => null,
        ]);

        $this->withdrawCompletion($outage, $ticket);
        $ticket->forceFill(['final_reason_id' => null, 'confirmed_at' => null, 'confirmed_by' => null, 'customer_confirmed' => null, 'approval_state' => null])->save();

        $home = $outage->isSiteVisit() ? QueueType::VisitDispatch : ($ticket->home_desk ?? QueueType::CxTier2);
        $this->transition($outage, $outage->isSiteVisit() ? StatusCode::VisitOpen : StatusCode::TicketOpen, $by, $reason);
        // The customer was never really served: the SLA clock continues from when it was first reported.
        $this->sla->apply($outage, 'reopened');
        $this->ticketEvent($outage, 'reopened', $by, $home, $reason);
        $this->moveTicket($outage, $ticket, $home, $by, 'reopen', "Reopened (was {$previous}): {$reason}");
        $this->notifier->dispatch(NotificationEvent::TicketReopened, $outage, ['reason' => $reason]);
    }

    // ------------------------------------------------------------------ team leader intervention

    /** A team leader takes the ticket over at its desk: a separate timer runs and the take-over is on record. */
    public function startIntervention(Outage $outage, User $by, string $reason): Outage
    {
        $this->requireText($reason, 'reason', 'Say why you are intervening.');

        $result = DB::transaction(function () use ($outage, $by, $reason): Outage {
            $outage = $this->lock($outage);
            Gate::forUser($by)->authorize('ticketIntervene', $outage);
            $ticket = $this->ticketOf($outage);
            $desk = $ticket->desk ?? throw ValidationException::withMessages(['desk' => 'The ticket is not at a desk.']);

            $entry = $outage->openQueueEntries()->where('queue_type', $desk->value)->first() ?? $this->fifo->enqueue($outage, $desk, ['reason' => 'Team leader intervention']);
            $from = $entry->activeAssignment()->with('user:id,name')->first()?->user?->name;
            $this->fifo->assign($entry, $by, AssignmentType::Override, $by, "Team leader intervention: {$reason}", ['intervention' => true, 'taken_from' => $from]);
            $outage->refresh();

            $ticket->forceFill(['intervention_by' => $by->id, 'intervention_started_at' => now(), 'intervention_ended_at' => null])->save();
            $this->ticketEvent($outage, 'intervention_started', $by, $desk, $reason, ['taken_from' => $from]);
            $this->ticketUpdate($outage, "Team leader intervention started by {$by->name}".($from ? " (taken over from {$from})" : '').": {$reason}", $by, 'escalation', 'intervention_started');
            $this->notifier->dispatch(NotificationEvent::TicketIntervention, $outage, ['reason' => $reason]);
            $this->syncTicketStatus($outage, $ticket, $by, 'Team leader intervention');

            return $outage->refresh();
        });

        return $result->refresh();
    }

    /** End the intervention; with $handBack the ticket returns to its queue for the next person, otherwise the leader keeps it. */
    public function endIntervention(Outage $outage, User $by, ?string $note = null, bool $handBack = true): Outage
    {
        $result = DB::transaction(function () use ($outage, $by, $note, $handBack): Outage {
            $outage = $this->lock($outage);
            Gate::forUser($by)->authorize('ticketEndIntervention', $outage);
            $ticket = $this->ticketOf($outage);
            $this->closeIntervention($ticket);
            $ticket->save();

            $this->ticketEvent($outage, 'intervention_ended', $by, $ticket->desk, $note, ['seconds' => $ticket->intervention_seconds]);
            $this->ticketUpdate($outage, 'Team leader intervention ended'.($handBack ? ' — ticket handed back to the queue' : '').($note ? ": {$note}" : '.'), $by, 'action', 'intervention_ended');

            if ($handBack && $ticket->desk && ($assignment = $outage->activeAssignments()->where('queue_type', $ticket->desk->value)->where('user_id', $by->id)->first())) {
                $this->fifo->release($assignment, 'Team leader intervention ended', $by);
            }

            event(new OutageUpdated($outage->id, $outage->ticket_number));

            return $outage->refresh();
        });

        return $result->refresh();
    }

    // ------------------------------------------------------------------ outage link / attachments

    /** Link the ticket to the network outage affecting the customer (or clear the link). */
    public function linkTicketOutage(Outage $outage, User $by, ?int $networkOutageId): Outage
    {
        $result = DB::transaction(function () use ($outage, $by, $networkOutageId): Outage {
            $outage = $this->lock($outage);
            Gate::forUser($by)->authorize('ticketLinkOutage', $outage);
            $ticket = $this->ticketOf($outage);

            $network = $networkOutageId
                ? (Outage::query()->network()->find($networkOutageId) ?? throw ValidationException::withMessages(['outage_id' => 'Select a network outage ticket.']))
                : null;

            $ticket->forceFill([
                'linked_outage_id' => $network?->id,
                'delay_reason_id' => $network ? ($ticket->delay_reason_id ?? DelayReason::idForCode(DelayReason::OUTAGE)) : $ticket->delay_reason_id,
            ])->save();

            $this->ticketEvent($outage, 'outage_linked', $by, $ticket->desk, $network?->ticket_number);
            $this->ticketUpdate($outage, $network ? "Linked to outage {$network->ticket_number} ({$network->title}): the customer is affected by it." : 'Outage link removed.', $by, 'action', 'outage_linked');
            event(new OutageUpdated($outage->id, $outage->ticket_number));

            return $outage->refresh();
        });

        return $result->refresh();
    }

    /**
     * @param  array<int, string>  $paths  files already uploaded under outage-attachments/ on the private disk
     * @param  array<string, string>  $names  path => original file name
     */
    public function addTicketAttachments(Outage $outage, User $by, array $paths, array $names = [], ?string $note = null, ?string $kind = null): int
    {
        return DB::transaction(function () use ($outage, $by, $paths, $names, $note, $kind): int {
            Gate::forUser($by)->authorize('ticketAttach', $outage);
            $files = $this->storeTicketAttachments($outage, $by, $paths, $names, $note, $kind);

            if ($files->isEmpty()) {
                throw ValidationException::withMessages(['files' => 'Add at least one file.']);
            }

            $this->ticketUpdate($outage, 'Attachment'.($files->count() === 1 ? '' : 's').' added: '.$files->map->name()->implode(', ').($note ? " — {$note}" : '.'), $by, 'attachment', 'attachment_added');
            event(new OutageUpdated($outage->id, $outage->ticket_number));

            return $files->count();
        });
    }

    /**
     * The technician adds photos of the visit as the work goes: the nine of an installation are taken
     * at the FAT, along the cable and in the house — not all at the end. $data as for completeVisit:
     * "{field}" (the stored path, or paths), "{field}_name" and "{field}_location".
     */
    public function addVisitPhotos(Outage $outage, User $by, array $data): int
    {
        return DB::transaction(function () use ($outage, $by, $data): int {
            $outage = $this->lock($outage);
            Gate::forUser($by)->authorize('visitComplete', $outage);
            $this->fifo->authorizeWork($outage, QueueType::VisitTechnician, $by);

            $stored = $this->storeTicketPhotos($outage, $by, $this->visitPhotosFrom($outage, $data));

            if ($stored === 0) {
                throw ValidationException::withMessages(['photos' => 'Take at least one photo.']);
            }

            $missing = VisitPhotos::missing($outage);
            $this->ticketUpdate($outage, "{$stored} photo".($stored === 1 ? '' : 's').' added — '.VisitPhotos::progress($outage).' on the visit'
                .($missing ? '. Still to take: '.collect(array_keys($missing))->map(fn (string $kind) => VisitPhotos::short($kind))->implode('; ').'.' : ': all taken.'), $by, 'attachment', 'attachment_added');
            event(new OutageUpdated($outage->id, $outage->ticket_number));

            return $stored;
        });
    }

    /**
     * What was sent for each photo of this visit's set, as storeTicketPhotos takes it.
     *
     * @return array<string, array{0: mixed, 1: mixed, 2: string, 3: mixed, 4: bool}> kind => path(s), name(s), note, position, several?
     */
    private function visitPhotosFrom(Outage $outage, array $data): array
    {
        // A relocation not provisioned yet: the speed tests and the smart-device photo wait for the NOC.
        $locked = VisitPhotos::locked($outage);
        $early = collect($locked)->filter(fn (string $kind) => filled($data[VisitPhotos::field($kind)] ?? null))
            ->mapWithKeys(fn (string $kind) => [VisitPhotos::field($kind) => 'Send the visit to the NOC for provisioning first; then take: '.VisitPhotos::short($kind).'.'])->all();

        if ($early !== []) {
            throw ValidationException::withMessages($early);
        }

        return collect(VisitPhotos::of($outage))->except($locked)->map(fn (array $photo) => [
            $data[$photo['field']] ?? null,
            $data[$photo['field'].'_name'] ?? null,
            $photo['short'],
            // A geotagged photo comes with the position it was taken at (sent by the app, or read from the photo itself).
            $data[$photo['field'].'_location'] ?? null,
            $photo['many'],
        ])->all();
    }

    /** A final reason is only accepted at a desk it is offered at (Configuration → Final Reasons). */
    private function reasonOfDesk(FinalReason $reason, ?QueueType $desk, ?int $verticalId = null): void
    {
        if (! $reason->isOfferedAt($desk)) {
            throw ValidationException::withMessages(['final_reason_id' => "“{$reason->name}” is not a final reason of the {$desk?->getLabel()} desk. Choose one of the reasons offered here."]);
        }

        if ($verticalId && $reason->vertical_id && (int) $reason->vertical_id !== $verticalId) {
            throw ValidationException::withMessages(['final_reason_id' => "“{$reason->name}” is a final reason of another vertical. Choose one of the reasons offered here."]);
        }
    }

    /**
     * Files already uploaded under outage-attachments/ on the private disk become attachments of
     * the ticket. Anything else that claims to be a path is ignored.
     *
     * @param  array<int, string>  $paths
     * @param  array<string, string>  $names  path => original file name
     * @return Collection<int, OutageAttachment>
     */
    private function storeTicketAttachments(Outage $outage, User $by, array $paths, array $names = [], ?string $note = null, ?string $kind = null): Collection
    {
        $kind = in_array($kind, ['geo_photo', 'speed_test', 'evidence'], true) ? $kind : null;

        return collect($paths)
            ->filter(fn ($path) => is_string($path) && Str::startsWith($path, OutageAttachment::DIRECTORY.'/') && ! str_contains($path, '..') && Storage::disk('local')->exists($path))
            ->values()
            ->map(fn (string $path) => OutageAttachment::query()->create([
                'outage_id' => $outage->id, 'kind' => $kind, 'path' => $path, 'original_name' => $names[$path] ?? null, 'note' => $note, 'user_id' => $by->id,
                // A geolocation photo added later carries its position in the photo itself.
            ] + (VisitPhotos::geotagged($kind) ? app(PhotoLocation::class)->for($path) : [])));
    }

    /**
     * What the person raising a ticket or a visit attached to it (a screenshot, the customer's
     * e-mail, a receipt): on the ticket from the first moment, and said so on its timeline.
     *
     * @param  array<string, mixed>  $data  attachments (paths), attachment_names (path => file name)
     */
    private function attachOnRaising(Outage $outage, User $by, array $data): void
    {
        $files = $this->storeTicketAttachments($outage, $by, array_values((array) ($data['attachments'] ?? [])), (array) ($data['attachment_names'] ?? []), 'Attached when raised');

        if ($files->isNotEmpty()) {
            $this->ticketUpdate($outage, 'Attached when raised: '.$files->map->name()->implode(', ').'.', $by, 'attachment', 'attachment_added');
        }
    }

    // ------------------------------------------------------------------ follow-up trigger (scheduler)

    /**
     * A ticket has been pending past the follow-up time: it enters the follow-up FIFO queue (a call
     * to the customer, alongside the work in progress) and is marked ready for credit. Returns
     * false when it was already flagged or is no longer open.
     */
    public function flagTicketFollowUp(int $outageId): bool
    {
        return (bool) AuditContext::as('system:ticket-follow-up', fn () => DB::transaction(function () use ($outageId): bool {
            $outage = Outage::query()->with(['status', 'priority'])->lockForUpdate()->find($outageId);
            $ticket = $outage ? Ticket::query()->where('outage_id', $outage->id)->first() : null;

            if (! $outage || ! $ticket || $outage->is_closed || $ticket->follow_up_due_at) {
                return false;
            }

            $hours = round(app(Settings::class)->int('ticketing.followup_minutes') / 60, 1);
            $ticket->forceFill(['follow_up_due_at' => now(), 'credit_ready_at' => now()])->save();
            $this->fifo->enqueue($outage, QueueType::TicketFollowUp, ['reason' => "Pending more than {$hours} SLA hours — call the customer"]);
            $this->ticketEvent($outage, 'follow_up_due', null, $ticket->desk, "Pending more than {$hours} SLA hours");
            $this->ticketUpdate($outage, "Pending more than {$hours} SLA hours: placed in the customer follow-up queue and marked ready for credit.", null, 'system', 'follow_up_due');
            $this->notifier->dispatch(NotificationEvent::TicketFollowUpDue, $outage, ['reason' => "Pending more than {$hours} SLA hours", 'by' => 'System']);

            return true;
        }));
    }

    // ------------------------------------------------------------------ FIFO → ticket

    /**
     * Keeps a ticket in step with the FIFO item of its desk. The engine assigns, takes back
     * (reject, acknowledgement timeout, person went off shift) and re-assigns on its own; this
     * turns that into the ticket's "assigned at", technician, status, the customer SMS when a
     * technician gets a visit, and the time line.
     */
    public function syncTicketAssignment(int $outageId): void
    {
        if (Outage::query()->whereKey($outageId)->tickets()->where('is_closed', false)->doesntExist()) {
            return;
        }

        AuditContext::as('system:fifo-engine', fn () => DB::transaction(function () use ($outageId): void {
            $outage = Outage::query()->with(['status', 'priority'])->lockForUpdate()->find($outageId);
            $ticket = $outage ? Ticket::query()->where('outage_id', $outage->id)->first() : null;

            if (! $outage || ! $ticket || $outage->is_closed || ! $ticket->desk) {
                return;
            }

            $desk = $ticket->desk;
            $entry = $outage->openQueueEntries()->where('queue_type', $desk->value)->first();
            $assignment = $entry?->activeAssignment()->with(['user', 'assignedBy:id,name'])->first();
            $field = $desk->isFieldDesk();

            if ($assignment) {
                if ((int) $ticket->assignment_id !== (int) $assignment->id) {
                    $how = $assignment->assignment_type === AssignmentType::AutoFifo ? 'automatically (FIFO)' : 'by '.($assignment->assignedBy?->name ?? 'a manager');
                    $ticket->forceFill([
                        'assignment_id' => $assignment->id, 'assigned_at' => $assignment->assigned_at,
                        'first_assigned_at' => $ticket->first_assigned_at ?? $assignment->assigned_at, 'accepted_at' => null,
                    ]);
                    $outage->forceFill(['acknowledged_at' => $outage->acknowledged_at ?? now()]);

                    if ($field) {
                        $outage->forceFill(['technician_id' => $assignment->user_id, 'team_id' => $assignment->team_id ?? $outage->team_id]);
                    }

                    $ticket->save();
                    $outage->save();
                    $this->ticketEvent($outage, 'assigned', null, $desk, $how, ['assignment_id' => $assignment->id], $assignment->user_id);
                    $this->ticketUpdate($outage, "Assigned to {$assignment->user?->name} at {$desk->getLabel()} {$how}.", null, 'system', 'assigned');

                    if ($desk === QueueType::VisitTechnician && $assignment->user && $ticket->arrived_at === null) {
                        $this->visitTechnicianAssigned($outage, $ticket, $assignment->user);
                    }
                }

                if ($assignment->status === AssignmentStatus::Accepted && ! $ticket->accepted_at) {
                    $ticket->forceFill(['accepted_at' => $assignment->accepted_at ?? now()])->save();
                    $this->ticketEvent($outage, 'accepted', null, $desk, null, ['assignment_id' => $assignment->id], $assignment->user_id);
                }
            } elseif ($ticket->assignment_id) {
                // Rejected, not accepted in time, or the person became unavailable: waiting in the queue again.
                $ticket->forceFill(['assignment_id' => null, 'assigned_at' => null, 'accepted_at' => null])->save();

                if ($field) {
                    $outage->forceFill(['technician_id' => null])->save();
                }

                $this->ticketEvent($outage, 'returned_to_queue', null, $desk);
                $this->ticketUpdate($outage, "Nobody holds the ticket any more (assignment rejected, not accepted in time, or the person became unavailable): back in the {$desk->getLabel()} queue.", null, 'system', 'returned_to_queue');
            }

            $this->syncTicketStatus($outage, $ticket, null, null);
        }));
    }

    // ------------------------------------------------------------------ internals

    private function ticketType(string $code): OutageType
    {
        return OutageType::query()->where('code', $code)->first()
            ?? throw ValidationException::withMessages(['type' => 'The customer ticketing reference data is missing. Run: php artisan db:seed --class=TicketingSeeder']);
    }

    private function ticketOf(Outage $outage): Ticket
    {
        return Ticket::query()->where('outage_id', $outage->id)->lockForUpdate()->first()
            ?? throw ValidationException::withMessages(['ticket' => 'This is not a customer ticket or site visit.']);
    }

    /**
     * The vertical a customer ticket is raised under: the one chosen (an active vertical), and it must be
     * the vertical of the issue's category. Without a choice: the category's vertical, or FTTH.
     */
    private function ticketVertical(array $data, TicketSubcategory $subcategory): ?int
    {
        $categoryVertical = $subcategory->category?->vertical_id;
        $chosen = is_numeric($data['vertical_id'] ?? null) ? (int) $data['vertical_id'] : null;

        if ($chosen !== null && ! Vertical::query()->active()->whereKey($chosen)->exists()) {
            throw ValidationException::withMessages(['vertical_id' => 'Choose the vertical: FTTH, 4G…']);
        }

        if ($chosen !== null && $categoryVertical !== null && $chosen !== (int) $categoryVertical) {
            throw ValidationException::withMessages(['subcategory_id' => "“{$subcategory->name}” is not an issue of the chosen vertical. Choose the category and issue again."]);
        }

        return $chosen ?? ($categoryVertical ? (int) $categoryVertical : Vertical::idFor(Vertical::FTTH));
    }

    /** The Outage row every ticket is built on. */
    private function newTicketOutage(OutageType $type, User $by, array $attributes, StatusCode $initial): Outage
    {
        $outage = new Outage;
        $outage->forceFill($attributes + [
            'outage_type_id' => $type->id,
            'major_outage_type_id' => $type->major_outage_type_id ?? MajorOutageType::query()->where('code', MajorOutageType::CUSTOMER)->value('id'),
            'vertical_id' => $type->vertical_id ?? Vertical::query()->where('code', Vertical::FTTH)->value('id'),
            'workflow' => $type->workflow,
            'status_id' => OutageStatus::forCode($initial)->id,
            'is_closed' => false,
            'customers_affected' => 1,
            'sla_state' => SlaState::Compliant,
            'created_by' => $by->id,
            'updated_by' => $by->id,
        ]);
        $outage->detected_at = $outage->start_time;
        $outage->save();

        return $outage->refresh();
    }

    /** History, SLA, time line, "created" event and notification of a new ticket. */
    private function openTicket(Outage $outage, Ticket $ticket, User $by, string $text): void
    {
        $this->recordHistory($outage, null, OutageStatus::query()->findOrFail($outage->status_id), 'Ticket created', $by);
        $this->sla->apply($outage, 'started');
        $this->ticketEvent($outage, 'created', $by);
        $this->ticketUpdate($outage, $text, $by, 'note', 'created');

        event(new OutageCreated($outage->id, $outage->ticket_number));
        $this->notifier->dispatch(NotificationEvent::OutageCreated, $outage);
    }

    /**
     * Customer details of a ticket. The account is looked up in the customer database: found, the
     * record fills whatever the agent left blank; not found, the details typed in are kept as a
     * provisional customer, which a later customer import completes by account number.
     *
     * @return array<string, mixed>
     */
    private function ticketCustomerFields(array $data, User $by): array
    {
        $text = fn (string $key, int $limit = 250) => filled($data[$key] ?? null) ? Str::limit(trim((string) $data[$key]), $limit, '') : null;
        $id = fn (string $key) => is_int($data[$key] ?? null) || ctype_digit((string) ($data[$key] ?? '')) ? (int) $data[$key] : null;

        $account = Customer::normaliseAccount($data['account_number'] ?? null);
        $customer = $account !== '' ? Customer::query()->where('account_number', $account)->first() : null;

        $typed = [
            'name' => $text('customer_name', 190),
            'primary_phone' => $text('primary_number', 40) ?? $text('calling_number', 40),
            'alt_phone_1' => $text('alt_number_1', 40),
            'alt_phone_2' => $text('alt_number_2', 40),
            'address' => $text('address'),
            'landmark' => $text('landmark'),
            'region_id' => $id('region_id'),
            'olt_id' => $id('olt_id'),
            'fdt_id' => $id('olt_id') ? $id('fdt_id') : null,
            'slot_id' => $id('fdt_id') ? $id('slot_id') : null,
            'port_id' => $id('slot_id') ? $id('port_id') : null,
            'bandwidth' => $text('bandwidth', 60),
        ];

        if (! $customer && $account !== '') {
            $customer = Customer::query()->create(array_filter($typed, fn ($value) => $value !== null) + [
                'account_number' => $account, 'source' => Customer::SOURCE_MANUAL, 'is_active' => true,
                // A customer of the vertical the ticket is raised under.
                'vertical_id' => is_numeric($data['vertical_id'] ?? null) ? (int) $data['vertical_id'] : null,
                'notes' => "Captured by {$by->name} while raising a ticket; not on the customer file yet.",
            ])->refresh();
        } elseif ($customer) {
            // Details the customer gave now that the record did not have yet. A provisional record
            // takes the position on the network too; a verified one is only changed by the import.
            $fill = array_filter($typed, fn ($value, $key) => $value !== null && blank($customer->{$key})
                && ($customer->isProvisional() || ! in_array($key, ['olt_id', 'fdt_id', 'slot_id', 'port_id', 'name'], true)), ARRAY_FILTER_USE_BOTH);

            if ($fill !== []) {
                $customer->forceFill($fill)->save();
            }
        }

        $oltId = $customer?->olt_id ?? $typed['olt_id'];

        return [
            'customer_id' => $customer?->id,
            'account_number' => $account !== '' ? $account : null,
            'customer_name' => $typed['name'] ?? $customer?->name,
            'calling_number' => $text('calling_number', 40) ?? $typed['primary_phone'] ?? $customer?->primary_phone,
            'primary_number' => $text('primary_number', 40) ?? $customer?->primary_phone,
            'alt_number_1' => $typed['alt_phone_1'] ?? $customer?->alt_phone_1,
            'alt_number_2' => $typed['alt_phone_2'] ?? $customer?->alt_phone_2,
            'address' => $typed['address'] ?? $customer?->address,
            'landmark' => $typed['landmark'] ?? $customer?->landmark,
            'olt_id' => $oltId,
            'fdt_id' => $customer?->olt_id ? $customer->fdt_id : $typed['fdt_id'],
            'slot_id' => $customer?->olt_id ? $customer->slot_id : $typed['slot_id'],
            'port_id' => $customer?->olt_id ? $customer->port_id : $typed['port_id'],
            // Region: said on the form, else the customer's, else the OLT's.
            'region_id' => $typed['region_id'] ?? $customer?->region_id ?? ($oltId ? Olt::query()->whereKey($oltId)->value('region_id') : null),
            'bandwidth' => $customer?->bandwidth ?? $typed['bandwidth'],
        ];
    }

    /** The customer sits under an ongoing outage: link it and say so on the time line. */
    private function linkOngoingOutage(Outage $outage, Ticket $ticket, ?User $by): void
    {
        $match = app(OutageLocator::class)->ongoingFor($ticket->olt_id, $ticket->fdt_id, $ticket->slot_id, $ticket->port_id);

        if (! $match) {
            return;
        }

        $network = $match['outage'];
        $ticket->forceFill(['linked_outage_id' => $network->id, 'delay_reason_id' => $ticket->delay_reason_id ?? DelayReason::idForCode(DelayReason::OUTAGE)])->save();
        $this->ticketEvent($outage, 'outage_linked', $by, null, $network->ticket_number);
        $this->ticketUpdate($outage, ($match['certain'] ? 'The customer is affected by' : 'The customer may be affected by')
            ." ongoing outage {$network->ticket_number} ({$network->title}) — ".($match['confirmed'] ? 'confirmed by NOC' : 'not yet confirmed by NOC').'. Advise the customer.', null, 'system', 'outage_linked');
    }

    /**
     * Take the ticket from its current desk to another one: close the FIFO item of the desk it
     * leaves, record the hand-off, queue it at the new desk (optionally straight to one person).
     *
     * @param  array{user?: ?User, team_id?: ?int, eligible_from?: mixed, delay_reason_id?: ?int}  $options
     */
    private function moveTicket(Outage $outage, Ticket $ticket, QueueType $to, ?User $by, string $kind, string $note, array $options = [], bool $announce = true): void
    {
        $from = $ticket->desk;
        $now = now();

        // A Shifting / Relocation visit is dispatched at its own desk.
        if ($to->isVisitDispatch() && $outage->isSiteVisit()) {
            $to = EligibilityEvaluator::visitDispatchDesk($outage);
        }

        // Returned (work done, not completed, sent back): to the person at that desk who sent it out — the dispatcher
        // who dispatched the technician, the agent who escalated — when they may still work that desk; else FIFO.
        if ($kind === 'return' && $from && $from !== $to && empty($options['user'])) {
            $options['user'] = $this->sender($outage, $from, $to);
        }

        if ($from) {
            $this->fifo->complete($outage, $from, $by, Str::limit(($kind === 'return' ? 'Returned to ' : 'Sent to ').$to->getLabel(), 200));
        }

        if ($from) {
            // The hand-off that brought it here is answered when it is returned, or sent back to the
            // desk it came from (approved, declined): that is the escalation turnaround.
            TicketDeskMove::query()->where('outage_id', $outage->id)->where('to_desk', $from->value)->whereNull('returned_at')
                ->when($kind !== 'return', fn ($query) => $query->where('from_desk', $to->value))
                ->update(['returned_at' => $now, 'updated_at' => $now]);
        }

        TicketDeskMove::query()->create([
            'outage_id' => $outage->id, 'from_desk' => $from, 'to_desk' => $to, 'kind' => $kind, 'note' => Str::limit($note, 2000),
            'delay_reason_id' => $options['delay_reason_id'] ?? null, 'moved_by' => $by?->id, 'moved_at' => $now,
        ]);

        $escalation = $kind === 'route' && $from !== null;

        $ticket->forceFill([
            'desk' => $to,
            'desk_since' => $now,
            'assignment_id' => null,
            'assigned_at' => null,
            'accepted_at' => null,
            'escalated_to' => $escalation ? $to : $ticket->escalated_to,
            'escalated_at' => $escalation ? $now : $ticket->escalated_at,
            'escalation_count' => $ticket->escalation_count + ($escalation ? 1 : 0),
            'returned_at' => $kind === 'return' ? $now : $ticket->returned_at,
        ])->save();

        $entry = $this->fifo->enqueue($outage, $to, array_filter([
            'team_id' => $options['team_id'] ?? null,
            'eligible_from' => $options['eligible_from'] ?? null,
            'reason' => Str::limit(($from ? "From {$from->getLabel()}: " : '').$note, 250),
        ], fn ($value) => $value !== null));

        if (! empty($options['user'])) {
            $this->fifo->assign($entry, $options['user'], AssignmentType::Dispatch, $by, Str::limit($note, 250));
        }

        $outage->refresh();

        $this->ticketEvent($outage, match ($kind) {
            'return' => 'returned', 'route' => $from ? 'escalated' : 'queued', default => 'queued',
        }, $by, $to, Str::limit($note, 500), ['from' => $from?->value, 'kind' => $kind]);

        if ($announce) {
            $verb = match ($kind) {
                'return' => 'Returned to', 'approve', 'decline' => 'Sent to', 'assign' => 'Dispatched to', 'reopen' => 'Back at', default => 'Escalated to',
            };
            $this->ticketUpdate($outage, "{$verb} {$to->getLabel()}".($from && $from !== $to ? " from {$from->getLabel()}" : '')
                .(! empty($options['user']) ? " ({$options['user']->name})" : '').": {$note}", $by, $kind === 'route' ? 'escalation' : 'action', $kind === 'route' ? 'escalated' : $kind);
        }

        $this->syncTicketStatus($outage, $ticket, $by, Str::limit($note, 500));
    }

    /** Who sent the ticket from desk $to to desk $from (the latest such hand-over), if they may still work $to. */
    private function sender(Outage $outage, QueueType $from, QueueType $to): ?User
    {
        $desks = $to->isVisitDispatch() ? [QueueType::VisitDispatch->value, QueueType::ShiftingDispatch->value] : [$to->value];
        $userId = TicketDeskMove::query()->where('outage_id', $outage->id)->where('to_desk', $from->value)
            ->whereIn('from_desk', $desks)->whereNotNull('moved_by')->latest('id')->value('moved_by');
        $user = $userId ? User::query()->find($userId) : null;

        if (! $user || ! $user->is_active || ! collect(EligibilityEvaluator::deskPermissions($to, $outage))->contains(fn (string $p) => $user->can($p))) {
            return null;
        }

        // Off duty (offline, away): whoever is on duty at the desk takes it (FIFO) rather than it waiting for them.
        if (app(Settings::class)->bool('fifo.require_availability') && ! in_array($user->availability_status, [AvailabilityStatus::Available, AvailabilityStatus::Busy], true)) {
            return null;
        }

        $needs = EligibilityEvaluator::visitPermission($outage, $to);

        return $needs === null || $user->can($needs) ? $user : null;
    }

    /** The desk that handed the ticket to where it is now (and who did). */
    private function previousDesk(Outage $outage, Ticket $ticket): ?array
    {
        if (! $ticket->desk) {
            return null;
        }

        $move = TicketDeskMove::query()->where('outage_id', $outage->id)->where('to_desk', $ticket->desk->value)
            ->whereNull('returned_at')->whereNotNull('from_desk')->where('from_desk', '!=', $ticket->desk->value)
            ->whereNotIn('kind', ['return', 'decline'])->latest('id')->first();

        if (! $move) {
            return null;
        }

        // Handed over by a technician in the field: it goes back to that technician's dispatcher, not to the technician queue.
        $desk = match ($move->from_desk) {
            QueueType::VisitTechnician => EligibilityEvaluator::visitDispatchDesk($outage),
            QueueType::InfraSupportTechnician => QueueType::InfraSupportDispatch,
            default => $move->from_desk,
        };

        return ['desk' => $desk, 'user_id' => $desk === $move->from_desk ? $move->moved_by : null];
    }

    /** Dispatcher → technician (named, or the technician FIFO queue). Used by create, assign and rework. */
    private function dispatchVisit(Outage $outage, Ticket $ticket, User $by, array $data): void
    {
        $note = filled($data['note'] ?? null) ? trim((string) $data['note']) : null;
        $technician = filled($data['technician_id'] ?? null) ? $this->deskUser((int) $data['technician_id'], QueueType::VisitTechnician, $outage) : null;
        $teamId = filled($data['team_id'] ?? null) ? (int) $data['team_id'] : null;

        if (array_key_exists('scheduled_at', $data) && filled($data['scheduled_at'])) {
            $ticket->forceFill(['scheduled_at' => $this->visitMoment($data['scheduled_at'])]);
        }

        $scheduled = $ticket->scheduled_at;
        $limit = app(Settings::class)->int('ticketing.max_daily_visits');

        if ($technician && $limit > 0) {
            $day = ($scheduled ?? now())->copy();
            $booked = Ticket::query()->where('outage_id', '!=', $outage->id)
                ->whereHas('outage', fn ($q) => $q->where('is_closed', false)->where('technician_id', $technician->id))
                ->where(fn ($q) => $q->whereBetween('scheduled_at', [$day->copy()->startOfDay(), $day->copy()->endOfDay()])
                    ->orWhere(fn ($open) => $open->whereNull('scheduled_at')->when(! $day->isToday(), fn ($none) => $none->whereRaw('1 = 0'))))
                ->count();

            if ($booked >= $limit) {
                throw ValidationException::withMessages(['technician_id' => "{$technician->name} already has {$booked} visit(s) on ".$day->format('d M').' (limit '.$limit.').']);
            }
        }

        // A new attempt: whatever a previous technician recorded on the way is cleared.
        $this->withdrawCompletion($outage, $ticket);
        $ticket->forceFill(['en_route_at' => null, 'arrived_at' => null, 'paused_at' => null, 'paused_seconds' => 0])->save();
        $outage->forceFill(['team_id' => $teamId ?? $outage->team_id, 'dispatched_at' => now(), 'dispatched_by' => $by->id])->save();

        $future = $scheduled && $scheduled->isAfter(now()->endOfDay());
        $when = $scheduled ? ' for '.$scheduled->format('d M Y H:i') : '';
        $this->ticketEvent($outage, 'scheduled', $by, QueueType::VisitTechnician, $note, ['scheduled_at' => $scheduled?->toDateTimeString()], $technician?->id);

        $this->moveTicket($outage, $ticket, QueueType::VisitTechnician, $by, 'assign',
            ($technician ? "Assigned to {$technician->name}" : 'Placed in the technician queue (FIFO)').$when.($note ? ". {$note}" : '.'), [
                'user' => $technician,
                'team_id' => $teamId,
                // Without a named technician, a visit booked for a later day is only offered on that day.
                'eligible_from' => ! $technician && $future ? $scheduled->copy()->startOfDay() : null,
            ]);
    }

    /** A technician now holds the visit: text the customer and tell the rules. */
    private function visitTechnicianAssigned(Outage $outage, Ticket $ticket, User $technician): void
    {
        $day = match (true) {
            ! $ticket->scheduled_at, $ticket->scheduled_at->isToday() => 'today',
            $ticket->scheduled_at->isTomorrow() => 'tomorrow ('.$ticket->scheduled_at->format('D d M').')',
            default => 'on '.$ticket->scheduled_at->format('D d M Y'),
        };

        $this->smsTicketCustomer($outage, $ticket, 'ticketing.sms_assigned', [
            'technician' => $technician->name,
            'technician_phone' => $technician->phone ?: 'our support line',
            'visit_day' => $day,
            'visit_time' => $ticket->scheduled_at?->format('D d M Y, H:i') ?? 'today',
        ]);

        $this->notifier->dispatch(NotificationEvent::VisitScheduled, $outage, ['reason' => "Technician {$technician->name}, visit {$day}", 'by' => 'System']);
    }

    /**
     * Text the customer on every number of the ticket (calling, primary, alternatives). One log
     * row per number, delivered by the notification queue with retries.
     */
    private function smsTicketCustomer(Outage $outage, Ticket $ticket, string $templateKey, array $values = []): int
    {
        $settings = app(Settings::class);
        $template = trim((string) $settings->get($templateKey));

        if (! $settings->bool('ticketing.sms_customer') || $template === '') {
            return 0;
        }

        $values += [
            'ticket' => $outage->ticket_number,
            'customer' => (string) $ticket->customer_name,
            'account' => (string) $ticket->account_number,
            'company' => (string) $settings->get('ticketing.company_name'),
        ];
        $text = (string) preg_replace_callback('/\{\{\s*([a-z_]+)\s*\}\}/i', fn (array $m) => (string) ($values[$m[1]] ?? ''), $template);
        $text = mb_strimwidth(Str::squish($text), 0, max(70, (int) config('oms.sms.max_length', 306)), '…');

        $numbers = collect($ticket->phones())
            // "752026684 / 0769417357" in one field is two numbers; local numbers typed without the leading 0 get it.
            ->flatMap(fn (string $number) => preg_split('/[\/,;]+/', $number) ?: [])
            ->map(function (string $number): ?string {
                $digits = (string) preg_replace('/\D+/', '', $number);

                return PhoneNumber::normalise(match (true) {
                    str_starts_with(trim($number), '+') => '+'.$digits,
                    (bool) preg_match('/^[1-9]\d{8}$/', $digits) => '0'.$digits,
                    default => $digits,
                });
            })
            ->filter()->unique()->values();

        foreach ($numbers as $number) {
            $log = NotificationLog::query()->create([
                'outage_id' => $outage->id,
                'event' => NotificationEvent::TicketCustomerSms->value,
                'channel' => NotificationChannel::Sms->value,
                'recipient' => $number,
                'subject' => 'Customer SMS — '.$outage->ticket_number,
                'body' => $text,
                'status' => 'pending',
                'trigger' => AuditContext::trigger(),
            ]);

            SendNotificationJob::dispatch($log->id);
        }

        if ($numbers->isNotEmpty()) {
            $ticket->forceFill(['customer_sms_at' => now()])->save();
            $this->ticketEvent($outage, 'customer_sms', null, $ticket->desk, $text, ['numbers' => $numbers->count()]);
            $this->ticketUpdate($outage, "SMS sent to the customer on {$numbers->count()} number(s): “{$text}”", null, 'system', 'customer_sms');
        }

        return $numbers->count();
    }

    /** Close the ticket: final reason, end time, SLA outcome, parent ticket, notifications. */
    private function finishTicket(Outage $outage, Ticket $ticket, User $by, StatusCode $status, string $summary, string $event = 'closed'): void
    {
        $this->resumeTicketTimer($ticket);
        $this->closeIntervention($ticket);
        $ticket->save();

        $void = $status->isTicketVoid();
        $end = $outage->isSiteVisit() && $ticket->completed_at ? $ticket->completed_at : now();

        $outage->forceFill([
            'final_reason' => Str::limit($summary, 5000),
            'resolved_at' => now(),
            'resolved_by' => $by->id,
            'end_time' => $end,
            'latest_update' => Str::limit($summary, 5000),
        ]);

        $this->transition($outage, $status, $by, Str::limit($summary, 500));
        $ticket->forceFill(['desk' => null, 'desk_since' => now()])->save();
        $this->ticketEvent($outage, $event, $by, null, Str::limit($summary, 500));
        $this->ticketUpdate($outage, ($void ? '' : 'Closed — ').$summary, $by, 'action', $event);
        $this->notifier->dispatch(NotificationEvent::Resolved, $outage, ['reason' => $summary]);
        $this->tellTicketUsers(NotificationEvent::Resolved, $outage, [$outage->created_by], ['reason' => $summary]);

        if (! $void) {
            $this->smsTicketCustomer($outage, $ticket, 'ticketing.sms_closed');
        }

        $this->settleParentTicket($outage, $ticket, $by, $status, $summary);
    }

    /**
     * A site visit raised from a customer ticket has ended: completed → the customer ticket closes
     * with it; cancelled / declined → the customer ticket goes back to its desk to be dealt with.
     */
    private function settleParentTicket(Outage $visit, Ticket $ticket, User $by, StatusCode $status, string $summary): void
    {
        if (! $visit->isSiteVisit() || ! $ticket->parent_outage_id) {
            return;
        }

        $parent = Outage::query()->with(['status', 'priority'])->lockForUpdate()->find($ticket->parent_outage_id);
        $parentTicket = $parent ? Ticket::query()->where('outage_id', $parent->id)->first() : null;

        if (! $parent || ! $parentTicket || $parent->is_closed || ! $this->statusIs($parent, StatusCode::TicketSiteVisit)) {
            return;
        }

        if ($status->isTicketVoid()) {
            $home = $parentTicket->home_desk ?? QueueType::CxTier2;
            $this->moveTicket($parent, $parentTicket, $home, $by, 'return', "Site visit {$visit->ticket_number} was not carried out ({$summary}). Contact the customer and decide the next step.");

            return;
        }

        $parentTicket->forceFill([
            'final_reason_id' => $ticket->final_reason_id, 'resolution_notes' => $ticket->resolution_notes,
            'delay_reason_id' => $parentTicket->delay_reason_id ?? $ticket->delay_reason_id, 'customer_confirmed' => $ticket->customer_confirmed,
        ])->save();
        $this->finishTicket($parent, $parentTicket, $by, StatusCode::TicketClosed, "Resolved by site visit {$visit->ticket_number}: {$summary}");
    }

    /** "Closed Escalated" when the visit had to be handed to another team on the way. */
    private function visitClosedStatus(Outage $outage): StatusCode
    {
        $escalated = TicketDeskMove::query()->where('outage_id', $outage->id)->where('kind', 'route')->whereNotNull('from_desk')
            ->whereNotIn('to_desk', [QueueType::CxFieldSupport->value])->exists();

        return $escalated ? StatusCode::VisitClosedEscalated : StatusCode::VisitClosed;
    }

    /** A late ticket needs its reason for delay before it can be closed. */
    private function requireDelayReason(Outage $outage, Ticket $ticket, mixed $end): void
    {
        if (! $ticket->delay_reason_id && $outage->sla_due_at && $end && Carbon::parse($end)->greaterThan($outage->sla_due_at)) {
            throw ValidationException::withMessages(['delay_reason_id' => 'This ticket is past its SLA: select the reason for the delay.']);
        }
    }

    private function withdrawCompletion(Outage $outage, Ticket $ticket): void
    {
        $ticket->forceFill(['completed_at' => null, 'completed_by' => null, 'verified_at' => null, 'verified_by' => null])->save();

        if ($outage->restored_at) {
            $outage->forceFill(['restored_at' => null, 'restored_by' => null])->save();
        }
    }

    private function resumeTicketTimer(Ticket $ticket): void
    {
        if ($ticket->paused_at) {
            $ticket->forceFill([
                'paused_seconds' => $ticket->paused_seconds + max(0, now()->getTimestamp() - $ticket->paused_at->getTimestamp()),
                'paused_at' => null,
            ]);
        }
    }

    private function closeIntervention(Ticket $ticket): void
    {
        if ($ticket->intervention_started_at && ! $ticket->intervention_ended_at) {
            $ticket->forceFill([
                'intervention_ended_at' => now(),
                'intervention_seconds' => $ticket->intervention_seconds + max(0, now()->getTimestamp() - $ticket->intervention_started_at->getTimestamp()),
            ]);
        }
    }

    /** One technician step (en route, arrived, pause, resume) with the shared checks around it. */
    private function visitStep(Outage $outage, User $by, string $ability, callable $step): Outage
    {
        $result = DB::transaction(function () use ($outage, $by, $ability, $step): Outage {
            $outage = $this->lock($outage);
            Gate::forUser($by)->authorize($ability, $outage);
            $ticket = $this->ticketOf($outage);
            $this->fifo->authorizeWork($outage, $ticket->desk, $by);

            $step($outage, $ticket);
            $this->syncTicketStatus($outage, $ticket, $by, null);
            event(new OutageUpdated($outage->id, $outage->ticket_number));

            return $outage->refresh();
        });

        return $result->refresh();
    }

    /** The status a ticket should show, from the desk it is at and what has happened there. */
    private function ticketStatusFor(Outage $outage, Ticket $ticket): ?StatusCode
    {
        $desk = $ticket->desk;

        if ($outage->is_closed || ! $desk) {
            return null;
        }

        $held = fn () => $outage->activeAssignments()->where('queue_type', $desk->value)->exists();

        if ($outage->isSiteVisit()) {
            return match (true) {
                $desk->isVisitDispatch() => $ticket->completed_at ? StatusCode::VisitPendingVerification : StatusCode::VisitOpen,
                $desk === QueueType::VisitTechnician => match (true) {
                    ! $held() => StatusCode::VisitPendingWithoutTeam,
                    $ticket->en_route_at !== null || $ticket->arrived_at !== null => StatusCode::VisitInProgress,
                    default => StatusCode::VisitPendingScheduled,
                },
                $desk === QueueType::CxFieldSupport => $ticket->verified_at ? StatusCode::VisitPendingConfirmation : StatusCode::VisitPendingCustomer,
                default => StatusCode::VisitPendingEscalated,
            };
        }

        return match (true) {
            $desk === QueueType::CxCallback => StatusCode::TicketPendingCustomer,
            $desk->isApprovalDesk() && $ticket->approval_state === Ticket::APPROVAL_PENDING => StatusCode::TicketPendingApproval,
            $desk !== $ticket->home_desk => StatusCode::TicketEscalated,
            $outage->activeAssignments()->where('queue_type', $desk->value)->where('status', AssignmentStatus::Accepted->value)->exists() => StatusCode::TicketInProgress,
            default => StatusCode::TicketOpen,
        };
    }

    private function syncTicketStatus(Outage $outage, Ticket $ticket, ?User $by, ?string $reason): void
    {
        $code = $this->ticketStatusFor($outage, $ticket);

        if ($code && ! $this->statusIs($outage, $code)) {
            $this->transition($outage, $code, $by, $reason);
        }
    }

    /** An active user who may work the given desk. */
    private function deskUser(int $userId, QueueType $desk, ?Outage $outage = null): User
    {
        $user = User::query()->find($userId);

        if (! $user || ! $user->is_active || ! collect(EligibilityEvaluator::deskPermissions($desk, $outage))->contains(fn (string $p) => $user->can($p))) {
            throw ValidationException::withMessages(['user_id' => ($user?->name ?? 'That user')." cannot receive tickets at {$desk->getLabel()} (inactive, or without the desk permission)."]);
        }

        // A visit kept for some technicians / dispatchers (Shifting / Relocation) goes only to them.
        if ($outage && ($needs = EligibilityEvaluator::visitPermission($outage, $desk)) && ! $user->can($needs)) {
            throw ValidationException::withMessages([$desk === QueueType::VisitTechnician ? 'technician_id' : 'user_id' => "{$user->name} does not do this kind of visit: it needs “".Permissions::label($needs).'”.']);
        }

        return $user;
    }

    /** A visit time: null when blank, never (meaningfully) in the past. */
    private function visitMoment(mixed $value): ?Carbon
    {
        if (blank($value)) {
            return null;
        }

        $moment = Carbon::parse($value);

        if ($moment->lessThan(now()->subMinutes(5))) {
            throw ValidationException::withMessages(['scheduled_at' => 'The visit date and time must be in the future.']);
        }

        return $moment;
    }

    /**
     * @param  array<string, array{0: ?string, 1: ?string, 2: string}>  $photos  kind => [path, original name, note]
     */
    private function storeTicketPhotos(Outage $outage, User $by, array $photos): int
    {
        $stored = 0;
        $usable = fn (mixed $path): bool => is_string($path) && Str::startsWith($path, OutageAttachment::DIRECTORY.'/') && ! str_contains($path, '..') && Storage::disk('local')->exists($path);

        foreach ($photos as $kind => $photo) {
            [$paths, $name, $note, $location, $many] = array_pad($photo, 5, null);
            $paths = array_values(array_filter(is_array($paths) ? $paths : [$paths], $usable));
            $paths = $many ? $paths : array_slice($paths, 0, 1);

            if ($paths === []) {
                continue;
            }

            // Taken again: the earlier one stays on the visit, but is no longer "the" photo of its kind.
            if (! $many) {
                OutageAttachment::query()->where('outage_id', $outage->id)->where('kind', $kind)->get()
                    ->each(fn (OutageAttachment $earlier) => $earlier->update(['kind' => 'evidence', 'note' => Str::limit('Replaced — '.($earlier->note ?: $kind), 250)]));
            }

            foreach ($paths as $path) {
                OutageAttachment::query()->create([
                    'outage_id' => $outage->id, 'kind' => $kind, 'path' => $path,
                    'original_name' => is_array($name) ? ($name[$path] ?? null) : $name, 'note' => $note, 'user_id' => $by->id,
                ] + (VisitPhotos::geotagged($kind) ? app(PhotoLocation::class)->for($path, is_array($location) ? $location : null) : []));
                $stored++;
            }
        }

        return $stored;
    }

    /** In-app notice to the people directly involved (never e-mail: tickets are many). The acting user is skipped. */
    private function tellTicketUsers(NotificationEvent $event, Outage $outage, array $userIds, array $context = []): void
    {
        $ids = array_diff(array_filter(array_map('intval', $userIds)), [(int) auth()->id()]);

        if ($ids !== []) {
            $this->notifier->notifyUsers($event, $outage, User::query()->active()->whereIn('id', $ids)->get(), [NotificationChannel::InApp->value], $context);
        }
    }

    /** One step on the structured time line (the TAT reports read these). */
    private function ticketEvent(Outage $outage, string $event, ?User $by, ?QueueType $desk = null, ?string $note = null, array $meta = [], ?int $subjectUserId = null): void
    {
        TicketEvent::query()->create([
            'outage_id' => $outage->id,
            'event' => $event,
            'desk' => $desk,
            'user_id' => $by?->id,
            'subject_user_id' => $subjectUserId,
            'note' => $note !== null ? Str::limit($note, 2000) : null,
            'meta' => $meta ?: null,
            'occurred_at' => now(),
        ]);
    }

    /** A time line entry and the same action in the audit trail (who, action, status, when, comment). */
    private function ticketUpdate(Outage $outage, string $body, ?User $by, string $type = 'system', string $action = 'note'): void
    {
        OutageUpdate::query()->create([
            'outage_id' => $outage->id,
            'user_id' => $by?->id,
            'type' => $type,
            'actor_type' => $by ? null : OutageUpdate::ACTOR_SYSTEM,
            'body' => $body,
            'is_system' => $by === null,
        ]);

        $outage->audit(AuditLog::TICKET_PREFIX.$action, [
            'ticket' => $outage->ticket_number,
            'status' => OutageStatus::query()->whereKey($outage->status_id)->value('name'),
            'by' => $by?->name ?? 'System',
            'details' => Str::limit($body, 2000),
        ], userId: $by?->id ?? false);
    }
}
