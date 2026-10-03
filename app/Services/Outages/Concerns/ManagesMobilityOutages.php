<?php

namespace App\Services\Outages\Concerns;

use App\Enums\AssignmentType;
use App\Enums\NotificationChannel;
use App\Enums\NotificationEvent;
use App\Enums\QueueType;
use App\Enums\SlaState;
use App\Enums\StatusCode;
use App\Events\OutageCreated;
use App\Events\OutageUpdated;
use App\Models\AuditLog;
use App\Models\Enodeb;
use App\Models\NotificationLog;
use App\Models\Outage;
use App\Models\OutageAttachment;
use App\Models\OutageReopening;
use App\Models\OutageStatus;
use App\Models\OutageType;
use App\Models\OutageUpdate;
use App\Models\Priority;
use App\Models\Team;
use App\Models\TransmissionNode;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorTemplate;
use App\Models\VendorUpdate;
use App\Models\Vertical;
use App\Services\Mobility\MobilityImpact;
use App\Services\Mobility\VendorNotifier;
use App\Support\AuditContext;
use App\Support\Settings;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * 4G NOC outage management. Two workflows, chosen by the outage type:
 *
 *   INTERNAL (eNodeB, Transmission):  4G NOC creates → assigns an Engineer / Technician → engineer
 *   investigates and marks "Resolved – Pending NOC Confirmation" → NOC verifies → Closed.
 *   Transmission outages go to the 4G engineers, eNodeB outages to the 4G technicians
 *   (OutageType::HANDLERS): FIFO only offers a ticket to, and the NOC can only pick, such a person.
 *
 *   VENDOR (Power): 4G NOC creates and selects the vendor → the vendor is e-mailed automatically →
 *   the vendor works outside the system and the NOC records every vendor update by hand → the vendor
 *   reports restoration ("Vendor Resolved – Pending NOC Verification") → NOC verifies → Closed.
 *
 * Engineers, technicians and vendors can never close a ticket: only the NOC does, after verifying
 * the service. Closed tickets can be reopened with a reason. Everything lands on the ticket timeline.
 */
trait ManagesMobilityOutages
{
    /** Statuses the NOC (or the engineer, where marked) may set by hand, per workflow. */
    public const MOBILITY_MANUAL_STATUSES = [
        OutageType::WORKFLOW_MOBILITY_INTERNAL => [StatusCode::MobilityOpen, StatusCode::MobilityInProgress, StatusCode::MobilityMonitoring],
        OutageType::WORKFLOW_MOBILITY_VENDOR => [
            StatusCode::MobilityOpen, StatusCode::MobilityVendorAcknowledged, StatusCode::MobilityVendorInvestigating, StatusCode::MobilityMonitoring,
        ],
    ];

    // ------------------------------------------------------------------ create / edit

    /**
     * @param  array<string, mixed>  $data  outage_type_id, title, description, location, start_time, detected_at, enodeb_ids, node_ids,
     *                                      cells_down, customers_affected, service_ids, service_impact, transmission_link_id, transmission_type,
     *                                      priority_is_manual + priority_id, vendor_id (vendor types), engineer_id + assignment_note (internal types)
     */
    public function createMobility(array $data, User $by): Outage
    {
        Gate::forUser($by)->authorize('createMobility', Outage::class);
        $this->requireText($data['title'] ?? null, 'title', 'Give the outage a title.');

        $type = OutageType::query()->active()->mobility()->find($data['outage_type_id'] ?? 0)
            ?? throw ValidationException::withMessages(['outage_type_id' => 'Select a 4G outage type.']);

        $vendor = $type->usesVendor()
            ? (Vendor::query()->active()->find($data['vendor_id'] ?? 0)
                ?? throw ValidationException::withMessages(['vendor_id' => 'Select the (active) vendor responsible for this outage.']))
            : null;

        $start = Carbon::parse($data['start_time'] ?? now());
        $detected = Carbon::parse($data['detected_at'] ?? now());

        if ($start->greaterThan(now()->addMinutes(5))) {
            throw ValidationException::withMessages(['start_time' => 'The outage start time cannot be in the future.']);
        }

        $result = DB::transaction(function () use ($data, $by, $type, $vendor, $start, $detected): Outage {
            $outage = new Outage(Arr::only($data, ['title', 'description', 'location', 'service_impact', 'transmission_link_id', 'transmission_type']));
            $outage->outage_type_id = $type->id;
            $outage->major_outage_type_id = $type->major_outage_type_id;
            $outage->vertical_id = $type->vertical_id ?? Vertical::query()->where('code', Vertical::MOBILITY_4G)->value('id');
            $outage->workflow = $type->workflow;
            $outage->fault_domain = $type->fault_domain;
            $outage->vendor_id = $vendor?->id;
            $outage->ticket_number = $this->tickets->next($type->prefix, OutageType::MOBILITY_TICKET_DIGITS);
            $outage->start_time = $start;
            $outage->detected_at = $detected;

            $initial = OutageStatus::forCode(StatusCode::MobilityNew);
            $outage->status_id = $initial->id;
            $outage->is_closed = false;
            $outage->sla_state = SlaState::Compliant;
            $outage->created_by = $by->id;
            $outage->updated_by = $by->id;
            $outage->save();

            $this->syncMobilityElements($outage, $data, forNewOutage: true);
            $this->syncServices($outage, $data['service_ids'] ?? [], forNewOutage: true);
            $this->applyMobilityImpact($outage, $data);

            $outage->refresh();
            $this->recordHistory($outage, null, $initial, 'Ticket created', $by);
            $this->sla->apply($outage, 'started');
            $this->addMobilityUpdate($outage, "Ticket created: {$outage->title}".(($impact = $outage->impactLabel()) ? " — affected: {$impact}" : '').'.', $by, action: 'created');

            event(new OutageCreated($outage->id, $outage->ticket_number));
            $this->notifier->dispatch(NotificationEvent::OutageCreated, $outage);

            if ($vendor) {
                // Step 2–3 of the power workflow: the vendor is assigned and e-mailed at once.
                $this->assignVendorTo($outage, $vendor, $by, VendorTemplate::TICKET_CREATED);
            } elseif (! empty($data['engineer_id'])) {
                $this->assignEngineerTo($outage, User::query()->findOrFail($data['engineer_id']), $by, $data['assignment_note'] ?? null);
            } else {
                // No engineer picked: the 4G engineer FIFO queue gives it to the next available one.
                $this->queueForEngineer($outage, $by, $this->mobilityTeamId($data['team_id'] ?? null), $data['assignment_note'] ?? null);
            }

            return $outage->refresh();
        });

        return $result->refresh();
    }

    public function updateMobility(Outage $outage, array $data, User $by): Outage
    {
        $result = DB::transaction(function () use ($outage, $data, $by): Outage {
            $outage = $this->lock($outage);
            Gate::forUser($by)->authorize('mobilityEdit', $outage);
            $previousPriority = $outage->priority_id;

            $outage->fill(Arr::only($data, [
                'title', 'description', 'location', 'service_impact', 'transmission_link_id', 'transmission_type', 'start_time', 'detected_at',
            ]));
            $startChanged = $outage->isDirty('start_time');
            $outage->save();

            if (array_key_exists('enodeb_ids', $data) || array_key_exists('node_ids', $data)) {
                $this->syncMobilityElements($outage, $data, forNewOutage: false);
            }

            if (array_key_exists('service_ids', $data)) {
                $this->syncServices($outage, $data['service_ids'] ?? [], forNewOutage: false);
            }

            $this->applyMobilityImpact($outage, $data);

            if ((int) $previousPriority !== (int) $outage->priority_id) {
                $this->onPriorityChanged($outage);
            } elseif ($startChanged) {
                $this->sla->apply($outage, 'recalculated');
            }

            event(new OutageUpdated($outage->id, $outage->ticket_number));

            return $outage->refresh();
        });

        return $result->refresh();
    }

    // ------------------------------------------------------------------ internal tickets: NOC → engineer → NOC

    /**
     * NOC assigns (or re-assigns) the ticket: to a named engineer / technician, or — without one —
     * to the 4G engineer FIFO queue, optionally limited to one team.
     */
    public function assignMobilityEngineer(Outage $outage, User $by, ?int $engineerId, ?string $note = null, ?int $teamId = null): Outage
    {
        $result = DB::transaction(function () use ($outage, $by, $engineerId, $note, $teamId): Outage {
            $outage = $this->lock($outage);
            Gate::forUser($by)->authorize('mobilityAssign', $outage);

            $engineerId
                ? $this->assignEngineerTo($outage, User::query()->findOrFail($engineerId), $by, $note)
                : $this->queueForEngineer($outage, $by, $this->mobilityTeamId($teamId), $note);

            return $outage->refresh();
        });

        return $result->refresh();
    }

    /**
     * Keeps an internal 4G ticket in step with its engineer FIFO item. The FIFO engine assigns,
     * takes back (reject, acknowledgement timeout, engineer went off shift) and re-assigns on its
     * own; this turns that into the ticket's status, engineer and timeline:
     *
     *   someone holds the item   → engineer set, New / Open becomes ASSIGNED
     *   nobody holds it any more → ASSIGNED / IN PROGRESS / MONITORING becomes OPEN (waiting in the queue)
     *
     * Called after every assignment event; does nothing when the ticket is already in step.
     */
    public function syncMobilityAssignment(int $outageId): void
    {
        // One cheap look before locking: FTTH tickets and closed tickets are not ours.
        if (Outage::query()->whereKey($outageId)->where('workflow', OutageType::WORKFLOW_MOBILITY_INTERNAL)->where('is_closed', false)->doesntExist()) {
            return;
        }

        AuditContext::as('system:fifo-engine', fn () => DB::transaction(function () use ($outageId): void {
            $outage = Outage::query()->with(['status', 'priority'])->lockForUpdate()->find($outageId);
            $entry = $outage?->openQueueEntries()->where('queue_type', QueueType::MobilityEngineer->value)->first();

            if (! $outage || $outage->is_closed || ! $entry) {
                return; // not at the engineer step (with the NOC, or waiting for NOC verification)
            }

            $assignment = $entry->activeAssignment()->with(['user:id,name', 'assignedBy:id,name'])->first();

            if ($assignment) {
                $newEngineer = (int) $outage->technician_id !== (int) $assignment->user_id;
                $outage->forceFill(['technician_id' => $assignment->user_id, 'acknowledged_at' => $outage->acknowledged_at ?? now()]);

                $how = $assignment->assignment_type === AssignmentType::AutoFifo
                    ? 'automatically by FIFO (next available '.$this->mobilityHandler($outage).')'
                    : 'by '.($assignment->assignedBy?->name ?? 'a manager');

                // A new engineer starts at "Assigned", also when the previous one had already begun.
                if ($this->statusIs($outage, StatusCode::MobilityNew, StatusCode::MobilityOpen)
                    || ($newEngineer && $this->statusIs($outage, StatusCode::MobilityInProgress, StatusCode::MobilityMonitoring))) {
                    $this->transition($outage, StatusCode::MobilityAssigned, null, "Assigned to {$assignment->user?->name} {$how}");
                } elseif ($outage->isDirty()) {
                    $outage->save();
                }

                if ($newEngineer) {
                    $this->addMobilityUpdate($outage, "Assigned to {$assignment->user?->name} {$how}.", null, action: 'fifo_assigned');
                }

                return;
            }

            if ($this->statusIs($outage, StatusCode::MobilityAssigned, StatusCode::MobilityInProgress, StatusCode::MobilityMonitoring)) {
                $outage->forceFill(['technician_id' => null]);
                $who = $this->mobilityHandler($outage);
                $this->transition($outage, StatusCode::MobilityOpen, null, 'Back in the 4G queue');
                $this->addMobilityUpdate($outage, "Nobody is on the ticket any more (assignment rejected, not accepted in time, or the person became unavailable). It is back in the 4G queue for the next available {$who}.", null, action: 'fifo_returned');
            }
        }));
    }

    /** The assigned engineer / technician accepts the ticket and starts investigating. */
    public function startMobilityWork(Outage $outage, User $by, ?string $note = null): Outage
    {
        $result = DB::transaction(function () use ($outage, $by, $note): Outage {
            $outage = $this->lock($outage);
            Gate::forUser($by)->authorize('mobilityStart', $outage);
            $this->fifo->authorizeWork($outage, QueueType::MobilityEngineer, $by);

            $outage->forceFill(['work_started_at' => $outage->work_started_at ?? now(), 'acknowledged_at' => $outage->acknowledged_at ?? now()]);
            $this->transition($outage, StatusCode::MobilityInProgress, $by, $note ?: 'Investigation started');
            $this->addMobilityUpdate($outage, 'Accepted the ticket and started the investigation.'.($note ? " {$note}" : ''), $by, action: 'investigation_started');

            return $outage->refresh();
        });

        return $result->refresh();
    }

    /**
     * Engineer / technician: the fault is fixed. The ticket goes back to the NOC as
     * "Resolved – Pending NOC Confirmation"; only the NOC closes it.
     *
     * @param  array{root_cause: string, resolution: string, restored_at?: mixed}  $data
     */
    public function markMobilityResolved(Outage $outage, User $by, array $data): Outage
    {
        $this->requireText($data['root_cause'] ?? null, 'root_cause', 'Record the root cause.');
        $this->requireText($data['resolution'] ?? null, 'resolution', 'Describe the resolution / work done.');
        $restoredAt = $this->pastMoment($data['restored_at'] ?? null, 'restored_at');

        $result = DB::transaction(function () use ($outage, $by, $data, $restoredAt): Outage {
            $outage = $this->lock($outage);
            Gate::forUser($by)->authorize('mobilityResolve', $outage);
            $this->fifo->authorizeWork($outage, QueueType::MobilityEngineer, $by);

            $outage->forceFill([
                'root_cause' => trim($data['root_cause']),
                'resolution' => trim($data['resolution']),
                'restored_at' => $restoredAt,
                'restored_by' => $by->id,
                'acknowledged_at' => $outage->acknowledged_at ?? now(),
                'work_started_at' => $outage->work_started_at ?? now(),
                'latest_update' => trim($data['resolution']),
            ]);

            $this->fifo->complete($outage, QueueType::MobilityEngineer, $by, 'Resolved – pending NOC confirmation');
            $this->transition($outage, StatusCode::MobilityResolvedPendingNoc, $by, trim($data['resolution']));
            $this->addMobilityUpdate($outage, "Marked resolved — pending NOC confirmation. Root cause: {$outage->root_cause} Resolution: {$outage->resolution}", $by, 'restoration', action: 'resolved_pending_noc');

            $this->fifo->enqueue($outage, QueueType::MobilityNoc, ['reason' => 'Resolved by '.$by->name.' — NOC to verify']);
            $this->notifier->dispatch(NotificationEvent::MobilityResolvedPending, $outage, ['reason' => $outage->resolution, 'notify_user_ids' => [$outage->created_by]]);

            return $outage->refresh();
        });

        return $result->refresh();
    }

    // ------------------------------------------------------------------ NOC verification / closure

    /**
     * NOC confirms the service is back and closes the ticket.
     *
     * @param  array{root_cause?: ?string, resolution?: ?string, note?: ?string}  $data
     */
    public function closeMobility(Outage $outage, User $by, array $data = []): Outage
    {
        $result = DB::transaction(function () use ($outage, $by, $data): Outage {
            $outage = $this->lock($outage);
            Gate::forUser($by)->authorize('mobilityClose', $outage);

            // What the engineer / NOC already recorded stands unless the NOC rewrites it now.
            $rootCause = trim((string) ($data['root_cause'] ?? $outage->root_cause));
            $resolution = trim((string) ($data['resolution'] ?? $outage->resolution));
            $this->requireText($rootCause, 'root_cause', 'A root cause is required to close the ticket.');
            $this->requireText($resolution, 'resolution', 'A resolution is required to close the ticket.');
            $note = trim((string) ($data['note'] ?? ''));

            $outage->forceFill([
                'root_cause' => $rootCause,
                'resolution' => $resolution,
                'final_reason' => "Root cause: {$rootCause}\nResolution: {$resolution}",
                'restore_confirmed_at' => now(),
                'restore_confirmed_by' => $by->id,
                'resolved_at' => now(),
                'resolved_by' => $by->id,
                'end_time' => $outage->restored_at ?? now(),
            ]);

            $this->fifo->complete($outage, QueueType::MobilityNoc, $by, 'Verified and closed');
            $this->transition($outage, StatusCode::MobilityClosed, $by, $note ?: 'Restoration verified by NOC');
            $this->addMobilityUpdate($outage, 'NOC verified the restoration and closed the ticket.'.($note ? " {$note}" : ''), $by, action: 'closed');
            $this->notifier->dispatch(NotificationEvent::Resolved, $outage, ['reason' => $note ?: null, 'notify_user_ids' => [$outage->technician_id, $outage->created_by]]);

            if ($outage->isVendorTicket()) {
                $this->sendVendorMail($outage, VendorTemplate::CLOSED, $by);
            }

            return $outage->refresh();
        });

        return $result->refresh();
    }

    /** NOC checked and the service is still down: back to the engineer / vendor. */
    public function rejectMobilityResolution(Outage $outage, User $by, string $reason): Outage
    {
        $this->requireText($reason, 'reason', 'Explain what is still failing.');

        $result = DB::transaction(function () use ($outage, $by, $reason): Outage {
            $outage = $this->lock($outage);
            Gate::forUser($by)->authorize('mobilityClose', $outage);
            $this->fifo->complete($outage, QueueType::MobilityNoc, $by, 'Restoration not verified');

            // Saved now: the FIFO engine below works on its own copy of the ticket.
            $outage->forceFill(['restored_at' => null, 'restored_by' => null, 'vendor_resolved_at' => null])->save();

            if ($outage->isVendorTicket()) {
                // The silence timer restarts: the NOC goes back to the vendor now.
                $outage->forceFill(['vendor_last_update_at' => now(), 'vendor_next_update_at' => null, 'vendor_escalation_level' => 0, 'vendor_escalated_at' => null]);
                $this->transition($outage, StatusCode::MobilityVendorInvestigating, $by, $reason);
            } elseif ($engineer = User::query()->active()->find($outage->technician_id)) {
                $entry = $this->fifo->enqueue($outage, QueueType::MobilityEngineer, ['reason' => 'NOC could not verify the fix']);
                $this->fifo->assign($entry, $engineer, AssignmentType::Dispatch, $by, 'NOC could not verify the fix: '.$reason);
                $outage->refresh();
                $this->transition($outage, StatusCode::MobilityInProgress, $by, $reason);
            } else {
                // The engineer who worked it is gone: back to the FIFO queue for the next one.
                $this->transition($outage, StatusCode::MobilityOpen, $by, $reason);
                $this->queueForEngineer($outage, $by, $outage->team_id);
            }

            $this->addMobilityUpdate($outage, "NOC could not verify the restoration: {$reason}", $by, action: 'verification_failed');
            $this->notifier->dispatch(NotificationEvent::MobilityVerificationFailed, $outage, ['reason' => $reason, 'notify_user_ids' => [$outage->technician_id]]);

            return $outage->refresh();
        });

        return $result->refresh();
    }

    /** Open / In Progress / Monitoring (internal) or Open / Vendor Acknowledged / Vendor Investigating / Monitoring (vendor). */
    public function setMobilityStatus(Outage $outage, StatusCode $to, User $by, ?string $note = null): Outage
    {
        $result = DB::transaction(function () use ($outage, $to, $by, $note): Outage {
            $outage = $this->lock($outage);
            Gate::forUser($by)->authorize('mobilitySetStatus', $outage);

            if (! in_array($to, self::MOBILITY_MANUAL_STATUSES[$outage->workflow] ?? [], true)) {
                throw ValidationException::withMessages(['status' => 'This status is set by the workflow.']);
            }

            if (! $by->can('mobility.verify')) {
                // An engineer only moves a ticket of their own between In Progress and Monitoring.
                if ($outage->isVendorTicket() || ! in_array($to, [StatusCode::MobilityInProgress, StatusCode::MobilityMonitoring], true)) {
                    throw ValidationException::withMessages(['status' => 'Only the NOC can set this status.']);
                }

                $this->fifo->authorizeWork($outage, QueueType::MobilityEngineer, $by);
            }

            if ($this->statusIs($outage, $to)) {
                return $outage;
            }

            if ($to === StatusCode::MobilityOpen && ! $outage->isVendorTicket()) {
                // Back in the NOC's hands: the engineer is released.
                $this->fifo->complete($outage, QueueType::MobilityEngineer, $by, 'Returned to the NOC');
            }

            if ($to === StatusCode::MobilityVendorAcknowledged) {
                $outage->forceFill(['vendor_acknowledged_at' => $outage->vendor_acknowledged_at ?? now()]);
            }

            if ($to !== StatusCode::MobilityOpen) {
                $outage->forceFill(['acknowledged_at' => $outage->acknowledged_at ?? now()]);
            }

            if ($to === StatusCode::MobilityInProgress) {
                $outage->forceFill(['work_started_at' => $outage->work_started_at ?? now()]);
            }

            $this->transition($outage, $to, $by, $note);
            $this->addMobilityUpdate($outage, 'Status set to '.$outage->status->name.($note ? ": {$note}" : '.'), $by, action: 'status_set');

            return $outage->refresh();
        });

        return $result->refresh();
    }

    // ------------------------------------------------------------------ vendor tickets

    /**
     * NOC captures what the vendor reported by e-mail / phone / WhatsApp.
     *
     * @param  array{vendor_status: string, method: string, occurred_at?: mixed, contact_person?: ?string, eta_at?: mixed, next_update_at?: mixed, comments?: ?string, attachments?: array, attachment_names?: array}  $data
     */
    public function recordVendorUpdate(Outage $outage, User $by, array $data): VendorUpdate
    {
        if (! array_key_exists($data['vendor_status'] ?? '', VendorUpdate::STATUSES)) {
            throw ValidationException::withMessages(['vendor_status' => 'Select the vendor status.']);
        }

        if (! array_key_exists($data['method'] ?? '', VendorUpdate::METHODS)) {
            throw ValidationException::withMessages(['method' => 'Select how the vendor communicated.']);
        }

        $occurredAt = $this->pastMoment($data['occurred_at'] ?? null, 'occurred_at');

        return DB::transaction(function () use ($outage, $by, $data, $occurredAt): VendorUpdate {
            $outage = $this->lock($outage);
            Gate::forUser($by)->authorize('mobilityVendorUpdate', $outage);
            $outage->loadMissing('vendor');

            $update = VendorUpdate::query()->create([
                'outage_id' => $outage->id,
                'vendor_id' => $outage->vendor_id,
                'user_id' => $by->id,
                'occurred_at' => $occurredAt,
                'contact_person' => filled($data['contact_person'] ?? null) ? trim($data['contact_person']) : $outage->vendor?->contact_person,
                'method' => $data['method'],
                'vendor_status' => $data['vendor_status'],
                'eta_at' => filled($data['eta_at'] ?? null) ? Carbon::parse($data['eta_at']) : null,
                'next_update_at' => filled($data['next_update_at'] ?? null) ? Carbon::parse($data['next_update_at']) : null,
                'comments' => filled($data['comments'] ?? null) ? trim($data['comments']) : null,
            ]);

            $files = $this->storeMobilityAttachments($outage, $by, (array) ($data['attachments'] ?? []), (array) ($data['attachment_names'] ?? []), null, $update->id);

            $summary = collect([
                "Vendor update from {$outage->vendor?->name} ({$update->methodLabel()}".($update->contact_person ? ", {$update->contact_person}" : '').'): '.$update->statusLabel().'.',
                $update->eta_at ? 'ETA '.$update->eta_at->format('d M H:i').'.' : null,
                $update->next_update_at ? 'Next update '.$update->next_update_at->format('d M H:i').'.' : null,
                $update->comments,
                $files->isNotEmpty() ? 'Attached: '.$files->map->name()->implode(', ').'.' : null,
            ])->filter()->implode(' ');

            $outage->forceFill([
                'vendor_last_update_at' => now(),
                'vendor_next_update_at' => $update->next_update_at,
                'vendor_eta_at' => $update->eta_at ?? $outage->vendor_eta_at,
                'vendor_acknowledged_at' => $outage->vendor_acknowledged_at ?? $occurredAt,
                'acknowledged_at' => $outage->acknowledged_at ?? $occurredAt,
                'vendor_escalation_level' => 0,
                'vendor_escalated_at' => null,
                'latest_update' => $summary,
            ])->save();

            $this->addMobilityUpdate($outage, $summary, $by, 'vendor', action: 'vendor_update');
            $pending = $this->statusIs($outage, StatusCode::MobilityVendorResolvedPendingNoc);

            if ($update->reportsResolved()) {
                // Step 8: the vendor reports restoration — the NOC must now verify the site.
                $outage->forceFill(['restored_at' => $occurredAt, 'restored_by' => $by->id, 'vendor_resolved_at' => $occurredAt]);

                if (! $pending) {
                    $this->transition($outage, StatusCode::MobilityVendorResolvedPendingNoc, $by, $update->statusLabel());
                    $this->fifo->enqueue($outage, QueueType::MobilityNoc, ['reason' => 'Vendor reports restoration — NOC to verify']);
                    $this->notifier->dispatch(NotificationEvent::MobilityResolvedPending, $outage, ['reason' => $summary]);
                } else {
                    $outage->save();
                }
            } else {
                $target = match ($update->vendor_status) {
                    'acknowledged' => StatusCode::MobilityVendorAcknowledged,
                    'monitoring' => StatusCode::MobilityMonitoring,
                    default => StatusCode::MobilityVendorInvestigating,
                };

                // An acknowledgement never moves a ticket backwards from "investigating".
                if ($target === StatusCode::MobilityVendorAcknowledged && $this->statusIs($outage, StatusCode::MobilityVendorInvestigating, StatusCode::MobilityMonitoring)) {
                    $target = null;
                }

                if ($pending) {
                    // The vendor took the "resolved" back: no longer waiting for NOC verification.
                    $this->fifo->complete($outage, QueueType::MobilityNoc, $by, 'Vendor reports the fault is not resolved');
                    $outage->forceFill(['restored_at' => null, 'restored_by' => null, 'vendor_resolved_at' => null]);
                    $target ??= StatusCode::MobilityVendorInvestigating;
                }

                if ($target && ! $this->statusIs($outage, $target)) {
                    $this->transition($outage, $target, $by, $update->statusLabel());
                }
            }

            $this->notifier->dispatch(NotificationEvent::MobilityVendorUpdate, $outage, ['reason' => $summary]);
            event(new OutageUpdated($outage->id, $outage->ticket_number));

            return $update;
        });
    }

    /** NOC assigns a different vendor to an open ticket; the new vendor is e-mailed. */
    public function changeMobilityVendor(Outage $outage, User $by, int $vendorId, string $reason): Outage
    {
        $this->requireText($reason, 'reason', 'Say why the vendor is changed.');

        $result = DB::transaction(function () use ($outage, $by, $vendorId, $reason): Outage {
            $outage = $this->lock($outage);
            Gate::forUser($by)->authorize('mobilityChangeVendor', $outage);

            $vendor = Vendor::query()->active()->find($vendorId)
                ?? throw ValidationException::withMessages(['vendor_id' => 'Select an active vendor.']);

            if ($vendor->id === $outage->vendor_id) {
                throw ValidationException::withMessages(['vendor_id' => "{$vendor->name} is already the vendor of this ticket."]);
            }

            $this->assignVendorTo($outage, $vendor, $by, VendorTemplate::VENDOR_CHANGED, $reason);
            $this->sla->apply($outage->refresh(), 'recalculated');

            return $outage->refresh();
        });

        return $result->refresh();
    }

    /** NOC sends the vendor notification again (new contact, first e-mail failed, reminder…). */
    public function resendVendorNotification(Outage $outage, User $by, ?string $note = null): Outage
    {
        $result = DB::transaction(function () use ($outage, $by, $note): Outage {
            $outage = $this->lock($outage);
            Gate::forUser($by)->authorize('mobilityNotifyVendor', $outage);

            $this->sendVendorMail($outage, VendorTemplate::MANUAL, $by, ['reason' => (string) $note],
                advance: $this->statusIs($outage, StatusCode::MobilityVendorAssigned), fallback: VendorTemplate::TICKET_CREATED, announce: true);

            return $outage->refresh();
        });

        return $result->refresh();
    }

    /** Called by the mail job once a vendor e-mail has left: timeline entry + "Vendor Notification Sent". */
    public function vendorMailDelivered(NotificationLog $log): void
    {
        if (! $log->outage_id) {
            return;
        }

        AuditContext::as('system:vendor-mail', fn () => DB::transaction(function () use ($log): void {
            $outage = Outage::query()->with(['status', 'priority'])->lockForUpdate()->find($log->outage_id);

            if (! $outage) {
                return;
            }

            $to = implode(', ', (array) ($log->meta['to'] ?? [$log->recipient]));
            $cc = implode(', ', (array) ($log->meta['cc'] ?? []));
            $this->addMobilityUpdate($outage, "Vendor notification e-mail sent to {$to}".($cc ? " (CC {$cc})" : '').": “{$log->subject}”.", null, action: 'vendor_email_sent');

            $outage->forceFill(['vendor_notified_at' => $outage->vendor_notified_at ?? now()]);

            if (! empty($log->meta['advance']) && ! $outage->is_closed && $this->statusIs($outage, StatusCode::MobilityVendorAssigned)
                && (int) $outage->vendor_id === (int) ($log->meta['vendor_id'] ?? 0)) {
                $this->transition($outage, StatusCode::MobilityVendorNotified, null, 'Vendor notification e-mail sent');
            } else {
                $outage->save();
            }

            $this->notifier->dispatch(NotificationEvent::MobilityVendorNotified, $outage, ['reason' => "E-mail sent to {$to}", 'by' => 'System']);
        }));
    }

    /** Called by the mail job when a vendor e-mail finally failed: the NOC must reach the vendor another way. */
    public function vendorMailFailed(NotificationLog $log, ?string $error = null): void
    {
        if (! $log->outage_id) {
            return;
        }

        AuditContext::as('system:vendor-mail', fn () => DB::transaction(function () use ($log, $error): void {
            $outage = Outage::query()->with(['status', 'priority'])->find($log->outage_id);

            if (! $outage) {
                return;
            }

            $reason = Str::limit((string) ($error ?: $log->failure_reason ?: 'unknown error'), 300);
            $this->addMobilityUpdate($outage, "Vendor notification e-mail to {$log->recipient} FAILED: {$reason} Contact the vendor directly, then use “Send vendor notification” to retry.", null, action: 'vendor_email_failed');
            $this->notifier->dispatch(NotificationEvent::MobilityVendorMailFailed, $outage, ['reason' => $reason, 'by' => 'System', 'notify_user_ids' => [$outage->created_by]]);
        }));
    }

    // ------------------------------------------------------------------ reopen / cancel / escalate / attachments

    public function reopenMobility(Outage $outage, User $by, string $reason): Outage
    {
        $this->requireText($reason, 'reason', 'A reason is required to reopen a ticket.');

        $result = DB::transaction(function () use ($outage, $by, $reason): Outage {
            $outage = $this->lock($outage);
            Gate::forUser($by)->authorize('mobilityReopen', $outage);

            // Keep the closure that is being undone.
            OutageReopening::query()->create([
                'outage_id' => $outage->id,
                'user_id' => $by->id,
                'reason' => trim($reason),
                'previous_status_id' => $outage->status_id,
                'previous_closed_at' => $outage->resolved_at ?? $outage->cancelled_at,
                'previous_closed_by' => $outage->resolved_by ?? $outage->cancelled_by,
                'previous_end_time' => $outage->end_time,
                'previous_sla_state' => $outage->sla_state?->value,
                'previous_root_cause' => $outage->root_cause,
                'previous_resolution' => $outage->resolution,
            ]);

            $previous = $outage->status?->name;

            $outage->forceFill([
                'reopened_at' => now(),
                'reopen_count' => $outage->reopen_count + 1,
                'end_time' => null,
                'resolved_at' => null, 'resolved_by' => null,
                'restore_confirmed_at' => null, 'restore_confirmed_by' => null,
                'restored_at' => null, 'restored_by' => null,
                'vendor_resolved_at' => null, 'vendor_next_update_at' => null,
                'vendor_escalation_level' => 0, 'vendor_escalated_at' => null,
                'cancelled_at' => null, 'cancelled_by' => null,
                'final_reason' => null,
            ]);

            $this->transition($outage, StatusCode::MobilityOpen, $by, $reason);
            // The outage never really ended: the SLA clock continues from the original start.
            $this->sla->apply($outage, 'reopened');
            $this->addMobilityUpdate($outage, "Ticket reopened (was {$previous}): {$reason}", $by, action: 'reopened');
            $this->notifier->dispatch(NotificationEvent::MobilityReopened, $outage, ['reason' => $reason, 'notify_user_ids' => [$outage->technician_id]]);

            if ($outage->isVendorTicket()) {
                $this->sendVendorMail($outage, VendorTemplate::REOPENED, $by, ['reason' => $reason]);
            } else {
                // Back into the workflow: the next available engineer / technician picks it up.
                $this->queueForEngineer($outage, $by, $outage->team_id);
            }

            return $outage->refresh();
        });

        return $result->refresh();
    }

    public function cancelMobility(Outage $outage, User $by, string $reason): Outage
    {
        $this->requireText($reason, 'reason', 'A reason is required to cancel a ticket.');

        $result = DB::transaction(function () use ($outage, $by, $reason): Outage {
            $outage = $this->lock($outage);
            Gate::forUser($by)->authorize('mobilityCancel', $outage);

            $outage->forceFill([
                'cancelled_at' => now(),
                'cancelled_by' => $by->id,
                'end_time' => now(),
                'final_reason' => 'Cancelled: '.trim($reason),
            ]);

            $this->transition($outage, StatusCode::MobilityCancelled, $by, $reason);
            $this->addMobilityUpdate($outage, "Ticket cancelled: {$reason}", $by, action: 'cancelled');
            $this->notifier->dispatch(NotificationEvent::MobilityCancelled, $outage, ['reason' => $reason, 'notify_user_ids' => [$outage->technician_id]]);

            if ($outage->isVendorTicket() && $outage->vendor_notified_at) {
                $this->sendVendorMail($outage, VendorTemplate::CANCELLED, $by, ['reason' => $reason]);
            }

            return $outage->refresh();
        });

        return $result->refresh();
    }

    /** NOC escalates a ticket to management (roles / addresses set in 4G Settings, plus the notification rules). */
    public function escalateMobility(Outage $outage, User $by, string $reason): Outage
    {
        $this->requireText($reason, 'reason', 'Say why the ticket is escalated.');

        $result = DB::transaction(function () use ($outage, $by, $reason): Outage {
            $outage = $this->lock($outage);
            Gate::forUser($by)->authorize('mobilityEscalate', $outage);

            $outage->forceFill(['escalated_at' => now(), 'escalated_by' => $by->id])->save();
            $this->addMobilityUpdate($outage, "Escalated by NOC: {$reason}", $by, 'escalation', action: 'escalated');

            $settings = app(Settings::class);
            $this->notifier->notifyTargets(NotificationEvent::MobilityEscalated, $outage, [
                'roles' => array_values(array_filter((array) $settings->get('mobility.escalation_roles'))),
                'emails' => (array) $settings->get('mobility.escalation_emails'),
            ], [NotificationChannel::InApp->value, NotificationChannel::Mail->value], ['reason' => $reason]);
            $this->notifier->dispatch(NotificationEvent::MobilityEscalated, $outage, ['reason' => $reason]);
            event(new OutageUpdated($outage->id, $outage->ticket_number));

            return $outage->refresh();
        });

        return $result->refresh();
    }

    /**
     * @param  array<int, string>  $paths  files already uploaded under outage-attachments/ on the private disk
     * @param  array<string, string>  $names  path => original file name
     * @return Collection<int, OutageAttachment>
     */
    public function addMobilityAttachments(Outage $outage, User $by, array $paths, array $names = [], ?string $note = null): Collection
    {
        return DB::transaction(function () use ($outage, $by, $paths, $names, $note): Collection {
            Gate::forUser($by)->authorize('mobilityAttach', $outage);
            $files = $this->storeMobilityAttachments($outage, $by, $paths, $names, $note);

            if ($files->isEmpty()) {
                throw ValidationException::withMessages(['attachments' => 'Add at least one file.']);
            }

            $this->addMobilityUpdate($outage, 'Attachment'.($files->count() === 1 ? '' : 's').' added: '.$files->map->name()->implode(', ').($note ? " — {$note}" : '.'), $by, 'attachment', action: 'attachment_added');
            event(new OutageUpdated($outage->id, $outage->ticket_number));

            return $files;
        });
    }

    // ------------------------------------------------------------------ internals

    private function assignEngineerTo(Outage $outage, User $engineer, User $by, ?string $note): void
    {
        if ($outage->isVendorTicket()) {
            throw ValidationException::withMessages(['engineer_id' => 'This outage type is handled by a vendor, not an engineer.']);
        }

        if (! $engineer->is_active || ! $engineer->can(QueueType::MobilityEngineer->defaultPermission())) {
            throw ValidationException::withMessages(['engineer_id' => "{$engineer->name} cannot receive 4G tickets (inactive, or not a 4G engineer / technician)."]);
        }

        // Transmission outages are worked by the 4G engineers, eNodeB outages by the 4G technicians.
        if (($handles = OutageType::handlerPermission($outage->fault_domain)) && ! $engineer->checkPermissionTo($handles)) {
            throw ValidationException::withMessages(['engineer_id' => "{$engineer->name} does not handle {$outage->domainLabel()} outages: these go to a {$this->mobilityHandler($outage)}."]);
        }

        // Re-assignment: the previous engineer's item is released first.
        $this->fifo->complete($outage, QueueType::MobilityEngineer, $by, 'Reassigned to '.$engineer->name);

        $entry = $this->fifo->enqueue($outage, QueueType::MobilityEngineer, ['reason' => 'Assigned by '.$by->name]);
        $this->fifo->assign($entry, $engineer, AssignmentType::Dispatch, $by, $note ?: 'Assigned by 4G NOC');

        $outage->refresh();
        $outage->forceFill(['technician_id' => $engineer->id, 'acknowledged_at' => $outage->acknowledged_at ?? now()]);
        $this->transition($outage, StatusCode::MobilityAssigned, $by, $note ?: "Assigned to {$engineer->name}");
        $this->addMobilityUpdate($outage, "Assigned to {$engineer->name}.".($note ? " {$note}" : ''), $by, action: 'engineer_assigned');
    }

    /** Who works this ticket: "4G engineer" (transmission), "4G technician" (eNodeB), else "engineer / technician". */
    private function mobilityHandler(Outage $outage): string
    {
        return OutageType::handlerLabel($outage->fault_domain);
    }

    /**
     * Put the ticket in the 4G engineer / technician FIFO queue: the next available person who
     * handles this kind of outage gets it — a 4G engineer for transmission, a 4G technician for
     * eNodeB — and the one after, if it is rejected or not accepted in time. Anyone currently on it
     * is released first.
     */
    private function queueForEngineer(Outage $outage, User $by, ?int $teamId, ?string $note = null): void
    {
        if ($outage->isVendorTicket()) {
            throw ValidationException::withMessages(['engineer_id' => 'This outage type is handled by a vendor, not an engineer.']);
        }

        $who = $this->mobilityHandler($outage);
        $this->fifo->complete($outage, QueueType::MobilityEngineer, $by, 'Returned to the 4G queue');

        // Nobody is on it until FIFO assigns; a team, when given, limits who it is offered to.
        $outage->forceFill(['technician_id' => null, 'team_id' => $teamId ?? $outage->team_id])->save();

        $this->fifo->enqueue($outage, QueueType::MobilityEngineer, ['team_id' => $teamId, 'reason' => "Placed in the 4G queue by {$by->name} for the next available {$who}"]);
        $outage->refresh();

        // Someone was working it: it is open again until FIFO hands it to the next person.
        if ($this->statusIs($outage, StatusCode::MobilityAssigned, StatusCode::MobilityInProgress, StatusCode::MobilityMonitoring)) {
            $this->transition($outage, StatusCode::MobilityOpen, $by, $note ?: 'Returned to the 4G queue');
        }

        $team = $teamId ? Team::query()->whereKey($teamId)->value('name') : null;
        $this->addMobilityUpdate($outage, 'Placed in the 4G queue (FIFO)'.($team ? " for team {$team}" : '').": the next available {$who} is assigned automatically.".($note ? " {$note}" : ''), $by, action: 'queued_for_engineer');
    }

    /** A team may limit who FIFO offers the ticket to; only an active team counts. */
    private function mobilityTeamId(mixed $teamId): ?int
    {
        return $teamId && Team::query()->active()->whereKey($teamId)->exists() ? (int) $teamId : null;
    }

    private function assignVendorTo(Outage $outage, Vendor $vendor, User $by, string $trigger, ?string $reason = null): void
    {
        $previous = $outage->vendor_id && $outage->vendor_id !== $vendor->id ? Vendor::query()->find($outage->vendor_id)?->name : null;

        $outage->forceFill([
            'vendor_id' => $vendor->id,
            'vendor_notified_at' => null, 'vendor_acknowledged_at' => null, 'vendor_last_update_at' => null,
            'vendor_next_update_at' => null, 'vendor_eta_at' => null, 'vendor_escalation_level' => 0, 'vendor_escalated_at' => null,
            'acknowledged_at' => $outage->acknowledged_at ?? now(),
        ]);
        $outage->setRelation('vendor', $vendor);

        $this->transition($outage, StatusCode::MobilityVendorAssigned, $by, $reason ?: "Vendor {$vendor->name} assigned");
        $this->addMobilityUpdate($outage, $previous
            ? "Vendor changed from {$previous} to {$vendor->name}: {$reason}"
            : "Vendor {$vendor->name} assigned.", $by, action: $previous ? 'vendor_changed' : 'vendor_assigned');

        $this->sendVendorMail($outage, $trigger, $by, ['reason' => (string) $reason], advance: true, fallback: VendorTemplate::TICKET_CREATED, announce: true);
    }

    /**
     * Queue the vendor e-mail(s) of a trigger. With $announce, a missing template / address is
     * written on the timeline so the NOC knows nothing was sent.
     */
    private function sendVendorMail(Outage $outage, string $trigger, ?User $by, array $context = [], bool $advance = false, ?string $fallback = null, bool $announce = false): void
    {
        $logs = app(VendorNotifier::class)->send($trigger, $outage, $context, $advance, $fallback);

        if ($logs->isEmpty()) {
            if ($announce) {
                $this->addMobilityUpdate($outage, 'No active vendor e-mail template for this step, so no e-mail was sent. Contact the vendor directly.', null, action: 'vendor_email_not_sent');
            }

            return;
        }

        // Could not even be queued (the vendor has no e-mail address).
        foreach ($logs->where('status', 'failed') as $log) {
            $this->addMobilityUpdate($outage, "Vendor notification NOT sent: {$log->failure_reason} Contact the vendor directly.", null, action: 'vendor_email_failed');
            $this->notifier->dispatch(NotificationEvent::MobilityVendorMailFailed, $outage, ['reason' => $log->failure_reason, 'by' => 'System']);
        }
    }

    /** eNodeB sites and transmission nodes of the ticket (inactive ones stay only if already attached). */
    private function syncMobilityElements(Outage $outage, array $data, bool $forNewOutage): void
    {
        $existing = [
            'enodebs' => $forNewOutage ? [] : $outage->enodebs()->pluck('enodebs.id')->all(),
            'nodes' => $forNewOutage ? [] : $outage->transmissionNodes()->pluck('transmission_nodes.id')->all(),
        ];

        $filter = fn (string $model, array $ids, array $keep) => ($ids = array_map('intval', array_filter($ids, fn ($id) => is_int($id) || ctype_digit((string) $id)))) === []
            ? []
            : $model::query()->whereIn('id', $ids)->where(fn ($q) => $q->where('is_active', true)->orWhereIn('id', $keep ?: [0]))->pluck('id')->all();

        $after = [
            'enodebs' => array_key_exists('enodeb_ids', $data) ? $filter(Enodeb::class, (array) $data['enodeb_ids'], $existing['enodebs']) : $existing['enodebs'],
            'nodes' => array_key_exists('node_ids', $data) ? $filter(TransmissionNode::class, (array) $data['node_ids'], $existing['nodes']) : $existing['nodes'],
        ];

        $outage->enodebs()->sync($after['enodebs']);
        $outage->transmissionNodes()->sync($after['nodes']);
        $outage->unsetRelation('enodebs')->unsetRelation('transmissionNodes');

        sort($existing['enodebs']);
        sort($existing['nodes']);
        sort($after['enodebs']);
        sort($after['nodes']);

        if (! $forNewOutage && $after !== $existing) {
            $outage->auditLogs()->create([
                'auditable_type' => $outage->getMorphClass(),
                'auditable_id' => $outage->id,
                'event' => 'topology_updated',
                'old_values' => $existing,
                'new_values' => $after,
                'user_id' => auth()->id(),
                'trigger' => AuditContext::trigger(),
                'created_at' => now(),
            ]);
        }
    }

    /** Elements affected → severity (unless the NOC set the priority by hand). */
    private function applyMobilityImpact(Outage $outage, array $data): void
    {
        $impact = app(MobilityImpact::class);
        $cells = (int) ($data['cells_down'] ?? $outage->cells_down);
        $customers = (int) ($data['customers_affected'] ?? $outage->customers_affected);

        $counts = $impact->counts(
            $outage->enodebs()->pluck('enodebs.id')->all(),
            $outage->transmissionNodes()->pluck('transmission_nodes.id')->all(),
            $cells,
            $customers,
        );

        $manual = array_key_exists('priority_is_manual', $data) ? ! empty($data['priority_is_manual']) : (bool) $outage->priority_is_manual;
        $manualId = (int) ($data['priority_id'] ?? $outage->priority_id);

        if ($manual && $manualId && Priority::query()->active()->whereKey($manualId)->where('vertical_id', $outage->vertical_id)->exists()) {
            $outage->priority_is_manual = true;
            $outage->priority_id = $manualId;
        } else {
            $outage->priority_is_manual = false;
            $outage->priority_id = $impact->severity((int) $outage->vertical_id, (int) $outage->outage_type_id, $counts)['priority']?->id;
        }

        $outage->forceFill([
            'impact_counts' => $counts,
            'customers_affected' => $customers,
            'cells_down' => $cells,
            // Feed the existing 4G figures of the dashboards and scheduled e-mails.
            'sites_down' => $counts['enodebs'],
            'power_faults' => $outage->fault_domain === OutageType::DOMAIN_POWER ? 1 : 0,
        ])->save();
    }

    /**
     * @param  array<int, string>  $paths
     * @param  array<string, string>  $names
     * @return Collection<int, OutageAttachment>
     */
    private function storeMobilityAttachments(Outage $outage, User $by, array $paths, array $names = [], ?string $note = null, ?int $vendorUpdateId = null): Collection
    {
        return collect($paths)
            ->filter(fn ($path) => is_string($path) && Str::startsWith($path, OutageAttachment::DIRECTORY.'/') && ! str_contains($path, '..')
                && Storage::disk('local')->exists($path))
            ->values()
            ->map(fn (string $path) => OutageAttachment::query()->create([
                'outage_id' => $outage->id,
                'vendor_update_id' => $vendorUpdateId,
                'path' => $path,
                'original_name' => $names[$path] ?? null,
                'note' => $note,
                'user_id' => $by->id,
            ]));
    }

    /**
     * One entry of the ticket timeline, tagged with who it came from (System / NOC / Engineer /
     * Technician) — and the same action in the audit trail (who, action, status, when, comment), so
     * nothing that happens to a 4G ticket goes unrecorded.
     */
    private function addMobilityUpdate(Outage $outage, string $body, ?User $by, string $type = 'system', string $action = 'note'): void
    {
        $actor = OutageUpdate::actorFor($by);

        OutageUpdate::query()->create([
            'outage_id' => $outage->id,
            'user_id' => $by?->id,
            'type' => $type,
            'actor_type' => $actor,
            'body' => $body,
            'is_system' => $type === 'system',
        ]);

        $outage->audit(AuditLog::MOBILITY_PREFIX.$action, [
            'ticket' => $outage->ticket_number,
            'status' => OutageStatus::query()->whereKey($outage->status_id)->value('name'),
            'by' => $by ? "{$by->name} ({$actor})" : $actor,
            'details' => $body,
        ], userId: $by?->id ?? false);
    }

    /** A moment that happened: now by default, never (meaningfully) in the future. */
    private function pastMoment(mixed $value, string $field): Carbon
    {
        $moment = filled($value) ? Carbon::parse($value) : now();

        if ($moment->greaterThan(now()->addMinutes(5))) {
            throw ValidationException::withMessages([$field => 'This time cannot be in the future.']);
        }

        return $moment;
    }
}
