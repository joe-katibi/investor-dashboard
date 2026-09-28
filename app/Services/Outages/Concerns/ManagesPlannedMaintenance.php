<?php

namespace App\Services\Outages\Concerns;

use App\Enums\NotificationEvent;
use App\Enums\QueueType;
use App\Enums\StatusCode;
use App\Events\OutageUpdated;
use App\Models\ChangeRequest;
use App\Models\ChangeRequestReschedule;
use App\Models\ChangeRequestSignature;
use App\Models\Outage;
use App\Models\OutageType;
use App\Models\User;
use App\Services\Maintenance\MaintenanceNotifier;
use App\Support\AuditContext;
use App\Support\Settings;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Planned maintenance:
 *
 *   INFRASTRUCTURE REQUEST → NOC REVIEW (FIFO) → NOC confirms customers, completes the CRF, picks
 *   signatories → CRF SIGN-OFF (stakeholders notified, personal sign links) → all approved →
 *   INFRASTRUCTURE DISPATCH (FIFO) → TECHNICIAN → NOC verifies all customers are up (else back to
 *   the field) → CX NOTIFY CUSTOMERS (FIFO) → RESOLVED.
 *
 * Reminders before the window and the "started" alert are sent by MaintenanceScheduler. SLA:
 * the "Planned Maintenance – window" policy (6 hours by default) from the scheduled start.
 */
trait ManagesPlannedMaintenance
{
    /** CRF fields Infrastructure / NOC can fill in. */
    private const CRF_FIELDS = [
        'initiator', 'department', 'in_charge', 'escalation_time', 'rollback_time', 'emergency_contact', 'risk_tier',
        'work_description', 'fallback_plan', 'prior_tests', 'risks_mitigation', 'checklist', 'stakeholder_emails',
        'customer_list_path', 'customer_list_name',
    ];

    /**
     * Infrastructure raises a planned maintenance request (a ticket + draft CRF) for NOC.
     *
     * @param  array<string, mixed>  $data  title, vertical_id, scheduled_start, scheduled_end, work_description, … topology / services
     */
    public function requestMaintenance(array $data, User $by): Outage
    {
        Gate::forUser($by)->authorize('requestMaintenance', Outage::class);
        [$start, $end] = $this->maintenanceWindow($data['scheduled_start'] ?? null, $data['scheduled_end'] ?? null);
        $this->requireText($data['work_description'] ?? null, 'work_description', 'Describe the work to be done.');

        $type = OutageType::query()->active()->where('is_planned', true)->orderBy('sort_order')->first()
            ?? throw ValidationException::withMessages(['outage_type_id' => 'No active planned maintenance outage type is configured.']);

        $result = DB::transaction(function () use ($data, $by, $type, $start, $end): Outage {
            $outage = $this->newOutage(
                array_merge($data, ['start_time' => $start, 'customers_affected' => (int) ($data['customers_affected'] ?? 0)]),
                $by, $type, StatusCode::MaintenanceRequested, 'Planned maintenance requested',
            );

            ChangeRequest::query()->create(array_merge(Arr::only($data, self::CRF_FIELDS), [
                'outage_id' => $outage->id,
                'initiator' => $data['initiator'] ?? ($by->department?->name ?? 'Infrastructure').' Team',
                'department' => $data['department'] ?? 'NOC',
                'risk_tier' => $data['risk_tier'] ?? 'medium',
                'scheduled_start' => $start,
                'scheduled_end' => $end,
            ]));

            $outage->forceFill(['noc_escalated_at' => now()])->save();
            $this->fifo->enqueue($outage, QueueType::NocReview, ['reason' => 'Planned maintenance: NOC to confirm customers and issue the CRF']);
            $this->notifier->dispatch(NotificationEvent::MaintenanceRequested, $outage, ['window' => $start->format('d/m/Y H:i').' – '.$end->format('d/m/Y H:i')]);

            return $outage->refresh();
        });

        return $result->refresh();
    }

    /**
     * NOC confirms the affected customers, completes the CRF and sends it for sign-off: stakeholders
     * get the notice (CRF attached) and each signatory a personal "Sign CRF" link.
     *
     * @param  array<string, mixed>  $data  customers_affected, CRF fields, signatories: list<{role, user_id?, email?}>
     */
    public function sendCrfForSignoff(Outage $outage, User $by, array $data): Outage
    {
        $signatories = $this->normaliseSignatories($data['signatories'] ?? []);

        if (! isset($data['customers_affected']) || (int) $data['customers_affected'] < 0) {
            throw ValidationException::withMessages(['customers_affected' => 'Confirm the number of affected customers.']);
        }

        $result = DB::transaction(function () use ($outage, $by, $data, $signatories): Outage {
            $outage = $this->lock($outage);
            Gate::forUser($by)->authorize('prepareCrf', $outage);
            $this->fifo->authorizeWork($outage, QueueType::NocReview, $by);
            $changeRequest = ChangeRequest::query()->where('outage_id', $outage->id)->lockForUpdate()->firstOrFail();

            if (isset($data['scheduled_start']) || isset($data['scheduled_end'])) {
                [$start, $end] = $this->maintenanceWindow($data['scheduled_start'] ?? $changeRequest->scheduled_start, $data['scheduled_end'] ?? $changeRequest->scheduled_end);
                $changeRequest->forceFill(['scheduled_start' => $start, 'scheduled_end' => $end]);
                $outage->start_time = $start;
            }

            // NOC confirms the impact: priority and SLA follow the confirmed customer count.
            $previousPriority = $outage->priority_id;
            $outage->customers_affected = (int) $data['customers_affected'];
            $this->applyPriority($outage, [], $by);
            $outage->forceFill(['noc_confirmed_at' => now(), 'noc_confirmed_by' => $by->id])->save();
            $previousPriority !== $outage->priority_id ? $this->onPriorityChanged($outage) : $this->sla->apply($outage, 'recalculated');

            $changeRequest->fill(Arr::only($data, self::CRF_FIELDS));
            $changeRequest->forceFill(['status' => ChangeRequest::PENDING_SIGNOFF, 'sent_for_signoff_at' => now()])->save();

            // Re-issue: previous signatures (e.g. after "changes requested") no longer count.
            $changeRequest->signatures()->delete();
            foreach ($signatories as $i => $signatory) {
                $changeRequest->signatures()->create($signatory + ['sort' => $i]);
            }

            $this->fifo->complete($outage, QueueType::NocReview, $by, 'CRF sent for sign-off');
            $this->transition($outage, StatusCode::CrfPendingSignoff, $by, 'CRF sent for sign-off');
            $this->addSystemUpdate($outage, 'NOC confirmed '.number_format($outage->customers_affected).' affected customers and sent the CRF for sign-off to '
                .collect($signatories)->pluck('role_label')->implode(', ').'.', $by);

            $notifier = app(MaintenanceNotifier::class);
            $changeRequest->unsetRelation('signatures');
            $notifier->announce(NotificationEvent::CrfSignoffRequested, $changeRequest, 'crf_issued', ['notify_user_ids' => [$outage->created_by]]);
            $notifier->sendSignRequests($changeRequest);

            return $outage->refresh();
        });

        return $result->refresh();
    }

    /** New personal links for everyone who has not signed yet. */
    public function resendSignoffRequests(Outage $outage, User $by): int
    {
        Gate::forUser($by)->authorize('resendSignoff', $outage);
        $changeRequest = ChangeRequest::query()->where('outage_id', $outage->id)->firstOrFail();
        $sent = app(MaintenanceNotifier::class)->sendSignRequests($changeRequest);
        $this->addSystemUpdate($outage, "Sign-off request re-sent to {$sent} pending signator".($sent === 1 ? 'y' : 'ies').'.', $by);

        return $sent;
    }

    /**
     * A signatory approves (with name + drawn signature) or requests changes (with a comment),
     * from their personal link. Once everyone has approved, the work goes to Infrastructure.
     *
     * @param  array{decision: string, signed_name: string, signature_image?: ?string, comment?: ?string, ip?: ?string, user_agent?: ?string}  $data
     */
    public function recordSignature(ChangeRequestSignature $signature, array $data): ChangeRequest
    {
        $approve = ($data['decision'] ?? '') === 'approve';
        $this->requireText($data['signed_name'] ?? null, 'signed_name', 'Type your full name.');

        if ($approve && ! preg_match('#^data:image/png;base64,[A-Za-z0-9+/=]+$#', (string) ($data['signature_image'] ?? ''))) {
            throw ValidationException::withMessages(['signature_image' => 'Draw your signature in the box.']);
        }

        if (strlen((string) ($data['signature_image'] ?? '')) > 400_000) {
            throw ValidationException::withMessages(['signature_image' => 'The signature image is too large — clear it and sign again.']);
        }

        if (! $approve) {
            $this->requireText($data['comment'] ?? null, 'comment', 'Describe the changes you need.');
        }

        return DB::transaction(function () use ($signature, $data, $approve): ChangeRequest {
            $changeRequest = ChangeRequest::query()->lockForUpdate()->findOrFail($signature->change_request_id);
            $signature = ChangeRequestSignature::query()->lockForUpdate()->findOrFail($signature->id);

            if ($changeRequest->status !== ChangeRequest::PENDING_SIGNOFF || $signature->status !== ChangeRequestSignature::PENDING) {
                throw ValidationException::withMessages(['decision' => 'This CRF is no longer awaiting your signature.']);
            }

            $signature->forceFill([
                'status' => $approve ? ChangeRequestSignature::APPROVED : ChangeRequestSignature::CHANGES_REQUESTED,
                'signed_name' => trim($data['signed_name']),
                'signature_image' => $approve ? $data['signature_image'] : null,
                'comment' => $data['comment'] ?? null,
                'signed_at' => now(),
                'signed_ip' => $data['ip'] ?? null,
                'signed_user_agent' => isset($data['user_agent']) ? mb_substr((string) $data['user_agent'], 0, 250) : null,
                'token_hash' => null, // single use
            ])->save();

            $outage = $this->lock($changeRequest->outage);
            $by = $signature->user;
            $who = "{$signature->signed_name} ({$signature->role_label})";
            $notifier = app(MaintenanceNotifier::class);

            AuditContext::as('crf-signoff', function () use ($approve, $changeRequest, $outage, $by, $who, $signature, $data, $notifier): void {
                if (! $approve) {
                    // Back to NOC to update and re-issue; the other open links stop working.
                    $changeRequest->forceFill(['status' => ChangeRequest::CHANGES_REQUESTED])->save();
                    $changeRequest->signatures()->where('status', ChangeRequestSignature::PENDING)->update(['token_hash' => null]);
                    $this->transition($outage, StatusCode::MaintenanceRequested, $by, "CRF changes requested by {$who}");
                    $this->addSystemUpdate($outage, "CRF changes requested by {$who}: {$data['comment']}", $by);
                    $this->fifo->enqueue($outage, QueueType::NocReview, ['reason' => "CRF changes requested by {$who}"]);
                    $notifier->announce(NotificationEvent::CrfChangesRequested, $changeRequest, 'changes_requested', [
                        'reason' => $data['comment'],
                        'signatory_name' => $signature->signed_name,
                        'signatory_role' => $signature->role_label,
                        'notify_user_ids' => [$outage->created_by, $outage->noc_confirmed_by],
                    ]);

                    return;
                }

                $changeRequest->load('signatures');
                $this->addSystemUpdate($outage, "CRF approved by {$who} ({$changeRequest->signedCount()} of {$changeRequest->signatures->count()}).", $by);
                $this->notifier->dispatch(NotificationEvent::CrfSigned, $outage, [
                    'signed' => $changeRequest->signedCount(),
                    'total' => $changeRequest->signatures->count(),
                    'reason' => "Approved by {$who}",
                    'notify_user_ids' => [$outage->created_by, $outage->noc_confirmed_by],
                ]);

                if ($changeRequest->fullySigned()) {
                    $this->crfApproved($outage, $changeRequest);
                }
            });

            return $changeRequest->refresh();
        });
    }

    /** Move the maintenance window. Stakeholders and signatories get the updated CRF. */
    public function rescheduleMaintenance(Outage $outage, User $by, mixed $start, mixed $end, string $reason): Outage
    {
        $this->requireText($reason, 'reason', 'Give the reason for rescheduling.');
        [$start, $end] = $this->maintenanceWindow($start, $end);

        $result = DB::transaction(function () use ($outage, $by, $start, $end, $reason): Outage {
            $outage = $this->lock($outage);
            Gate::forUser($by)->authorize('rescheduleMaintenance', $outage);
            $changeRequest = ChangeRequest::query()->where('outage_id', $outage->id)->lockForUpdate()->firstOrFail();
            $notifier = app(MaintenanceNotifier::class);
            $previous = $notifier->window($changeRequest);

            ChangeRequestReschedule::query()->create([
                'change_request_id' => $changeRequest->id,
                'from_start' => $changeRequest->scheduled_start,
                'from_end' => $changeRequest->scheduled_end,
                'to_start' => $start,
                'to_end' => $end,
                'reason' => $reason,
                'user_id' => $by->id,
            ]);

            // Reminders are keyed by the window start, so the new window gets a fresh set.
            $changeRequest->forceFill([
                'scheduled_start' => $start,
                'scheduled_end' => $end,
                'reschedule_count' => $changeRequest->reschedule_count + 1,
                'started_notified_at' => null,
            ])->save();

            $outage->forceFill(['start_time' => $start])->save();
            $this->sla->apply($outage, 'recalculated');
            $this->addSystemUpdate($outage, "Maintenance rescheduled from {$previous} to {$notifier->window($changeRequest)}: {$reason}", $by);

            $reapprove = app(Settings::class)->bool('maintenance.reapprove_on_reschedule')
                && in_array($changeRequest->status, [ChangeRequest::APPROVED, ChangeRequest::PENDING_SIGNOFF], true)
                && $outage->dispatched_at === null;

            if ($reapprove) {
                $changeRequest->signatures()->update([
                    'status' => ChangeRequestSignature::PENDING, 'signed_name' => null, 'signature_image' => null,
                    'signed_at' => null, 'comment' => null, 'token_hash' => null,
                ]);
                $changeRequest->forceFill(['status' => ChangeRequest::PENDING_SIGNOFF, 'approved_at' => null])->save();

                if ($this->statusIs($outage, StatusCode::AssignedToInfrastructure)) {
                    $this->fifo->complete($outage, QueueType::Infrastructure, $by, 'Rescheduled — CRF needs re-approval');
                    $this->transition($outage, StatusCode::CrfPendingSignoff, $by, 'Rescheduled — CRF needs re-approval');
                }
            }

            $changeRequest->unsetRelation('signatures');
            $notifier->announce(NotificationEvent::MaintenanceRescheduled, $changeRequest, 'rescheduled', [
                'reason' => $reason,
                'previous_window' => $previous,
                'notify_user_ids' => [$outage->created_by, $outage->current_assignee_id, $outage->technician_id],
            ]);

            if ($reapprove) {
                $notifier->sendSignRequests($changeRequest->load('signatures'));
            }

            event(new OutageUpdated($outage->id, $outage->ticket_number));

            return $outage->refresh();
        });

        return $result->refresh();
    }

    public function cancelMaintenance(Outage $outage, User $by, string $reason): Outage
    {
        $this->requireText($reason, 'reason', 'Give the reason for cancelling.');

        $result = DB::transaction(function () use ($outage, $by, $reason): Outage {
            $outage = $this->lock($outage);
            Gate::forUser($by)->authorize('cancelMaintenance', $outage);
            $changeRequest = ChangeRequest::query()->where('outage_id', $outage->id)->lockForUpdate()->firstOrFail();

            $changeRequest->forceFill(['status' => ChangeRequest::CANCELLED, 'cancelled_at' => now(), 'cancel_reason' => $reason])->save();
            $changeRequest->signatures()->update(['token_hash' => null]);
            $outage->forceFill(['final_reason' => "Cancelled: {$reason}", 'end_time' => $outage->end_time ?? now()]);
            $this->transition($outage, StatusCode::MaintenanceCancelled, $by, $reason);
            $this->addSystemUpdate($outage, "Planned maintenance cancelled: {$reason}", $by);

            app(MaintenanceNotifier::class)->announce(NotificationEvent::MaintenanceCancelled, $changeRequest, 'cancelled', [
                'reason' => $reason,
                'notify_user_ids' => [$outage->created_by],
            ]);

            return $outage->refresh();
        });

        return $result->refresh();
    }

    /**
     * CX has told customers the maintenance was completed: the ticket closes and everyone gets
     * the completion e-mail with the final CRF.
     *
     * @param  array{channels?: list<string>, note?: ?string}  $data
     */
    public function notifyCustomersMaintenanceDone(Outage $outage, User $by, array $data): Outage
    {
        $this->requireText($data['note'] ?? null, 'note', 'Describe how customers were notified.');

        $result = DB::transaction(function () use ($outage, $by, $data): Outage {
            $outage = $this->lock($outage);
            Gate::forUser($by)->authorize('notifyCustomers', $outage);
            $this->fifo->authorizeWork($outage, QueueType::CxNotify, $by);
            $changeRequest = ChangeRequest::query()->where('outage_id', $outage->id)->lockForUpdate()->firstOrFail();

            $changeRequest->forceFill([
                'customers_notified_at' => now(),
                'customers_notified_by' => $by->id,
                'notification_channels' => array_values((array) ($data['channels'] ?? [])),
                'customer_notification_note' => $data['note'],
            ])->save();

            $outcome = (ChangeRequest::FINAL_STATUSES[$changeRequest->final_status] ?? 'Completed')
                .($changeRequest->outcome_details ? ": {$changeRequest->outcome_details}" : '');

            $outage->forceFill([
                'final_reason' => $outcome,
                'resolved_at' => now(),
                'resolved_by' => $by->id,
                'end_time' => $outage->end_time ?? $outage->restored_at ?? now(),
            ]);

            $this->fifo->complete($outage, QueueType::CxNotify, $by, 'Customers notified');
            $this->transition($outage, StatusCode::Resolved, $by, 'Planned maintenance completed — customers notified');
            $this->addSystemUpdate($outage, 'CX notified customers that the maintenance was completed'
                .(($channels = implode(', ', (array) ($data['channels'] ?? []))) ? " ({$channels})" : '').": {$data['note']}", $by);

            app(MaintenanceNotifier::class)->announce(NotificationEvent::MaintenanceCompleted, $changeRequest, 'completed', [
                'reason' => $data['note'],
                'notify_user_ids' => [$outage->created_by, $outage->noc_confirmed_by],
            ]);

            return $outage->refresh();
        });

        return $result->refresh();
    }

    // ------------------------------------------------------------------ internals

    /** Everyone approved: notify, then hand over to Infrastructure dispatch. */
    private function crfApproved(Outage $outage, ChangeRequest $changeRequest): void
    {
        $changeRequest->forceFill(['status' => ChangeRequest::APPROVED, 'approved_at' => now()])->save();

        $outage->forceFill(['infra_escalated_at' => now()]);
        $this->transition($outage, StatusCode::AssignedToInfrastructure, null, 'CRF fully signed — escalated to Infrastructure');
        $this->addSystemUpdate($outage, 'CRF fully signed. Escalated to Infrastructure dispatch to assign the team.', null);
        $this->fifo->enqueue($outage, QueueType::Infrastructure, ['team_id' => $outage->team_id, 'reason' => 'CRF approved — implement the planned maintenance']);

        app(MaintenanceNotifier::class)->announce(NotificationEvent::CrfApproved, $changeRequest, 'fully_signed', ['notify_user_ids' => [$outage->created_by]]);
        $this->notifier->dispatch(NotificationEvent::InfrastructureEscalation, $outage);
    }

    /** NOC verified all customers are up after the technician finished (called from confirmRestoration). */
    private function maintenanceVerified(Outage $outage, ChangeRequest $changeRequest, User $by, ?string $note, array $maintenance): void
    {
        $changeRequest->forceFill([
            'final_status' => $maintenance['final_status'],
            'outcome_details' => $maintenance['outcome_details'] ?? $note,
        ])->save();

        $this->fifo->complete($outage, QueueType::NocRestore, $by, 'NOC verified all customers are up');
        $this->transition($outage, StatusCode::CxCustomerNotification, $by, $note ?: 'NOC verified all customers are up');
        $this->addSystemUpdate($outage, 'NOC verified all customers are up — '.ChangeRequest::FINAL_STATUSES[$maintenance['final_status']]
            .(($maintenance['outcome_details'] ?? null) ? ": {$maintenance['outcome_details']}" : '').'. Escalated to CX to notify customers.', $by);
        $this->fifo->enqueue($outage, QueueType::CxNotify, ['reason' => 'Planned maintenance done — notify customers']);
        $this->notifier->dispatch(NotificationEvent::RestoreConfirmed, $outage, ['reason' => $note]);
    }

    /** @return array{0: Carbon, 1: Carbon} */
    private function maintenanceWindow(mixed $start, mixed $end): array
    {
        if (blank($start) || blank($end)) {
            throw ValidationException::withMessages(['scheduled_start' => 'Enter the scheduled start and end.']);
        }

        $start = Carbon::parse($start);
        $end = Carbon::parse($end);

        if ($end->lessThanOrEqualTo($start)) {
            throw ValidationException::withMessages(['scheduled_end' => 'The end must be after the start.']);
        }

        return [$start, $end];
    }

    /** @return list<array{role_label: string, user_id: ?int, name: ?string, email: string}> */
    private function normaliseSignatories(array $rows): array
    {
        $signatories = [];

        foreach ($rows as $row) {
            $role = trim((string) ($row['role'] ?? $row['role_label'] ?? ''));
            $user = ! empty($row['user_id']) ? User::query()->active()->find($row['user_id']) : null;
            $email = $user?->email ?? trim((string) ($row['email'] ?? ''));

            if ($role === '' && $email === '') {
                continue;
            }

            if ($role === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw ValidationException::withMessages(['signatories' => 'Each signatory needs a role and a person (user or valid e-mail).']);
            }

            $signatories[] = ['role_label' => $role, 'user_id' => $user?->id, 'name' => $user?->name ?? ($row['name'] ?? null), 'email' => $email];
        }

        if ($signatories === []) {
            throw ValidationException::withMessages(['signatories' => 'Add at least one signatory.']);
        }

        return $signatories;
    }
}
