<?php

namespace App\Services\Outages\Concerns;

use App\Enums\QueueType;
use App\Enums\StatusCode;
use App\Events\OutageUpdated;
use App\Models\Outage;
use App\Models\Ticket;
use App\Models\TicketFollowUp;
use App\Models\User;
use App\Support\AftercareCalls;
use App\Support\AuditContext;
use App\Support\Settings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * The call 72 hours after a site visit (CX Follow-up – Site Visit): CX Field Support has confirmed the
 * visit with the customer and closed it; three days on, an agent calls again to check the service is
 * still fine. The visit stays closed while the call is pending — the queue runs beside it.
 *
 *   happy                  → closed with the feedback
 *   the same issue is back → the visit is reopened for a team (Technician Revisit / NOC Escalation)
 *   a different issue      → a new customer ticket for it, raised from the call
 *   not reached / callback → offered again later; after the last attempt, closed as unreachable
 */
trait ManagesVisitAftercare
{
    /** Called by the scheduler for a visit confirmed long enough ago. False when it is not (or no longer) due. */
    public function flagVisitAftercare(int $outageId): bool
    {
        return (bool) AuditContext::as('system:visit-aftercare', fn () => DB::transaction(function () use ($outageId): bool {
            $outage = Outage::query()->with(['status', 'priority'])->lockForUpdate()->find($outageId);
            $ticket = $outage ? Ticket::query()->where('outage_id', $outage->id)->first() : null;

            if (! $outage || ! $ticket || ! $outage->isSiteVisit() || ! $outage->is_closed || $ticket->confirmed_at === null || $ticket->aftercare_due_at !== null
                || ! $this->statusIs($outage, StatusCode::VisitClosed, StatusCode::VisitClosedEscalated)) {
                return false;
            }

            $hours = app(Settings::class)->int('ticketing.aftercare_hours');
            $ticket->forceFill(['aftercare_due_at' => now(), 'aftercare_attempts' => 0])->save();
            $this->fifo->enqueue($outage, QueueType::VisitAftercare, ['reason' => "{$hours} hours after the visit — call the customer: is the service still fine?"]);
            $this->ticketEvent($outage, 'aftercare_due', null, QueueType::VisitAftercare, "{$hours}-hour follow-up call due");
            $this->ticketUpdate($outage, "{$hours} hours since the visit was confirmed: placed in the CX Follow-up – Site Visit queue for a call to the customer.", null, 'system', 'aftercare_due');

            return true;
        }));
    }

    /**
     * The agent's call: disposition, feedback, ratings and what happens next.
     *
     * @param  array{disposition: string, action_required?: ?string, rating?: mixed, service_working?: mixed, installation_quality?: ?string, technician_conduct?: ?string, notes?: ?string, callback_at?: mixed, subcategory_id?: mixed, new_ticket_narration?: ?string}  $data
     */
    public function recordVisitAftercare(Outage $outage, User $by, array $data): TicketFollowUp
    {
        $disposition = (string) ($data['disposition'] ?? '');

        if (AftercareCalls::main($disposition) === null) {
            throw ValidationException::withMessages(['disposition' => 'Select the disposition of the call.']);
        }

        $callOutcome = AftercareCalls::callOutcome($disposition);
        $reached = $callOutcome === 'connected';
        $action = $reached ? (string) ($data['action_required'] ?? '') : 'callback';

        if (! array_key_exists($action, AftercareCalls::ACTIONS) || ($reached && $action === 'callback')) {
            throw ValidationException::withMessages(['action_required' => $reached ? 'Select what happens next.' : 'Select the action.']);
        }

        if ($reached) {
            $this->requireText($data['notes'] ?? null, 'notes', 'Write what the customer said.');
        }

        if ($action === 'raise_ticket') {
            $this->requireText($data['new_ticket_narration'] ?? null, 'new_ticket_narration', 'Describe the new issue for the ticket.');
        }

        $rating = is_numeric($data['rating'] ?? null) ? (int) $data['rating'] : null;

        if ($rating !== null && ($rating < 1 || $rating > 5)) {
            throw ValidationException::withMessages(['rating' => 'The rating is from 1 to 5.']);
        }

        $callbackAt = filled($data['callback_at'] ?? null) ? Carbon::parse($data['callback_at']) : null;
        $settings = app(Settings::class);

        return DB::transaction(function () use ($outage, $by, $data, $disposition, $callOutcome, $reached, $action, $rating, $callbackAt, $settings): TicketFollowUp {
            $outage = $this->lock($outage);
            Gate::forUser($by)->authorize('visitAftercare', $outage);
            $ticket = $this->ticketOf($outage);
            $this->fifo->authorizeWork($outage, QueueType::VisitAftercare, $by);

            $quality = fn (?string $value) => array_key_exists((string) $value, AftercareCalls::QUALITY) ? $value : null;
            $call = TicketFollowUp::query()->create([
                'outage_id' => $outage->id,
                'user_id' => $by->id,
                'kind' => 'aftercare',
                // In the words of the other customer calls, too.
                'outcome' => match (true) {
                    $callOutcome === 'callback' => 'call_back',
                    ! $reached => 'unreachable',
                    AftercareCalls::feedback($disposition) === 'satisfied' => 'confirmed',
                    default => 'not_resolved',
                },
                'notes' => filled($data['notes'] ?? null) ? trim((string) $data['notes']) : null,
                'called_at' => now(),
                'call_outcome' => $callOutcome,
                'feedback' => AftercareCalls::feedback($disposition),
                'disposition' => $disposition,
                'action_required' => $action,
                'rating' => $reached ? $rating : null,
                'service_working' => $reached && isset($data['service_working']) && $data['service_working'] !== '' ? filter_var($data['service_working'], FILTER_VALIDATE_BOOLEAN) : null,
                'installation_quality' => $reached ? $quality($data['installation_quality'] ?? null) : null,
                'technician_conduct' => $reached ? $quality($data['technician_conduct'] ?? null) : null,
                'callback_at' => $callbackAt,
            ]);

            $attempts = $ticket->aftercare_attempts + 1;
            $label = AftercareCalls::label($disposition);
            $summary = "{$settings->int('ticketing.aftercare_hours')}-hour follow-up — {$label}".($call->notes ? ": {$call->notes}" : '.');
            $ticket->forceFill(['aftercare_attempts' => $attempts, 'followed_up_at' => now(), 'followed_up_by' => $by->id, 'follow_up_outcome' => $call->outcome])->save();
            $this->fifo->complete($outage, QueueType::VisitAftercare, $by, Str::limit($label, 200));

            if (! $reached) {
                $last = $attempts >= max(1, $settings->int('ticketing.aftercare_max_attempts'));

                if ($last && $callOutcome === 'unreachable') {
                    $this->finishAftercare($ticket, 'unreachable');
                    $summary .= " Not reached after {$attempts} attempts: closed as unreachable.";
                } else {
                    $next = $callbackAt && $callbackAt->isFuture() ? $callbackAt : now()->addHours(max(1, $settings->int('ticketing.aftercare_retry_hours')));
                    $this->fifo->enqueue($outage, QueueType::VisitAftercare, ['eligible_from' => $next, 'reason' => "Call again ({$label}) from ".$next->format('d M H:i')]);
                    $summary .= ' Call again from '.$next->format('d M Y H:i').'.';
                }
            } elseif (in_array($action, AftercareCalls::REOPENS, true)) {
                $this->finishAftercare($ticket, 'reopened');
                $why = $action === 'noc' ? 'NOC intervention required' : 'technician revisit required';
                $this->reopenClosedTicket($outage, $ticket->refresh(), $by, "{$settings->int('ticketing.aftercare_hours')}-hour follow-up: same issue, {$why} — {$label}: {$call->notes}");
                $summary .= ' The visit is reopened for a team.';
            } elseif ($action === 'raise_ticket') {
                $new = $this->createTicket([
                    'subcategory_id' => $data['subcategory_id'] ?? null,
                    'account_number' => $ticket->account_number, 'customer_name' => $ticket->customer_name,
                    'calling_number' => $ticket->calling_number ?: $ticket->primary_number, 'primary_number' => $ticket->primary_number,
                    'alt_number_1' => $ticket->alt_number_1, 'alt_number_2' => $ticket->alt_number_2,
                    'address' => $ticket->address, 'landmark' => $ticket->landmark, 'region_id' => $ticket->region_id,
                    'olt_id' => $ticket->olt_id, 'fdt_id' => $ticket->fdt_id, 'slot_id' => $ticket->slot_id, 'port_id' => $ticket->port_id,
                    'source' => 'call',
                    'description' => trim((string) $data['new_ticket_narration'])." (raised on the follow-up call after visit {$outage->ticket_number})",
                ], $by);
                $call->forceFill(['new_outage_id' => $new->id])->save();
                $this->finishAftercare($ticket, 'new_ticket');
                $summary .= " New ticket {$new->ticket_number} raised for the new issue.";
            } else {
                $this->finishAftercare($ticket, AftercareCalls::feedback($disposition) ?? 'other');
            }

            $this->ticketEvent($outage, 'aftercare_call', $by, QueueType::VisitAftercare, Str::limit($label.($call->notes ? ": {$call->notes}" : ''), 500),
                ['disposition' => $disposition, 'action' => $action, 'rating' => $call->rating]);
            $this->ticketUpdate($outage, $summary, $by, 'action', 'aftercare_call');
            event(new OutageUpdated($outage->id, $outage->ticket_number));

            return $call;
        });
    }

    private function finishAftercare(Ticket $ticket, string $result): void
    {
        $ticket->forceFill(['aftercare_done_at' => now(), 'aftercare_result' => $result])->save();
    }
}
