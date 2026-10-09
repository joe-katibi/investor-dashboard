<?php

namespace App\Services\Outages\Concerns;

use App\Enums\NotificationChannel;
use App\Enums\NotificationEvent;
use App\Enums\QueueType;
use App\Enums\StatusCode;
use App\Events\OutageCreated;
use App\Events\OutageUpdated;
use App\Models\AuditLog;
use App\Models\CustomFlow;
use App\Models\CustomTicket;
use App\Models\Outage;
use App\Models\OutageStatus;
use App\Models\OutageType;
use App\Models\Priority;
use App\Models\User;
use App\Support\Settings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Tickets of the workflows built from the screen (Configuration → Custom Workflows).
 *
 *   RAISED → step 1 → step 2 → … → last step → COMPLETED
 *
 * Each step is a FIFO queue of its own for the team named on it: the oldest ticket goes to the
 * team member waiting longest (or waits for a manager when the step is "manual"). The person
 * holding the ticket completes the step — it moves to the next one — or sends it back to the step
 * before with a reason. The last step closes the ticket with a closing note.
 *
 * A ticket follows the steps as they were when it was raised (it carries a copy), so a flow can be
 * edited, reordered or switched off at any time without touching tickets already on their way.
 */
trait ManagesCustomFlows
{
    /**
     * @param  array{title?: ?string, description?: ?string, priority_id?: mixed}  $data
     */
    public function raiseCustomTicket(CustomFlow $flow, array $data, User $by): Outage
    {
        Gate::forUser($by)->authorize('raiseCustom', Outage::class);

        if (! $flow->mayBeRaisedBy($by)) {
            throw ValidationException::withMessages(['custom_flow_id' => $flow->is_active ? 'You may not raise tickets in this workflow.' : 'This workflow is switched off.']);
        }

        $flow->load('steps.team');

        if ($problems = $flow->problems()) {
            throw ValidationException::withMessages(['custom_flow_id' => 'This workflow is not ready: '.implode(' ', $problems)]);
        }

        $this->requireText($data['title'] ?? null, 'title', 'Give the ticket a title.');

        $result = DB::transaction(function () use ($flow, $data, $by): Outage {
            $type = OutageType::query()->where('code', OutageType::CODE_CUSTOM)->first()
                ?? throw ValidationException::withMessages(['custom_flow_id' => 'The custom workflow reference data is missing. Run: php artisan db:seed --class=CustomWorkflowSeeder']);

            $outage = $this->newTicketOutage($type, $by, [
                'title' => Str::limit(trim((string) $data['title']), 250, ''),
                'description' => filled($data['description'] ?? null) ? trim((string) $data['description']) : null,
                'priority_id' => filled($data['priority_id'] ?? null) ? (int) $data['priority_id'] : Priority::query()->active()->orderByDesc('queue_rank')->value('id'),
                'customers_affected' => 0,
                'start_time' => now(),
                'ticket_number' => $this->tickets->next($flow->prefix, OutageType::TICKET_DIGITS),
            ], StatusCode::CustomOpen);

            $ticket = CustomTicket::query()->create([
                'outage_id' => $outage->id,
                'custom_flow_id' => $flow->id,
                'flow_name' => $flow->name,
                'flow_version' => $flow->version,
                'steps' => $flow->snapshot(),
                'step_index' => 0,
            ]);

            $this->recordHistory($outage, null, OutageStatus::query()->findOrFail($outage->status_id), 'Raised in workflow “'.$flow->name.'”', $by);
            $this->sla->applyMinutes($outage, $flow->sla_minutes ?: app(Settings::class)->int('sla.default_resolution_minutes'), 'started', $flow->name);
            $this->addSystemUpdate($outage, "Raised in workflow “{$flow->name}” (version {$flow->version})."
                .(filled($outage->description) ? ' '.Str::limit((string) $outage->description, 300) : ''), $by);
            $this->customAudit($outage, 'raised', $by, ['workflow' => $flow->name, 'version' => $flow->version]);

            event(new OutageCreated($outage->id, $outage->ticket_number));
            $this->enterCustomStep($outage, $ticket, 0, $by, 'New ticket');

            return $outage->refresh();
        });

        return $result->refresh();
    }

    /** The person holding the ticket finishes their step: on to the next one, or — at the last step — the ticket is completed. */
    public function completeCustomStep(Outage $outage, User $by, ?string $note = null): Outage
    {
        $result = DB::transaction(function () use ($outage, $by, $note): Outage {
            $outage = $this->lock($outage);
            Gate::forUser($by)->authorize('workCustomStep', $outage);
            $this->fifo->authorizeWork($outage, QueueType::CustomStep, $by);

            $ticket = $this->customTicketOf($outage);
            $step = $ticket->step() ?? throw ValidationException::withMessages(['step' => 'This ticket has no step to complete.']);
            $note = filled($note) ? trim((string) $note) : null;
            $last = $ticket->isLastStep();

            if ($note === null && ($step['note_required'] || ($last && ($ticket->flow?->requires_resolution ?? true)))) {
                throw ValidationException::withMessages(['note' => $last ? 'Say how the ticket was resolved before completing it.' : 'A note is required to complete this step.']);
            }

            $this->fifo->complete($outage, QueueType::CustomStep, $by, Str::limit('Step “'.$step['name'].'” completed'.($note ? ": {$note}" : ''), 200));
            $this->addSystemUpdate($outage, "Step “{$step['name']}” completed by {$by->name}".($note ? ": {$note}" : '.'), $by);
            $this->customAudit($outage, 'step_completed', $by, ['step' => $step['name'], 'comment' => $note]);

            if ($note) {
                $outage->forceFill(['latest_update' => Str::limit($note, 5000)])->save();
            }

            if (! $last) {
                $this->enterCustomStep($outage, $ticket, $ticket->step_index + 1, $by, "From “{$step['name']}”".($note ? ": {$note}" : ''));

                return $outage->refresh();
            }

            $summary = $note ?? 'All steps completed.';
            $ticket->forceFill(['step_index' => count($ticket->steps), 'step_since' => now()])->save();
            $outage->forceFill([
                'final_reason' => Str::limit($summary, 5000), 'resolved_at' => now(), 'resolved_by' => $by->id, 'end_time' => now(),
            ]);
            $this->transition($outage, StatusCode::CustomClosed, $by, Str::limit($summary, 500));
            $this->customAudit($outage, 'completed', $by, ['comment' => $summary]);
            $this->notifier->dispatch(NotificationEvent::Resolved, $outage, ['reason' => $summary]);
            $this->tellCustomRaiser($outage, NotificationEvent::Resolved, $by, $summary);

            return $outage->refresh();
        });

        return $result->refresh();
    }

    /** Not right yet: back to the step before, with the reason. */
    public function sendBackCustomStep(Outage $outage, User $by, string $reason): Outage
    {
        $this->requireText($reason, 'reason', 'Say why the ticket is sent back.');

        $result = DB::transaction(function () use ($outage, $by, $reason): Outage {
            $outage = $this->lock($outage);
            Gate::forUser($by)->authorize('workCustomStep', $outage);
            $this->fifo->authorizeWork($outage, QueueType::CustomStep, $by);

            $ticket = $this->customTicketOf($outage);
            $step = $ticket->step();

            if (! $step || $ticket->step_index === 0 || ! $step['can_send_back']) {
                throw ValidationException::withMessages(['step' => 'This step cannot send the ticket back.']);
            }

            $previous = $ticket->step($ticket->step_index - 1);
            $this->fifo->complete($outage, QueueType::CustomStep, $by, Str::limit("Sent back to “{$previous['name']}”: {$reason}", 200));
            $ticket->forceFill(['sent_back_count' => $ticket->sent_back_count + 1])->save();
            $outage->forceFill(['latest_update' => Str::limit($reason, 5000)])->save();
            $this->addSystemUpdate($outage, "Sent back from “{$step['name']}” to “{$previous['name']}” by {$by->name}: {$reason}", $by);
            $this->customAudit($outage, 'sent_back', $by, ['step' => $step['name'], 'to' => $previous['name'], 'comment' => $reason]);
            $this->enterCustomStep($outage, $ticket, $ticket->step_index - 1, $by, "Sent back from “{$step['name']}”: {$reason}");

            return $outage->refresh();
        });

        return $result->refresh();
    }

    /** Stop the ticket where it is: no SLA outcome, the reason is kept. */
    public function cancelCustomTicket(Outage $outage, User $by, string $reason): Outage
    {
        $this->requireText($reason, 'reason', 'A reason is required to cancel.');

        $result = DB::transaction(function () use ($outage, $by, $reason): Outage {
            $outage = $this->lock($outage);
            Gate::forUser($by)->authorize('cancelCustom', $outage);

            $outage->forceFill([
                'final_reason' => Str::limit('Cancelled: '.$reason, 5000), 'cancelled_at' => now(), 'cancelled_by' => $by->id,
                'resolved_at' => now(), 'resolved_by' => $by->id, 'end_time' => now(), 'latest_update' => Str::limit($reason, 5000),
            ]);
            $this->transition($outage, StatusCode::CustomCancelled, $by, Str::limit($reason, 500));
            $this->addSystemUpdate($outage, "Cancelled by {$by->name}: {$reason}", $by);
            $this->customAudit($outage, 'cancelled', $by, ['comment' => $reason]);
            $this->tellCustomRaiser($outage, NotificationEvent::Resolved, $by, 'Cancelled: '.$reason);

            return $outage->refresh();
        });

        return $result->refresh();
    }

    // ------------------------------------------------------------------ internals

    private function customTicketOf(Outage $outage): CustomTicket
    {
        return CustomTicket::query()->where('outage_id', $outage->id)->lockForUpdate()->with('flow')->first()
            ?? throw ValidationException::withMessages(['ticket' => 'This is not a ticket of a custom workflow.']);
    }

    /** The ticket arrives at a step: it joins that step's queue for the step's team. */
    private function enterCustomStep(Outage $outage, CustomTicket $ticket, int $index, ?User $by, string $reason): void
    {
        $step = $ticket->step($index);
        $ticket->forceFill(['step_index' => $index, 'step_since' => now()])->save();

        $this->fifo->enqueue($outage, QueueType::CustomStep, [
            'team_id' => $step['team_id'],
            'any_department' => true,
            'step_name' => $step['name'],
            'sla_minutes' => (int) ($step['target_minutes'] ?? 0),
            'manual' => ($step['assignment_mode'] ?? 'auto') === 'manual',
            'reason' => Str::limit("{$ticket->flow_name} — step “{$step['name']}”. {$reason}", 250),
        ]);

        event(new OutageUpdated($outage->id, $outage->ticket_number));
    }

    /** The person who raised the ticket hears how it ended (unless they ended it themselves). */
    private function tellCustomRaiser(Outage $outage, NotificationEvent $event, User $by, string $reason): void
    {
        if ($outage->created_by && (int) $outage->created_by !== (int) $by->id && $raiser = User::query()->active()->find($outage->created_by)) {
            $this->notifier->notifyUsers($event, $outage, [$raiser], [NotificationChannel::InApp->value], ['reason' => $reason, 'by' => $by->name]);
        }
    }

    private function customAudit(Outage $outage, string $action, User $by, array $details = []): void
    {
        $outage->loadMissing('status');

        $outage->audit(AuditLog::WORKFLOW_PREFIX.$action, array_filter([
            'ticket' => $outage->ticket_number, 'status' => $outage->status?->name,
        ] + $details, fn ($value) => $value !== null), [], $by->id);
    }
}
