<?php

namespace App\Services\Outages;

use App\Enums\NotificationChannel;
use App\Enums\NotificationEvent;
use App\Enums\QueueType;
use App\Enums\SlaState;
use App\Enums\StatusCode;
use App\Events\OutageCreated;
use App\Events\OutageStatusChanged;
use App\Events\OutageUpdated;
use App\Models\AuditLog;
use App\Models\ChangeRequest;
use App\Models\Fdt;
use App\Models\Olt;
use App\Models\Outage;
use App\Models\OutageFlagEvent;
use App\Models\OutageFollowUp;
use App\Models\OutagePhoto;
use App\Models\OutageStatus;
use App\Models\OutageStatusHistory;
use App\Models\OutageType;
use App\Models\OutageUpdate;
use App\Models\Port;
use App\Models\Service;
use App\Models\Slot;
use App\Models\SupportRequest;
use App\Models\Team;
use App\Models\User;
use App\Services\Fifo\FifoAssignmentEngine;
use App\Services\Notifications\NotificationDispatcher;
use App\Services\Outages\Concerns\ManagesCustomFlows;
use App\Services\Outages\Concerns\ManagesFieldMaintenance;
use App\Services\Outages\Concerns\ManagesFourGTickets;
use App\Services\Outages\Concerns\ManagesMobilityOutages;
use App\Services\Outages\Concerns\ManagesPlannedMaintenance;
use App\Services\Outages\Concerns\ManagesRelocations;
use App\Services\Outages\Concerns\ManagesTickets;
use App\Services\Sla\DeskSla;
use App\Services\Sla\SlaService;
use App\Support\AuditContext;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * The outage lifecycle (spec §9 / §26 / §28):
 *
 *   REPORT → SUPPORT UNCONFIRMED → NOC FIFO QUEUE → NOC CONFIRM/REJECT → INFRASTRUCTURE FIFO QUEUE
 *   → DISPATCH → TECHNICIAN FIFO ASSIGNMENT → TECHNICIAN ACTION → NOC RESTORE CONFIRMATION
 *   → OUTAGE RESOLVED → FINAL REASON/RFO → SMS/CREDIT CONFIRMATION
 *
 * Every public method authorises the acting user through OutagePolicy, runs in a single
 * transaction and leaves a status history / audit trail. UI, API and integrations all call
 * this service, so no channel can bypass the controls.
 */
class OutageWorkflow
{
    use ManagesCustomFlows, ManagesFieldMaintenance, ManagesFourGTickets, ManagesMobilityOutages, ManagesPlannedMaintenance, ManagesRelocations, ManagesTickets;

    public function __construct(
        private readonly TicketNumberGenerator $tickets,
        private readonly PriorityResolver $priorities,
        private readonly SlaService $sla,
        private readonly FifoAssignmentEngine $fifo,
        private readonly NotificationDispatcher $notifier,
        private readonly DeskSla $deskSla,
    ) {}

    // ------------------------------------------------------------------ create / edit

    public function create(array $data, User $by): Outage
    {
        Gate::forUser($by)->authorize('create', Outage::class);

        $result = DB::transaction(function () use ($data, $by): Outage {
            $type = OutageType::query()->with('majorOutageType')->active()->findOrFail($data['outage_type_id']);
            $major = $type->majorOutageType;

            // 4G NOC tickets (eNodeB / transmission / power) have their own form, statuses and workflow.
            if ($type->isMobility()) {
                throw ValidationException::withMessages(['outage_type_id' => '4G outages are reported under “4G NOC → Report 4G outage”.']);
            }

            if ($type->isTicketing()) {
                throw ValidationException::withMessages(['outage_type_id' => 'Customer tickets and site visits are raised under “Customer Care”.']);
            }

            if ($type->workflow === OutageType::WORKFLOW_CUSTOM) {
                throw ValidationException::withMessages(['outage_type_id' => 'Tickets of custom workflows are raised under “Workflow Tickets”.']);
            }

            // Planned maintenance has its own request → CRF → sign-off flow.
            if ($type->is_planned) {
                throw ValidationException::withMessages(['outage_type_id' => 'Planned maintenance is raised with “Request planned maintenance”.']);
            }

            if ($type->maintenance_kind) {
                throw ValidationException::withMessages(['outage_type_id' => 'Preventive / corrective maintenance is raised by the technician with “Raise field maintenance”.']);
            }

            // System outages go to IT first; everything else starts unconfirmed for NOC.
            $outage = $this->newOutage($data, $by, $type, $major->requires_it_review ? StatusCode::ItReview : StatusCode::SupportUnconfirmed, 'Outage reported');

            // Worked by a field team: it should be with a technician within the "time to field" target.
            if (! $major->requires_it_review) {
                $this->deskSla->stampField($outage);
            }

            if ($major->requires_it_review) {
                // IT checks the system first and escalates to NOC only for core / network causes.
                // …unless the affected system has its own owners (Savbill → CRM Managers, CPaaS → CPaaS Manager).
                $route = $this->systemRoute($outage);
                $this->fifo->enqueue($outage, QueueType::ItReview, $route['options'] + ['reason' => $route['team']
                    ? "System outage of {$route['service']->name}: reviewed by {$route['team']->name}"
                    : 'System outage: IT reviews first']);

                if ($route['team']) {
                    $this->addSystemUpdate($outage, "Affected system {$route['service']->name}: routed to {$route['team']->name} for review.", null);
                    $this->notifier->notifyUsers(NotificationEvent::ItEscalation, $outage, $route['team']->members()->where('users.is_active', true)->get(), [NotificationChannel::InApp->value], [
                        'queue' => $route['team']->name, 'reason' => "System outage of {$route['service']->name}",
                    ]);
                }

                $this->notifier->dispatch(NotificationEvent::ItEscalation, $outage, array_filter(['queue' => $route['team']?->name]));
            } elseif ($major->requires_noc_confirmation) {
                // Step 2: automatic escalation / routing to the NOC FIFO queue.
                $outage->forceFill(['noc_escalated_at' => now()])->save();
                $this->fifo->enqueue($outage, QueueType::NocReview, ['reason' => 'Automatic NOC escalation on creation']);
                $this->notifier->dispatch(NotificationEvent::NocEscalation, $outage);
            } else {
                AuditContext::as('system:auto-confirm', fn () => $this->afterConfirmation($outage, null, 'Major outage type does not require NOC confirmation'));
            }

            return $outage->refresh();
        });

        // Re-read after commit: post-commit FIFO processing may have assigned the next owner.
        return $result->refresh();
    }

    /**
     * Ticket number, rule-driven priority, initial status, topology, services, SLA and the
     * "created" notification — shared by outage reports and planned maintenance requests.
     */
    private function newOutage(array $data, User $by, OutageType $type, StatusCode $initialCode, string $reason): Outage
    {
        $outage = new Outage(Arr::only($data, (new Outage)->getFillable()));
        $outage->outage_type_id = $type->id;
        $outage->major_outage_type_id = $type->major_outage_type_id;
        $outage->ticket_number = $this->tickets->next($type->prefix);
        $outage->customers_affected = (int) ($data['customers_affected'] ?? 0);
        $outage->start_time = Carbon::parse($data['start_time'] ?? now());
        $this->applyPriority($outage, $data, $by);

        $initial = OutageStatus::forCode($initialCode);
        $outage->status_id = $initial->id;
        $outage->is_closed = false;
        $outage->sla_state = SlaState::Compliant;
        $outage->created_by = $by->id;
        $outage->updated_by = $by->id;
        $outage->save();

        $this->syncTopology($outage, $data, forNewOutage: true);
        $this->syncServices($outage, $data['service_ids'] ?? [], forNewOutage: true);

        $this->recordHistory($outage, null, $initial, $reason, $by);
        $this->sla->apply($outage->refresh(), 'started');

        foreach (['credit_shared', 'sms_sent'] as $flag) {
            if (! empty($data[$flag])) {
                $this->setFlag($outage, $flag, true, $by, authorize: false);
            }
        }

        event(new OutageCreated($outage->id, $outage->ticket_number));
        $this->notifier->dispatch(NotificationEvent::OutageCreated, $outage);

        return $outage;
    }

    public function update(Outage $outage, array $data, User $by): Outage
    {
        $result = DB::transaction(function () use ($outage, $data, $by): Outage {
            $outage = $this->lock($outage);
            Gate::forUser($by)->authorize('update', $outage);
            $previousPriority = $outage->priority_id;

            $outage->fill(Arr::only($data, [
                'title', 'description', 'location', 'customers_affected', 'sites_down', 'cells_down',
                'power_faults', 'start_time', 'team_type', 'team_id', 'sub_team_id', 'vertical_id', 'priority_is_manual', 'priority_id',
            ]));
            $this->applyPriority($outage, $data, $by);
            $startChanged = $outage->isDirty('start_time');
            $outage->save();

            if (array_key_exists('olt_ids', $data)) {
                $this->syncTopology($outage, $data, forNewOutage: false);
            }

            if (array_key_exists('service_ids', $data)) {
                $this->syncServices($outage, $data['service_ids'] ?? [], forNewOutage: false);
            }

            if ($previousPriority !== $outage->priority_id) {
                $this->onPriorityChanged($outage);
            } elseif ($startChanged) {
                $this->sla->apply($outage, 'recalculated');
            }

            foreach (['credit_shared', 'sms_sent'] as $flag) {
                if (array_key_exists($flag, $data)) {
                    $this->setFlag($outage, $flag, (bool) $data[$flag], $by);
                }
            }

            event(new OutageUpdated($outage->id, $outage->ticket_number));

            return $outage->refresh();
        });

        // Re-read after commit: post-commit FIFO processing may have assigned the next owner.
        return $result->refresh();
    }

    // ------------------------------------------------------------------ NOC review

    public function confirmByNoc(Outage $outage, User $by, ?string $note = null): Outage
    {
        $result = DB::transaction(function () use ($outage, $by, $note): Outage {
            $outage = $this->lock($outage);
            Gate::forUser($by)->authorize('confirm', $outage);
            $this->fifo->authorizeWork($outage, QueueType::NocReview, $by);

            $outage->forceFill(['noc_confirmed_at' => now(), 'noc_confirmed_by' => $by->id]);
            $this->transition($outage, StatusCode::NocConfirmed, $by, $note ?: 'NOC confirmed outage');
            $this->fifo->complete($outage, QueueType::NocReview, $by, 'NOC confirmed');
            $this->addSystemUpdate($outage, 'NOC confirmed the outage.'.($note ? " {$note}" : ''), $by);
            $this->notifier->dispatch(NotificationEvent::NocConfirmed, $outage, ['reason' => $note]);

            $this->afterConfirmation($outage, $by, $note);

            return $outage->refresh();
        });

        // Re-read after commit: post-commit FIFO processing may have assigned the next owner.
        return $result->refresh();
    }

    public function rejectByNoc(Outage $outage, User $by, string $reason): Outage
    {
        $this->requireText($reason, 'reason', 'A rejection reason is mandatory.');

        $result = DB::transaction(function () use ($outage, $by, $reason): Outage {
            $outage = $this->lock($outage);
            Gate::forUser($by)->authorize('confirm', $outage);
            $this->fifo->authorizeWork($outage, QueueType::NocReview, $by);

            $outage->forceFill([
                'noc_rejected_at' => now(),
                'noc_rejected_by' => $by->id,
                'rejection_reason' => $reason,
                'final_reason' => $reason,
                'end_time' => now(),
            ]);
            $this->fifo->complete($outage, QueueType::NocReview, $by, 'NOC rejected');
            $this->deskSla->clearField($outage);

            // Not an outage: Customer Experience (Tier II) calls the customer back and raises a
            // site visit if needed. The ticket closes as "NOC Reject" once they record the follow-up.
            $this->transition($outage, StatusCode::CxFollowUp, $by, $reason);
            $this->addSystemUpdate($outage, "NOC rejected the outage: {$reason}. Returned to Customer Experience (Tier II) for a customer callback.", $by);
            $this->fifo->enqueue($outage, QueueType::CxFollowUp, ['reason' => 'NOC rejected — customer callback / site visit']);
            $this->notifier->dispatch(NotificationEvent::NocRejected, $outage, ['reason' => $reason, 'notify_user_ids' => [$outage->created_by]]);

            return $outage->refresh();
        });

        // Re-read after commit: post-commit FIFO processing may have assigned the next owner.
        return $result->refresh();
    }

    // ------------------------------------------------------------------ Infrastructure / dispatch

    /**
     * @param  array{team_type?: ?string, team_id?: ?int, sub_team_id?: ?int, technician_id?: ?int, note?: ?string}  $data
     */
    public function dispatch(Outage $outage, array $data, User $by): Outage
    {
        $result = DB::transaction(function () use ($outage, $data, $by): Outage {
            $outage = $this->lock($outage);
            Gate::forUser($by)->authorize('dispatch', $outage);
            $this->fifo->authorizeWork($outage, QueueType::Infrastructure, $by);
            $note = $data['note'] ?? null;

            $outage->forceFill([
                'team_type' => $data['team_type'] ?? $outage->team_type,
                'team_id' => $data['team_id'] ?? $outage->team_id,
                'sub_team_id' => $data['sub_team_id'] ?? null,
                'dispatched_at' => now(),
                'dispatched_by' => $by->id,
            ])->save();

            $this->fifo->complete($outage, QueueType::Infrastructure, $by, 'Dispatched');

            // Re-dispatch: close any open technician item before routing again.
            $this->fifo->complete($outage, QueueType::Technician, $by, 'Re-dispatched');

            if (! empty($data['technician_id'])) {
                $technician = User::query()->findOrFail($data['technician_id']);
                $this->fifo->dispatchToTechnician($outage, $technician, $by, $outage->team_id, $outage->sub_team_id, $note);
            } else {
                $this->fifo->enqueue($outage, QueueType::Technician, [
                    'team_id' => $outage->team_id,
                    'sub_team_id' => $outage->sub_team_id,
                    'reason' => 'Dispatched to technician FIFO queue',
                ]);
            }

            // The FIFO engine updated assignee/technician on its own instance; reload before snapshotting.
            $outage->refresh()->load(['team', 'subTeam', 'priority']);
            $this->transition($outage, StatusCode::TeamDispatched, $by, $note ?: 'Team dispatched');
            $this->addSystemUpdate($outage, 'Dispatched to '.collect([$outage->team?->name, $outage->subTeam?->name])->filter()->implode(' / ').($note ? ". {$note}" : ''), $by);
            $this->notifier->dispatch(NotificationEvent::Dispatched, $outage, ['reason' => $note]);

            return $outage->refresh();
        });

        // Re-read after commit: post-commit FIFO processing may have assigned the next owner.
        return $result->refresh();
    }

    // ------------------------------------------------------------------ technician / restoration

    public function addUpdate(Outage $outage, User $by, string $body, string $type = 'progress'): OutageUpdate
    {
        Gate::forUser($by)->authorize('addUpdate', $outage);
        $this->requireText($body, 'body', 'The update text is required.');

        return DB::transaction(function () use ($outage, $by, $body, $type): OutageUpdate {
            $update = OutageUpdate::query()->create([
                'outage_id' => $outage->id,
                'user_id' => $by->id,
                'type' => in_array($type, OutageUpdate::MANUAL_TYPES, true) ? $type : 'note',
                // 4G timeline shows whether the NOC, an engineer or a technician wrote it.
                'actor_type' => $outage->isMobility() ? OutageUpdate::actorFor($by) : null,
                'body' => $body,
                'is_system' => false,
            ]);

            $outage->forceFill(['latest_update' => $body])->save();

            if ($outage->isMobility()) {
                $outage->audit(AuditLog::MOBILITY_PREFIX.'update_posted', [
                    'ticket' => $outage->ticket_number, 'by' => "{$by->name} ({$update->actor_type})",
                    'type' => OutageUpdate::TYPES[$update->type] ?? $update->type, 'details' => $body,
                ], userId: $by->id);
            } elseif ($outage->isTicket()) {
                $outage->audit(AuditLog::TICKET_PREFIX.'update_posted', [
                    'ticket' => $outage->ticket_number, 'by' => $by->name,
                    'type' => OutageUpdate::TYPES[$update->type] ?? $update->type, 'details' => $body,
                ], userId: $by->id);
            }

            event(new OutageUpdated($outage->id, $outage->ticket_number));
            $this->notifier->dispatch(NotificationEvent::UpdatePosted, $outage, ['reason' => $body, 'by' => $by->name]);
            // 4G customer tickets: everyone involved is kept posted.
            $this->shareFourGProgress($outage, $body);

            return $update;
        });
    }

    /**
     * Site photos of an Emergency / Regular outage, added while the work is going on (the findings
     * are photographed before anything is touched). Any of the three; a photo taken again replaces
     * the earlier one.
     *
     * @param  array<string, mixed>  $photos  see {@see ManagesFieldMaintenance::storeOutagePhotos()}
     */
    public function addSitePhotos(Outage $outage, User $by, array $photos): Outage
    {
        return DB::transaction(function () use ($outage, $by, $photos): Outage {
            $outage = $this->lock($outage);
            Gate::forUser($by)->authorize('addSitePhotos', $outage);

            if ($outage->majorOutageType?->requires_infrastructure) {
                $this->fifo->authorizeWork($outage, QueueType::Technician, $by);
            }

            $stored = $this->storeOutagePhotos($outage, $by, $photos);

            if ($stored->isEmpty()) {
                throw ValidationException::withMessages(['photos' => 'Add at least one photo.']);
            }

            $left = $outage->missingSitePhotos();
            $this->addSystemUpdate(
                $outage,
                'Site photo added — '.$stored->map(fn (OutagePhoto $photo) => $photo->label())->implode(', ').'. '
                    .($left === [] ? 'All three site photos are on the ticket.' : 'Still to take: '.implode(', ', $left).'.'),
                $by,
            );
            event(new OutageUpdated($outage->id, $outage->ticket_number));

            return $outage->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $photos  field maintenance: the two "after" photos (outside, inside);
     *                                        Emergency / Regular outage: any of the three site photos not on the ticket yet
     */
    public function reportRestored(Outage $outage, User $by, string $note, ?string $restoredAt = null, array $photos = []): Outage
    {
        $this->requireText($note, 'note', 'Describe the restoration performed.');

        $result = DB::transaction(function () use ($outage, $by, $note, $restoredAt, $photos): Outage {
            $outage = $this->lock($outage);
            Gate::forUser($by)->authorize('reportRestored', $outage);

            // Customer uptime needs both the live line and the drop cable to the premises.
            if (SupportRequest::query()->open()->where('outage_id', $outage->id)->exists()) {
                throw ValidationException::withMessages(['note' => 'The support technician request (drop cable / customer premises) is still open. Wait for the support technician to complete it, or cancel the request, before reporting restoration.']);
            }

            if ($outage->majorOutageType?->requires_infrastructure) {
                $this->fifo->authorizeWork($outage, QueueType::Technician, $by);
            }

            // Preventive / corrective maintenance closes with two "after" photos (outside with the label, inside).
            if ($outage->maintenance_kind) {
                if (array_filter(array_intersect_key($photos, OutagePhoto::POSITIONS)) !== []) {
                    $this->storeSitePhotos($outage, $by, OutagePhoto::AFTER, $photos);
                }

                if ($this->maintenancePhotosRequired() && ! $this->hasAfterPhotos($outage)) {
                    throw ValidationException::withMessages(['photos' => 'Add the two “after” photos (outside the FAT / FDT with the label, and inside) showing the finished work.']);
                }
            }

            // Emergency / Regular outage: findings on ground, after restoration and the power readings.
            if ($outage->offersSitePhotos()) {
                $this->storeOutagePhotos($outage, $by, $photos);

                // Mandatory unless the photos were made optional in the settings.
                if ($outage->requiresSitePhotos() && ($missing = $outage->missingSitePhotos()) !== []) {
                    throw ValidationException::withMessages(['photos' => 'The outage is reported restored with three site photos. Still missing: '.implode(', ', $missing).'.']);
                }
            }

            $outage->forceFill([
                'restored_at' => $restoredAt ? Carbon::parse($restoredAt) : now(),
                'restored_by' => $by->id,
                'latest_update' => $note,
            ]);

            $this->fifo->complete($outage, QueueType::Technician, $by, 'Restoration reported');
            $this->transition($outage, StatusCode::RestoreReported, $by, $note);
            OutageUpdate::query()->create(['outage_id' => $outage->id, 'user_id' => $by->id, 'type' => 'restoration', 'body' => $note]);

            $this->fifo->enqueue($outage, QueueType::NocRestore, ['reason' => 'Awaiting NOC restore confirmation']);
            $this->notifier->dispatch(NotificationEvent::RestoreReported, $outage, ['reason' => $note]);

            return $outage->refresh();
        });

        // Re-read after commit: post-commit FIFO processing may have assigned the next owner.
        return $result->refresh();
    }

    /**
     * @param  array{final_status?: ?string, outcome_details?: ?string}  $maintenance  CRF outcome (planned maintenance only)
     */
    public function confirmRestoration(Outage $outage, User $by, ?string $note = null, array $maintenance = []): Outage
    {
        $changeRequest = ChangeRequest::query()->where('outage_id', $outage->id)->first();

        if ($changeRequest && ! array_key_exists($maintenance['final_status'] ?? '', ChangeRequest::FINAL_STATUSES)) {
            throw ValidationException::withMessages(['final_status' => 'Select the final status of the maintenance.']);
        }

        $result = DB::transaction(function () use ($outage, $by, $note, $changeRequest, $maintenance): Outage {
            $outage = $this->lock($outage);
            Gate::forUser($by)->authorize('confirmRestoration', $outage);
            $this->fifo->authorizeWork($outage, QueueType::NocRestore, $by);

            $outage->forceFill([
                'restore_confirmed_at' => now(),
                'restore_confirmed_by' => $by->id,
                'end_time' => $outage->restored_at ?? now(),
            ]);

            if ($changeRequest) {
                // Planned maintenance: all customers verified → CX tells them it is done.
                $this->maintenanceVerified($outage, $changeRequest, $by, $note, $maintenance);

                return $outage->refresh();
            }

            $this->fifo->complete($outage, QueueType::NocRestore, $by, 'NOC restore confirmed');
            $this->transition($outage, StatusCode::NocRestoreConfirmed, $by, $note ?: 'NOC confirmed restoration');
            $this->addSystemUpdate($outage, 'NOC confirmed restoration.'.($note ? " {$note}" : ''), $by);
            $this->notifier->dispatch(NotificationEvent::RestoreConfirmed, $outage, ['reason' => $note]);

            return $outage->refresh();
        });

        // Re-read after commit: post-commit FIFO processing may have assigned the next owner.
        return $result->refresh();
    }

    /** NOC cannot verify the restoration: send the ticket back to the field team. */
    public function rejectRestoration(Outage $outage, User $by, string $reason): Outage
    {
        $this->requireText($reason, 'reason', 'Explain why the restoration could not be confirmed.');

        $result = DB::transaction(function () use ($outage, $by, $reason): Outage {
            $outage = $this->lock($outage);
            Gate::forUser($by)->authorize('confirmRestoration', $outage);
            $this->fifo->authorizeWork($outage, QueueType::NocRestore, $by);

            $outage->forceFill(['restored_at' => null, 'restored_by' => null]);
            $this->fifo->complete($outage, QueueType::NocRestore, $by, 'Restoration not confirmed');

            if ($outage->majorOutageType?->requires_infrastructure) {
                $this->transition($outage, StatusCode::TeamDispatched, $by, $reason);
                $this->fifo->enqueue($outage, QueueType::Technician, [
                    'team_id' => $outage->team_id,
                    'sub_team_id' => $outage->sub_team_id,
                    'reason' => 'Restoration not confirmed by NOC',
                ]);
            } else {
                $this->transition($outage, StatusCode::NocConfirmed, $by, $reason);
            }

            $this->addSystemUpdate($outage, "NOC could not confirm restoration: {$reason}", $by);

            return $outage->refresh();
        });

        // Re-read after commit: post-commit FIFO processing may have assigned the next owner.
        return $result->refresh();
    }

    public function resolve(Outage $outage, User $by, ?string $finalReason): Outage
    {
        $status = OutageStatus::forCode(StatusCode::Resolved);

        if ($status->prompt_final_reason) {
            $this->requireText($finalReason, 'final_reason', 'A Final Reason / RFO is required to resolve this outage.');
        }

        $result = DB::transaction(function () use ($outage, $by, $finalReason, $status): Outage {
            $outage = $this->lock($outage);
            Gate::forUser($by)->authorize('resolve', $outage);

            $outage->forceFill([
                'final_reason' => $finalReason ?: $outage->final_reason,
                'resolved_at' => now(),
                'resolved_by' => $by->id,
                'end_time' => $outage->end_time ?? $outage->restored_at ?? now(),
            ]);

            $this->transition($outage, $status, $by, $finalReason ?: 'Outage resolved');
            $this->addSystemUpdate($outage, 'Outage resolved.'.($finalReason ? " RFO: {$finalReason}" : ''), $by);
            $this->notifier->dispatch(NotificationEvent::Resolved, $outage, ['reason' => $finalReason]);

            if (! $outage->credit_shared || ! $outage->sms_sent) {
                $this->notifier->dispatch(NotificationEvent::CreditDue, $outage, ['reason' => 'Resolved — confirm customer SMS / credit']);
            }

            return $outage->refresh();
        });

        // Re-read after commit: post-commit FIFO processing may have assigned the next owner.
        return $result->refresh();
    }

    /** Operational statuses (Partial Restore, Awaiting Field Access, Vendor Escalated, ...). */
    public function changeStatus(Outage $outage, OutageStatus $status, User $by, ?string $reason): Outage
    {
        if (! $status->is_active || ! $status->is_manual_selectable || $status->workflow !== null) {
            throw ValidationException::withMessages(['status_id' => 'This status can only be set by the workflow.']);
        }

        if ($status->prompt_final_reason) {
            $this->requireText($reason, 'reason', "The status \"{$status->name}\" requires a Final Reason.");
        }

        $result = DB::transaction(function () use ($outage, $status, $by, $reason): Outage {
            $outage = $this->lock($outage);
            Gate::forUser($by)->authorize('changeStatus', $outage);

            if ($status->prompt_final_reason) {
                $outage->final_reason = $reason;
            }

            if ($status->is_closed) {
                $outage->forceFill(['resolved_at' => now(), 'resolved_by' => $by->id, 'end_time' => $outage->end_time ?? now()]);
            }

            $this->transition($outage, $status, $by, $reason);

            if ($reason) {
                $this->addSystemUpdate($outage, "Status set to {$status->name}: {$reason}", $by);
            }

            return $outage->refresh();
        });

        // Re-read after commit: post-commit FIFO processing may have assigned the next owner.
        return $result->refresh();
    }

    // ------------------------------------------------------------------ NOC reject → CX follow-up

    /**
     * CX Tier II records the customer callback on a NOC-rejected ticket, and optionally the site
     * visit they raised. With $data['close'] (default) the ticket closes as "NOC Reject"; without
     * it the ticket stays with the agent for another call (e.g. no answer).
     *
     * @param  array{call_outcome: string, callback_notes: string, called_at?: mixed, site_visit_required?: bool, site_visit_at?: mixed, site_visit_reference?: ?string, site_visit_notes?: ?string, close?: bool}  $data
     */
    public function recordCxFollowUp(Outage $outage, User $by, array $data): Outage
    {
        if (! array_key_exists($data['call_outcome'] ?? '', OutageFollowUp::OUTCOMES)) {
            throw ValidationException::withMessages(['call_outcome' => 'Select the outcome of the callback.']);
        }

        $this->requireText($data['callback_notes'] ?? null, 'callback_notes', 'Describe the callback with the customer.');
        $siteVisit = ! empty($data['site_visit_required']);

        if ($siteVisit && empty($data['site_visit_at'])) {
            throw ValidationException::withMessages(['site_visit_at' => 'Enter when the site visit is scheduled.']);
        }

        $result = DB::transaction(function () use ($outage, $by, $data, $siteVisit): Outage {
            $outage = $this->lock($outage);
            Gate::forUser($by)->authorize('cxFollowUp', $outage);
            $this->fifo->authorizeWork($outage, QueueType::CxFollowUp, $by);
            $close = $data['close'] ?? true;

            $followUp = OutageFollowUp::query()->create([
                'outage_id' => $outage->id,
                'user_id' => $by->id,
                'called_at' => Carbon::parse($data['called_at'] ?? now()),
                'call_outcome' => $data['call_outcome'],
                'callback_notes' => trim($data['callback_notes']),
                'site_visit_required' => $siteVisit,
                'site_visit_at' => $siteVisit ? Carbon::parse($data['site_visit_at']) : null,
                'site_visit_reference' => $siteVisit ? ($data['site_visit_reference'] ?? null) : null,
                'site_visit_notes' => $siteVisit ? ($data['site_visit_notes'] ?? null) : null,
                'closed_ticket' => (bool) $close,
            ]);

            $visit = $siteVisit
                ? 'Site visit raised for '.$followUp->site_visit_at->format('d M Y H:i')
                    .($followUp->site_visit_reference ? " (ref {$followUp->site_visit_reference})" : '')
                    .($followUp->site_visit_notes ? ": {$followUp->site_visit_notes}" : '.')
                : null;
            $summary = "Customer callback — {$followUp->outcomeLabel()}: {$followUp->callback_notes}".($visit ? " {$visit}" : '');

            OutageUpdate::query()->create(['outage_id' => $outage->id, 'user_id' => $by->id, 'type' => 'action', 'body' => $summary]);
            $outage->forceFill(['latest_update' => $summary, 'cx_followed_up_at' => now()])->save();

            $this->notifier->dispatch(NotificationEvent::CxFollowUp, $outage, ['reason' => $summary]);

            if ($visit) {
                $this->notifier->dispatch(NotificationEvent::SiteVisitRaised, $outage, ['reason' => $visit]);
            }

            if ($close) {
                $this->fifo->complete($outage, QueueType::CxFollowUp, $by, 'Customer follow-up completed');
                $outage->forceFill(['end_time' => $outage->end_time ?? now()]);
                $this->transition($outage, StatusCode::NocReject, $by, 'CX follow-up completed: '.$followUp->outcomeLabel());
            } else {
                event(new OutageUpdated($outage->id, $outage->ticket_number));
            }

            return $outage->refresh();
        });

        return $result->refresh();
    }

    // ------------------------------------------------------------------ support technician (parallel)

    /**
     * The field team asks for a support technician (drop cable → customer premises) on the same
     * ticket. The request is worked from its own FIFO queue in parallel: the outage technician
     * keeps the ticket while the support technician fixes the premises side.
     *
     * @param  array{work_required: string, customer_details?: ?string, location?: ?string}  $data
     */
    public function requestSupport(Outage $outage, User $by, array $data): SupportRequest
    {
        $this->requireText($data['work_required'] ?? null, 'work_required', 'Describe the work the support technician must do.');

        return DB::transaction(function () use ($outage, $by, $data): SupportRequest {
            $outage = $this->lock($outage);
            Gate::forUser($by)->authorize('requestSupport', $outage);

            $request = SupportRequest::query()->create([
                'outage_id' => $outage->id,
                'requested_by' => $by->id,
                'work_required' => trim($data['work_required']),
                'customer_details' => $data['customer_details'] ?? null,
                'location' => $data['location'] ?? $outage->location,
                'status' => SupportRequest::OPEN,
            ]);

            $entry = $this->fifo->enqueue($outage, QueueType::SupportTechnician, [
                'reason' => 'Support technician requested by '.$by->name,
            ]);
            $request->forceFill(['queue_entry_id' => $entry->id])->save();

            $details = collect([
                $request->work_required,
                $request->customer_details ? 'Customer: '.$request->customer_details : null,
                $request->location ? 'Location: '.$request->location : null,
            ])->filter()->implode('. ');

            $this->addSystemUpdate($outage, "Support technician requested on site (drop cable / customer premises): {$details}", $by);
            $this->notifier->dispatch(NotificationEvent::SupportRequested, $outage, ['reason' => $details, 'by' => $by->name]);
            event(new OutageUpdated($outage->id, $outage->ticket_number));

            return $request;
        });
    }

    public function completeSupport(Outage $outage, User $by, string $note): Outage
    {
        $this->requireText($note, 'note', 'Describe the drop cable / premises work done.');

        $result = DB::transaction(function () use ($outage, $by, $note): Outage {
            $outage = $this->lock($outage);
            Gate::forUser($by)->authorize('completeSupport', $outage);
            $this->fifo->authorizeWork($outage, QueueType::SupportTechnician, $by);

            $request = SupportRequest::query()->open()->where('outage_id', $outage->id)->lockForUpdate()->latest('id')->firstOrFail();
            $request->forceFill([
                'status' => SupportRequest::COMPLETED,
                'completed_by' => $by->id,
                'completed_at' => now(),
                'completion_note' => $note,
            ])->save();

            $this->fifo->complete($outage, QueueType::SupportTechnician, $by, 'Support work completed');
            OutageUpdate::query()->create([
                'outage_id' => $outage->id, 'user_id' => $by->id, 'type' => 'action',
                'body' => "Support technician completed the drop cable / premises work: {$note}",
            ]);

            // The requester and the outage technician hear about it directly.
            $this->notifier->dispatch(NotificationEvent::SupportCompleted, $outage, [
                'reason' => $note,
                'notify_user_ids' => [$request->requested_by, $outage->technician_id],
            ]);
            event(new OutageUpdated($outage->id, $outage->ticket_number));

            return $outage->refresh();
        });

        return $result->refresh();
    }

    public function cancelSupport(Outage $outage, User $by, string $reason): Outage
    {
        $this->requireText($reason, 'reason', 'Give a reason for cancelling the support request.');

        $result = DB::transaction(function () use ($outage, $by, $reason): Outage {
            $outage = $this->lock($outage);
            Gate::forUser($by)->authorize('cancelSupport', $outage);

            SupportRequest::query()->open()->where('outage_id', $outage->id)->lockForUpdate()->get()
                ->each(fn (SupportRequest $request) => $request->forceFill([
                    'status' => SupportRequest::CANCELLED,
                    'cancelled_by' => $by->id,
                    'cancelled_at' => now(),
                    'cancel_reason' => $reason,
                ])->save());

            $this->fifo->complete($outage, QueueType::SupportTechnician, $by, 'Support request cancelled');
            $this->addSystemUpdate($outage, "Support technician request cancelled: {$reason}", $by);
            event(new OutageUpdated($outage->id, $outage->ticket_number));

            return $outage->refresh();
        });

        return $result->refresh();
    }

    // ------------------------------------------------------------------ system outages: IT first

    /**
     * Who reviews a system outage: the team named on the first affected service that has one
     * (Configuration → Affected Services → "System outages reviewed by"), otherwise the IT desk.
     *
     * @return array{team: ?Team, service: ?Service, options: array<string, mixed>}
     */
    private function systemRoute(Outage $outage): array
    {
        $service = $outage->services()->whereNotNull('services.review_team_id')
            ->whereHas('reviewTeam', fn ($team) => $team->where('is_active', true))
            ->with('reviewTeam')->orderBy('services.id')->first();
        $team = $service?->reviewTeam;

        return ['team' => $team, 'service' => $service, 'options' => $team ? ['team_id' => $team->id, 'any_department' => true] : []];
    }

    /** IT finds a core / network cause and escalates the system outage to NOC. */
    public function itEscalateToNoc(Outage $outage, User $by, string $reason): Outage
    {
        $this->requireText($reason, 'reason', 'Explain why NOC is needed (core / network cause).');

        $result = DB::transaction(function () use ($outage, $by, $reason): Outage {
            $outage = $this->lock($outage);
            Gate::forUser($by)->authorize('itReview', $outage);
            $this->fifo->authorizeWork($outage, QueueType::ItReview, $by);

            $outage->forceFill(['it_escalated_to_noc_at' => now(), 'it_escalated_by' => $by->id, 'noc_escalated_at' => now()]);
            $this->fifo->complete($outage, QueueType::ItReview, $by, 'Escalated to NOC');
            $this->transition($outage, StatusCode::EscalatedToNoc, $by, $reason);
            $this->addSystemUpdate($outage, "IT escalated to NOC (core / network cause): {$reason}", $by);
            $this->fifo->enqueue($outage, QueueType::NocReview, ['reason' => 'Escalated by IT: '.$reason]);
            $this->notifier->dispatch(NotificationEvent::NocEscalation, $outage, ['reason' => 'Escalated by IT: '.$reason]);

            return $outage->refresh();
        });

        return $result->refresh();
    }

    /** NOC has fixed the core / network side and hands the ticket back to IT to confirm. */
    public function nocReturnToIt(Outage $outage, User $by, string $note): Outage
    {
        $this->requireText($note, 'note', 'Describe what NOC fixed.');

        $result = DB::transaction(function () use ($outage, $by, $note): Outage {
            $outage = $this->lock($outage);
            Gate::forUser($by)->authorize('returnToIt', $outage);
            $this->fifo->authorizeWork($outage, QueueType::NocReview, $by);

            $outage->forceFill(['noc_returned_to_it_at' => now(), 'noc_returned_by' => $by->id]);
            $this->fifo->complete($outage, QueueType::NocReview, $by, 'Resolved by NOC — returned to IT');
            $this->transition($outage, StatusCode::AwaitingItConfirmation, $by, $note);
            $this->addSystemUpdate($outage, "NOC resolved the core / network issue and returned the ticket to IT to confirm: {$note}", $by);
            // Back to the people who reviewed it: the system's own team, or the IT desk.
            $this->fifo->enqueue($outage, QueueType::ItConfirmation, $this->systemRoute($outage)['options'] + ['reason' => 'NOC resolved — IT to confirm']);
            $this->notifier->dispatch(NotificationEvent::ReturnedToIt, $outage, ['reason' => $note]);

            return $outage->refresh();
        });

        return $result->refresh();
    }

    /**
     * IT confirms the system is working and shares the feedback with the team, closing the
     * ticket. Used both when IT fixes it directly (IT Review) and after NOC returned it.
     */
    public function itResolve(Outage $outage, User $by, string $feedback): Outage
    {
        $this->requireText($feedback, 'feedback', 'Share the resolution feedback for the team.');

        $result = DB::transaction(function () use ($outage, $by, $feedback): Outage {
            $outage = $this->lock($outage);
            $inReview = $this->statusIs($outage, StatusCode::ItReview);
            Gate::forUser($by)->authorize($inReview ? 'itReview' : 'itConfirm', $outage);
            $queue = $inReview ? QueueType::ItReview : QueueType::ItConfirmation;
            $this->fifo->authorizeWork($outage, $queue, $by);

            $outage->forceFill([
                'final_reason' => $feedback,
                'it_confirmed_at' => now(),
                'it_confirmed_by' => $by->id,
                'resolved_at' => now(),
                'resolved_by' => $by->id,
                'end_time' => $outage->end_time ?? now(),
                'latest_update' => $feedback,
            ]);

            $this->fifo->complete($outage, $queue, $by, 'IT confirmed resolved');
            $this->transition($outage, StatusCode::Resolved, $by, 'IT confirmed resolved');
            $this->addSystemUpdate($outage, "IT confirmed the system is working. Feedback: {$feedback}", $by);

            // Feedback goes to the resolution rules and straight to the person who reported it.
            $this->notifier->dispatch(NotificationEvent::Resolved, $outage, ['reason' => $feedback, 'notify_user_ids' => [$outage->created_by]]);

            return $outage->refresh();
        });

        return $result->refresh();
    }

    /** IT checked NOC's fix and the system is still failing: back to NOC. */
    public function itReturnToNoc(Outage $outage, User $by, string $reason): Outage
    {
        $this->requireText($reason, 'reason', 'Describe what is still failing.');

        $result = DB::transaction(function () use ($outage, $by, $reason): Outage {
            $outage = $this->lock($outage);
            Gate::forUser($by)->authorize('itConfirm', $outage);
            $this->fifo->authorizeWork($outage, QueueType::ItConfirmation, $by);

            $this->fifo->complete($outage, QueueType::ItConfirmation, $by, 'Not resolved — returned to NOC');
            $this->transition($outage, StatusCode::EscalatedToNoc, $by, $reason);
            $this->addSystemUpdate($outage, "IT: still not working — returned to NOC: {$reason}", $by);
            $this->fifo->enqueue($outage, QueueType::NocReview, ['reason' => 'IT: not resolved — '.$reason]);
            $this->notifier->dispatch(NotificationEvent::NocEscalation, $outage, ['reason' => 'IT: still not working — '.$reason]);

            return $outage->refresh();
        });

        return $result->refresh();
    }

    /** Explicit YES/NO credit / SMS tracking with who/when audit (spec §18). */
    public function setFlag(Outage $outage, string $flag, bool $value, User $by, bool $authorize = true): void
    {
        if (! in_array($flag, ['credit_shared', 'sms_sent'], true)) {
            throw new \InvalidArgumentException("Unknown flag [{$flag}].");
        }

        if ($authorize) {
            Gate::forUser($by)->authorize('updateFlags', $outage);
        }

        $old = (bool) $outage->getAttribute($flag);

        if ($old === $value && $outage->{$flag === 'credit_shared' ? 'credit_updated_at' : 'sms_updated_at'} !== null) {
            return;
        }

        $prefix = $flag === 'credit_shared' ? 'credit' : 'sms';

        $outage->forceFill([
            $flag => $value,
            "{$prefix}_updated_by" => $by->id,
            "{$prefix}_updated_at" => now(),
        ])->save();

        OutageFlagEvent::query()->create([
            'outage_id' => $outage->id,
            'flag' => $flag,
            'old_value' => $old,
            'new_value' => $value,
            'changed_by' => $by->id,
        ]);

        if ($value) {
            $this->notifier->dispatch($flag === 'credit_shared' ? NotificationEvent::CreditShared : NotificationEvent::SmsSent, $outage);
        }

        event(new OutageUpdated($outage->id, $outage->ticket_number));
    }

    // ------------------------------------------------------------------ internals

    private function afterConfirmation(Outage $outage, ?User $by, ?string $note): void
    {
        $outage->loadMissing('majorOutageType');

        if (! $outage->majorOutageType?->requires_infrastructure) {
            if (! $outage->noc_confirmed_at) {
                $outage->forceFill(['noc_confirmed_at' => now()]);
                $this->transition($outage, StatusCode::NocConfirmed, $by, $note);
            }

            return;
        }

        // Step 5: confirmation automatically escalates to Infrastructure.
        $outage->forceFill(['infra_escalated_at' => now(), 'noc_confirmed_at' => $outage->noc_confirmed_at ?? now()]);
        AuditContext::as('system:noc-confirmation', fn () => $this->transition($outage, StatusCode::AssignedToInfrastructure, null, 'Automatically escalated to Infrastructure'));
        $this->fifo->enqueue($outage, QueueType::Infrastructure, ['team_id' => $outage->team_id, 'reason' => 'Automatic escalation after NOC confirmation']);
        $this->notifier->dispatch(NotificationEvent::InfrastructureEscalation, $outage);
    }

    private function transition(Outage $outage, StatusCode|OutageStatus $to, ?User $by, ?string $reason): void
    {
        $status = $to instanceof OutageStatus ? $to : OutageStatus::forCode($to);
        $fromId = $outage->getOriginal('status_id');

        $outage->forceFill(['status_id' => $status->id, 'is_closed' => $status->is_closed])->save();
        $outage->setRelation('status', $status);

        $this->recordHistory($outage, $fromId, $status, $reason, $by);

        // 4G and customer ticket audit trails: every status change with who, previous and new status, and the comment.
        if (($outage->isMobility() || $outage->isTicket()) && (int) $fromId !== (int) $status->id) {
            $outage->audit(($outage->isTicket() ? AuditLog::TICKET_PREFIX : AuditLog::MOBILITY_PREFIX).'status_changed', [
                'ticket' => $outage->ticket_number, 'status' => $status->name, 'comment' => $reason,
            ], ['status' => OutageStatus::query()->whereKey($fromId)->value('name')], $by?->id ?? false);
        }

        $this->sla->onStatusChanged($outage, $status);

        if ($status->is_closed) {
            SupportRequest::query()->open()->where('outage_id', $outage->id)->update([
                'status' => SupportRequest::CANCELLED,
                'cancelled_at' => now(),
                'cancelled_by' => $by?->id,
                'cancel_reason' => 'Outage closed: '.$status->name,
                'updated_at' => now(),
            ]);
            $this->fifo->cancelAll($outage, 'Outage closed: '.$status->name, $by);
            $this->sla->finalize($outage);
        }

        event(new OutageStatusChanged($outage->id, $outage->ticket_number, array_filter([$outage->current_assignee_id]), ['status' => $status->name]));
        $this->notifier->dispatch(NotificationEvent::StatusChanged, $outage, ['reason' => $reason]);
    }

    private function recordHistory(Outage $outage, ?int $fromId, OutageStatus $to, ?string $reason, ?User $by): void
    {
        OutageStatusHistory::query()->create([
            'outage_id' => $outage->id,
            'from_status_id' => $fromId,
            'to_status_id' => $to->id,
            'reason' => $reason,
            'changed_by' => $by?->id,
            'trigger' => AuditContext::trigger(),
            'snapshot' => [
                'priority_id' => $outage->priority_id,
                'priority' => $outage->priority?->name,
                'customers_affected' => $outage->customers_affected,
                'team_id' => $outage->team_id,
                'sub_team_id' => $outage->sub_team_id,
                'technician_id' => $outage->technician_id,
                'assignee_id' => $outage->current_assignee_id,
                'queue' => $outage->current_queue?->value,
                'sla_state' => $outage->sla_state?->value,
                'sla_due_at' => $outage->sla_due_at?->toIso8601String(),
            ],
        ]);
    }

    private function applyPriority(Outage $outage, array $data, User $by): void
    {
        $manual = ! empty($data['priority_is_manual']) && ! empty($data['priority_id']) && $by->can('outage.override_priority');

        if ($manual) {
            $outage->priority_is_manual = true;
            $outage->priority_id = (int) $data['priority_id'];

            return;
        }

        $outage->priority_is_manual = false;
        $outage->priority_id = $this->priorities->forCustomers((int) $outage->customers_affected, $outage->vertical_id ? (int) $outage->vertical_id : null)?->id;
    }

    private function onPriorityChanged(Outage $outage): void
    {
        $outage->load('priority');
        $this->sla->apply($outage, 'recalculated');
        $this->fifo->refreshPriority($outage);
        $this->notifier->dispatch(NotificationEvent::PriorityChanged, $outage);
        $this->addSystemUpdate($outage, 'Priority changed to '.($outage->priority?->name ?? 'none').'.', null);
    }

    /**
     * Keep the OLT → FDT → Slot → Port selection consistent: children must belong to a
     * selected parent, and inactive elements cannot be newly attached (spec §8).
     */
    private function syncTopology(Outage $outage, array $data, bool $forNewOutage): void
    {
        $existing = [
            'olts' => $outage->olts()->pluck('olts.id')->all(),
            'fdts' => $outage->fdts()->pluck('fdts.id')->all(),
            'slots' => $outage->slots()->pluck('slots.id')->all(),
            'ports' => $outage->ports()->pluck('ports.id')->all(),
        ];

        $filter = function (string $model, array $ids, ?string $parentColumn, array $parentIds, array $keep) {
            $ids = array_map('intval', array_filter($ids));

            if ($ids === []) {
                return [];
            }

            return $model::query()
                ->whereIn('id', $ids)
                ->when($parentColumn, fn ($q) => $q->whereIn($parentColumn, $parentIds ?: [0]))
                ->where(fn ($q) => $q->where('is_active', true)->orWhereIn('id', $keep ?: [0]))
                ->pluck('id')
                ->all();
        };

        $olts = $filter(Olt::class, $data['olt_ids'] ?? [], null, [], $forNewOutage ? [] : $existing['olts']);
        $fdts = $filter(Fdt::class, $data['fdt_ids'] ?? [], 'olt_id', $olts, $forNewOutage ? [] : $existing['fdts']);
        $slots = $filter(Slot::class, $data['slot_ids'] ?? [], 'fdt_id', $fdts, $forNewOutage ? [] : $existing['slots']);
        $ports = $filter(Port::class, $data['port_ids'] ?? [], 'slot_id', $slots, $forNewOutage ? [] : $existing['ports']);

        $outage->olts()->sync($olts);
        $outage->fdts()->sync($fdts);
        $outage->slots()->sync($slots);
        $outage->ports()->sync($ports);

        $after = compact('olts', 'fdts', 'slots', 'ports');

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

    private function syncServices(Outage $outage, array $serviceIds, bool $forNewOutage): void
    {
        $keep = $forNewOutage ? [] : $outage->services()->pluck('services.id')->all();
        $ids = Service::query()
            ->whereIn('id', array_map('intval', array_filter($serviceIds)) ?: [0])
            ->where(fn ($q) => $q->where('is_active', true)->orWhereIn('id', $keep ?: [0]))
            ->pluck('id')
            ->all();

        $outage->services()->sync($ids);
    }

    private function addSystemUpdate(Outage $outage, string $body, ?User $by): void
    {
        OutageUpdate::query()->create([
            'outage_id' => $outage->id,
            'user_id' => $by?->id,
            'type' => 'system',
            'body' => $body,
            'is_system' => true,
        ]);
    }

    private function lock(Outage $outage): Outage
    {
        return Outage::query()->with(['status', 'majorOutageType', 'priority', 'slaPolicy', 'team', 'subTeam'])->lockForUpdate()->findOrFail($outage->id);
    }

    private function statusIs(Outage $outage, StatusCode ...$codes): bool
    {
        return in_array(OutageStatus::codeForId($outage->status_id), array_map(fn (StatusCode $code) => $code->value, $codes), true);
    }

    private function requireText(?string $value, string $field, string $message): void
    {
        if (trim((string) $value) === '') {
            throw ValidationException::withMessages([$field => $message]);
        }
    }
}
