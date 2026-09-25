<?php

namespace App\Services\Outages;

use App\Enums\NotificationEvent;
use App\Enums\QueueType;
use App\Enums\SlaState;
use App\Enums\StatusCode;
use App\Events\OutageCreated;
use App\Events\OutageStatusChanged;
use App\Events\OutageUpdated;
use App\Models\Fdt;
use App\Models\Olt;
use App\Models\Outage;
use App\Models\OutageFlagEvent;
use App\Models\OutageStatus;
use App\Models\OutageStatusHistory;
use App\Models\OutageType;
use App\Models\OutageUpdate;
use App\Models\Port;
use App\Models\Service;
use App\Models\Slot;
use App\Models\User;
use App\Services\Fifo\FifoAssignmentEngine;
use App\Services\Notifications\NotificationDispatcher;
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
    public function __construct(
        private readonly TicketNumberGenerator $tickets,
        private readonly PriorityResolver $priorities,
        private readonly SlaService $sla,
        private readonly FifoAssignmentEngine $fifo,
        private readonly NotificationDispatcher $notifier,
    ) {}

    // ------------------------------------------------------------------ create / edit

    public function create(array $data, User $by): Outage
    {
        Gate::forUser($by)->authorize('create', Outage::class);

        $result = DB::transaction(function () use ($data, $by): Outage {
            $type = OutageType::query()->with('majorOutageType')->active()->findOrFail($data['outage_type_id']);
            $major = $type->majorOutageType;

            $outage = new Outage(Arr::only($data, (new Outage)->getFillable()));
            $outage->major_outage_type_id = $major->id;
            $outage->ticket_number = $this->tickets->next($type->prefix);
            $outage->customers_affected = (int) ($data['customers_affected'] ?? 0);
            $outage->start_time = Carbon::parse($data['start_time'] ?? now());
            $this->applyPriority($outage, $data, $by);

            $initial = OutageStatus::forCode(StatusCode::SupportUnconfirmed);
            $outage->status_id = $initial->id;
            $outage->is_closed = false;
            $outage->sla_state = SlaState::Compliant;
            $outage->created_by = $by->id;
            $outage->updated_by = $by->id;
            $outage->save();

            $this->syncTopology($outage, $data, forNewOutage: true);
            $this->syncServices($outage, $data['service_ids'] ?? [], forNewOutage: true);

            $this->recordHistory($outage, null, $initial, 'Outage reported', $by);
            $this->sla->apply($outage->refresh(), 'started');

            foreach (['credit_shared', 'sms_sent'] as $flag) {
                if (! empty($data[$flag])) {
                    $this->setFlag($outage, $flag, true, $by, authorize: false);
                }
            }

            event(new OutageCreated($outage->id, $outage->ticket_number));
            $this->notifier->dispatch(NotificationEvent::OutageCreated, $outage);

            if ($major->requires_noc_confirmation) {
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
            $this->transition($outage, StatusCode::NocReject, $by, $reason);
            $this->addSystemUpdate($outage, "NOC rejected the outage: {$reason}", $by);
            $this->notifier->dispatch(NotificationEvent::NocRejected, $outage, ['reason' => $reason]);

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
                'type' => array_key_exists($type, OutageUpdate::TYPES) ? $type : 'note',
                'body' => $body,
                'is_system' => false,
            ]);

            $outage->forceFill(['latest_update' => $body])->save();
            event(new OutageUpdated($outage->id, $outage->ticket_number));

            return $update;
        });
    }

    public function reportRestored(Outage $outage, User $by, string $note, ?string $restoredAt = null): Outage
    {
        $this->requireText($note, 'note', 'Describe the restoration performed.');

        $result = DB::transaction(function () use ($outage, $by, $note, $restoredAt): Outage {
            $outage = $this->lock($outage);
            Gate::forUser($by)->authorize('reportRestored', $outage);

            if ($outage->majorOutageType?->requires_infrastructure) {
                $this->fifo->authorizeWork($outage, QueueType::Technician, $by);
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

    public function confirmRestoration(Outage $outage, User $by, ?string $note = null): Outage
    {
        $result = DB::transaction(function () use ($outage, $by, $note): Outage {
            $outage = $this->lock($outage);
            Gate::forUser($by)->authorize('confirmRestoration', $outage);
            $this->fifo->authorizeWork($outage, QueueType::NocRestore, $by);

            $outage->forceFill([
                'restore_confirmed_at' => now(),
                'restore_confirmed_by' => $by->id,
                'end_time' => $outage->restored_at ?? now(),
            ]);

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
        if (! $status->is_active || ! $status->is_manual_selectable) {
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
        $this->sla->onStatusChanged($outage, $status);

        if ($status->is_closed) {
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
        $outage->priority_id = $this->priorities->forCustomers((int) $outage->customers_affected)?->id;
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

    private function requireText(?string $value, string $field, string $message): void
    {
        if (trim((string) $value) === '') {
            throw ValidationException::withMessages([$field => $message]);
        }
    }
}
