<?php

namespace App\Services\Fifo;

use App\Enums\AssignmentStatus;
use App\Enums\AssignmentType;
use App\Enums\AvailabilityStatus;
use App\Enums\NotificationChannel;
use App\Enums\NotificationEvent;
use App\Enums\QueueEntryStatus;
use App\Enums\QueueType;
use App\Events\AssignmentTimeout;
use App\Events\OutageAccepted;
use App\Events\OutageAssigned;
use App\Events\OutageRejected;
use App\Events\OutageUpdated;
use App\Events\PriorityChanged;
use App\Events\TechnicianAvailabilityChanged;
use App\Models\Department;
use App\Models\Outage;
use App\Models\OutageAssignment;
use App\Models\OutageAssignmentHistory;
use App\Models\OutageQueueEntry;
use App\Models\QueueDefinition;
use App\Models\User;
use App\Models\UserAvailability;
use App\Services\Notifications\NotificationDispatcher;
use App\Services\Sla\DeskSla;
use App\Support\AuditContext;
use App\Support\Settings;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Priority-aware FIFO assignment engine (spec §27).
 *
 * This is a plain application service with no UI dependency so the same logic can be driven
 * by Filament, the scheduler, APIs, monitoring integrations or a mobile app. Queue processing
 * is serialised per queue with a distributed cache lock, making it safe to run on every node
 * behind a load balancer.
 */
class FifoAssignmentEngine
{
    public function __construct(
        private readonly Settings $settings,
        private readonly EligibilityEvaluator $evaluator,
        private readonly NotificationDispatcher $notifier,
        private readonly DeskSla $deskSla,
    ) {}

    public function enabled(): bool
    {
        return $this->settings->bool('fifo.enabled');
    }

    public function isAutomatic(): bool
    {
        return $this->enabled() && $this->settings->get('fifo.assignment_mode') === 'auto';
    }

    // ------------------------------------------------------------------ queueing

    /** Most specific active queue definition for the outage (vertical / major type matchers). */
    public function resolveDefinition(Outage $outage, QueueType $type): ?QueueDefinition
    {
        return QueueDefinition::query()
            ->active()
            ->where('queue_type', $type->value)
            ->where(fn ($q) => $q->whereNull('major_outage_type_id')->orWhere('major_outage_type_id', $outage->major_outage_type_id))
            ->where(fn ($q) => $q->whereNull('vertical_id')->orWhere('vertical_id', $outage->vertical_id))
            ->get()
            ->sort(fn (QueueDefinition $a, QueueDefinition $b) => [$this->specificity($b), $a->sort_order, $a->id] <=> [$this->specificity($a), $b->sort_order, $b->id])
            ->first();
    }

    /**
     * Place the outage in a queue. Idempotent: an existing open entry of the same type is
     * returned unchanged, preserving its original FIFO timestamp.
     *
     * @param  array{team_id?: ?int, sub_team_id?: ?int, reason?: ?string, queued_at?: mixed, eligible_from?: mixed, any_department?: bool, step_name?: ?string, sla_minutes?: ?int, manual?: bool}  $options
     */
    public function enqueue(Outage $outage, QueueType $type, array $options = []): OutageQueueEntry
    {
        return DB::transaction(function () use ($outage, $type, $options): OutageQueueEntry {
            $existing = $outage->openQueueEntries()->where('queue_type', $type->value)->lockForUpdate()->first();

            if ($existing) {
                return $existing;
            }

            $outage->loadMissing('priority');
            $definition = $this->resolveDefinition($outage, $type);

            $entry = OutageQueueEntry::query()->create([
                'outage_id' => $outage->id,
                'queue_definition_id' => $definition?->id,
                'queue_type' => $type,
                // A step of a custom workflow carries its own name; a "manual" step waits for a manager.
                'step_name' => $options['step_name'] ?? null,
                'auto_assign_blocked' => ! empty($options['manual']),
                'priority_id' => $outage->priority_id,
                'priority_rank' => $outage->priority?->queue_rank ?? 999,
                // "any_department": the team named for the ticket decides who gets it, whatever their department.
                'department_id' => ! empty($options['any_department']) ? null : ($definition?->department_id
                    ?? Department::query()->where('code', $type->defaultDepartmentCode())->value('id')),
                'team_id' => $options['team_id'] ?? $definition?->team_id,
                'sub_team_id' => $options['sub_team_id'] ?? null,
                'queued_at' => $options['queued_at'] ?? now(),
                // Not offered to anyone before this moment (a site visit booked for a later day).
                'eligible_from' => $options['eligible_from'] ?? null,
                'status' => QueueEntryStatus::Pending,
            ]);

            $this->deskSla->stamp($entry, $outage, $options['sla_minutes'] ?? null);

            // A parallel queue (support technician) runs alongside; the ticket keeps its main owner.
            if (! $type->isParallel()) {
                $outage->forceFill(['current_queue' => $type, 'current_assignee_id' => null])->save();
            }

            $this->history($outage, null, $type, null, null, 'queued', $options['reason'] ?? 'Placed in '.$entry->label().' queue');

            $this->scheduleProcessing($type);

            return $entry;
        });
    }

    /**
     * Assign pending entries to eligible users. Returns number of assignments made.
     */
    public function process(?QueueType $type = null): int
    {
        if (! $this->isAutomatic()) {
            return 0;
        }

        $assigned = 0;

        foreach ($type ? [$type] : QueueType::cases() as $queueType) {
            $lock = Cache::lock('oms:fifo:'.$queueType->value, 60);

            try {
                $lock->block(10);
            } catch (LockTimeoutException) {
                continue; // another node is working this queue; the scheduler sweep retries.
            }

            try {
                $assigned += AuditContext::as('system:fifo-engine', fn () => $this->processQueue($queueType));
            } finally {
                $lock->release();
            }
        }

        return $assigned;
    }

    private function processQueue(QueueType $type): int
    {
        $entries = OutageQueueEntry::query()
            ->pending()
            ->where('queue_type', $type->value)
            ->where('auto_assign_blocked', false)
            ->where(fn ($q) => $q->whereNull('eligible_from')->orWhere('eligible_from', '<=', now()))
            ->with(['queueDefinition', 'outage.priority', 'outage.olts:id', 'outage.services:id', 'outage.ticket.visitType'])
            ->fifoOrder($this->settings->bool('fifo.priority_aware'))
            ->limit(200)
            ->get();

        if ($entries->isEmpty()) {
            return 0;
        }

        $this->evaluator->beginRun();
        $assigned = 0;
        $stillWaitingAhead = 0;

        foreach ($entries as $entry) {
            // The 72-hour follow-up call is made on a site visit that is already closed.
            if ($entry->queueDefinition?->isManual() || ! $entry->outage || ($entry->outage->is_closed && $entry->queue_type !== QueueType::VisitAftercare)) {
                $stillWaitingAhead++;

                continue;
            }

            $decision = $this->evaluator->decide($entry);

            if (! $decision['user']) {
                $stillWaitingAhead++;

                continue;
            }

            $this->assign(
                $entry,
                $decision['user'],
                AssignmentType::AutoFifo,
                null,
                'Automatic FIFO: oldest eligible ticket in the '.$type->getLabel().' queue assigned to the longest-waiting eligible user.',
                $decision['meta'],
                $stillWaitingAhead + 1,
            );

            $this->evaluator->recordAssignment($decision['user']->id);
            $assigned++;
        }

        return $assigned;
    }

    // ------------------------------------------------------------------ assignment lifecycle

    public function assign(
        OutageQueueEntry $entry,
        User $user,
        AssignmentType $type,
        ?User $by,
        ?string $reason,
        array $meta = [],
        ?int $position = null,
    ): OutageAssignment {
        return DB::transaction(function () use ($entry, $user, $type, $by, $reason, $meta, $position): OutageAssignment {
            /** @var OutageQueueEntry $entry */
            $entry = OutageQueueEntry::query()->lockForUpdate()->findOrFail($entry->id);

            if (! in_array($entry->status->value, QueueEntryStatus::openValues(), true)) {
                throw ValidationException::withMessages(['queue' => 'This queue item is no longer open.']);
            }

            $current = $entry->activeAssignment()->first();

            if ($current?->user_id === $user->id) {
                return $current;
            }

            if ($current) {
                $this->closeAssignment($current, AssignmentStatus::Released, 'Reassigned: '.$reason, $by, 'released');
            }

            $outage = Outage::query()->with(['priority', 'slaPolicy'])->findOrFail($entry->outage_id);
            $now = now();
            $timeout = $this->ackTimeoutMinutes($entry, $outage);

            $assignment = OutageAssignment::query()->create([
                'outage_id' => $outage->id,
                'queue_entry_id' => $entry->id,
                'user_id' => $user->id,
                'team_id' => $entry->team_id ?? $user->teams()->value('teams.id'),
                'sub_team_id' => $entry->sub_team_id,
                'queue_type' => $entry->queue_type,
                'assignment_type' => $type,
                'queue_position' => $position ?? $this->positionOf($entry),
                'priority_id' => $outage->priority_id,
                'priority_name' => $outage->priority?->name,
                'queued_at' => $entry->queued_at,
                'assigned_at' => $now,
                'ack_due_at' => $timeout > 0 ? $now->copy()->addMinutes($timeout) : null,
                'status' => AssignmentStatus::Assigned,
                'assignment_reason' => $reason,
                'decision_meta' => $meta,
                'assigned_by' => $by?->id,
            ]);

            $entry->forceFill(['status' => QueueEntryStatus::Assigned, 'assigned_at' => $now])->save();

            // saveQuietly: bookkeeping only, keeps the user audit trail readable.
            $user->forceFill(['last_assigned_at' => $now])->saveQuietly();

            if (! $entry->queue_type->isParallel()) {
                $outageUpdates = ['current_assignee_id' => $user->id, 'current_queue' => $entry->queue_type];
                if ($entry->queue_type === QueueType::Technician) {
                    $outageUpdates['technician_id'] = $user->id;
                }
                $outage->forceFill($outageUpdates)->save();
            }

            $this->history($outage, $assignment, $entry->queue_type, $current?->user_id, $user->id, $type === AssignmentType::AutoFifo ? 'assigned' : $type->value, $reason, $by);

            event(new OutageAssigned($outage->id, $outage->ticket_number, [$user->id], ['queue' => $entry->queue_type->value]));

            $context = [
                'assignee_id' => $user->id,
                'queue' => $entry->queue_type->getLabel(),
                'by' => $by?->name ?? 'FIFO engine (automatic)',
            ];

            // The assignee always gets it in-app, and by e-mail when "E-mail the assignee" is enabled.
            $this->notifier->notifyUsers(NotificationEvent::Assigned, $outage, [$user], $this->notifier->assignmentChannels(), $context);
            $this->notifier->dispatch(NotificationEvent::Assigned, $outage, $context);

            return $assignment;
        });
    }

    public function accept(OutageAssignment $assignment, User $user): OutageAssignment
    {
        return DB::transaction(function () use ($assignment, $user): OutageAssignment {
            $assignment = OutageAssignment::query()->lockForUpdate()->findOrFail($assignment->id);

            if ($assignment->user_id !== $user->id) {
                throw new AuthorizationException('Only the assigned user can accept this ticket.');
            }

            if ($assignment->status === AssignmentStatus::Accepted) {
                return $assignment;
            }

            if ($assignment->status !== AssignmentStatus::Assigned) {
                throw ValidationException::withMessages(['assignment' => 'This assignment is no longer awaiting acceptance.']);
            }

            $assignment->forceFill(['status' => AssignmentStatus::Accepted, 'accepted_at' => now()])->save();
            $assignment->queueEntry?->forceFill(['status' => QueueEntryStatus::Accepted])->save();

            $outage = $assignment->outage;
            $this->history($outage, $assignment, $assignment->queue_type, $user->id, $user->id, 'accepted', null, $user);

            event(new OutageAccepted($outage->id, $outage->ticket_number, [$user->id]));

            return $assignment;
        });
    }

    public function reject(OutageAssignment $assignment, User $user, string $reason): void
    {
        DB::transaction(function () use ($assignment, $user, $reason): void {
            $assignment = OutageAssignment::query()->lockForUpdate()->findOrFail($assignment->id);

            if ($assignment->user_id !== $user->id) {
                throw new AuthorizationException('Only the assigned user can reject this ticket.');
            }

            if (! $assignment->isActive()) {
                throw ValidationException::withMessages(['assignment' => 'This assignment is no longer active.']);
            }

            $assignment->forceFill([
                'status' => AssignmentStatus::Rejected,
                'rejected_at' => now(),
                'release_reason' => $reason,
            ])->save();

            $this->history($assignment->outage, $assignment, $assignment->queue_type, $user->id, null, 'rejected', $reason, $user);

            $this->returnToQueue($assignment, $user->id, $this->settings->bool('fifo.requeue_on_rejection'), $reason);

            event(new OutageRejected($assignment->outage_id, $assignment->outage->ticket_number, [$user->id]));

            $this->notifier->dispatch(NotificationEvent::AssignmentRejected, $assignment->outage, ['reason' => $reason]);
        });
    }

    /** Requeue assignments whose acknowledgement window elapsed (scheduler, every minute). */
    public function processTimeouts(): int
    {
        $expired = OutageAssignment::query()
            ->where('status', AssignmentStatus::Assigned->value)
            ->whereNotNull('ack_due_at')
            ->where('ack_due_at', '<', now())
            ->orderBy('ack_due_at')
            ->limit(500)
            ->pluck('id');

        foreach ($expired as $id) {
            AuditContext::as('system:assignment-timeout', function () use ($id): void {
                DB::transaction(function () use ($id): void {
                    $assignment = OutageAssignment::query()->lockForUpdate()->find($id);

                    if (! $assignment || $assignment->status !== AssignmentStatus::Assigned) {
                        return;
                    }

                    $assignment->forceFill([
                        'status' => AssignmentStatus::TimedOut,
                        'timed_out_at' => now(),
                        'release_reason' => 'Acknowledgement timeout',
                    ])->save();

                    $outage = $assignment->outage;
                    $this->history($outage, $assignment, $assignment->queue_type, $assignment->user_id, null, 'timed_out', 'Not acknowledged by '.$assignment->ack_due_at?->format('H:i'));

                    $entry = $this->returnToQueue($assignment, $assignment->user_id, $this->settings->bool('fifo.requeue_on_timeout'), 'Acknowledgement timeout');

                    event(new AssignmentTimeout($outage->id, $outage->ticket_number, [$assignment->user_id]));

                    $leaders = $this->notifier->teamLeaders($outage);
                    $this->notifier->notifyUsers(NotificationEvent::AssignmentTimeout, $outage, $leaders->push($assignment->user)->filter(), [NotificationChannel::InApp->value], [
                        'reason' => 'Not acknowledged by '.$assignment->user?->name,
                        'attempts' => $entry?->attempts,
                    ]);
                    $this->notifier->dispatch(NotificationEvent::AssignmentTimeout, $outage, ['attempts' => $entry?->attempts]);
                });
            });
        }

        if ($expired->isNotEmpty()) {
            $this->process();
        }

        return $expired->count();
    }

    /**
     * Release assignments held by users who are no longer eligible to hold them
     * (inactive, unavailable, on leave, off shift) per the configured requeue rule.
     */
    public function sweepIneligibleAssignees(): int
    {
        $mode = $this->settings->get('fifo.requeue_on_unavailable');

        if (! $this->enabled() || $mode === 'none') {
            return 0;
        }

        $statuses = $mode === 'all' ? AssignmentStatus::activeValues() : [AssignmentStatus::Assigned->value];
        $now = now();

        $assignments = OutageAssignment::query()
            ->whereIn('status', $statuses)
            ->with(['user' => fn ($q) => $q->with([
                'shiftAssignments' => fn ($s) => $s->activeAt($now),
                'leaves' => fn ($l) => $l->approvedAt($now),
            ])])
            ->limit(1000)
            ->get();

        $released = 0;

        foreach ($assignments as $assignment) {
            $user = $assignment->user;
            $reason = match (true) {
                ! $user || ! $user->is_active => 'User deactivated',
                $this->settings->bool('fifo.require_availability') && $user->availability_status !== AvailabilityStatus::Available && $user->availability_status !== AvailabilityStatus::Busy => 'User became '.$user->availability_status->getLabel(),
                $this->settings->bool('fifo.exclude_on_leave') && $user->leaves->isNotEmpty() => 'User is on leave',
                $this->settings->bool('fifo.respect_shift') && $user->shiftAssignments->isEmpty() => 'User shift ended',
                default => null,
            };

            if ($reason) {
                AuditContext::as('system:availability-sweep', fn () => $this->release($assignment, $reason, null));
                $released++;
            }
        }

        if ($released > 0) {
            $this->process();
        }

        return $released;
    }

    /** Release an active assignment back to its queue (keeps original FIFO time). */
    public function release(OutageAssignment $assignment, string $reason, ?User $by): void
    {
        DB::transaction(function () use ($assignment, $reason, $by): void {
            $assignment = OutageAssignment::query()->lockForUpdate()->findOrFail($assignment->id);

            if (! $assignment->isActive()) {
                return;
            }

            $this->closeAssignment($assignment, AssignmentStatus::Released, $reason, $by, 'released');
            $this->returnToQueue($assignment, null, true, $reason);
        });
    }

    /**
     * Offer a waiting ticket to everyone at its desk again: people who let it time out or turned it down are
     * offered it again, a hold is lifted, and — when asked — its team limit too. Recorded in the assignment
     * history. Hard rules stay: the desk permission, availability, leave, shifts, regions, Shifting visits.
     */
    public function reopenToEveryone(OutageQueueEntry $entry, ?User $by, string $reason, bool $dropTeam = false): void
    {
        DB::transaction(function () use ($entry, $by, $reason, $dropTeam): void {
            $entry = OutageQueueEntry::query()->lockForUpdate()->find($entry->id);

            if (! $entry || $entry->status !== QueueEntryStatus::Pending || $entry->activeAssignment()->exists()) {
                return;
            }

            $entry->forceFill(array_merge(
                ['rejected_user_ids' => [], 'attempts' => 0, 'auto_assign_blocked' => false, 'eligible_from' => null, 'escalated_at' => null],
                $dropTeam ? ['team_id' => null, 'sub_team_id' => null] : [],
            ))->save();

            $this->history($entry->outage, null, $entry->queue_type, null, null, 'reopened', $reason, $by);
        });

        $this->process($entry->queue_type);
    }

    /**
     * Every N minutes (FIFO settings: "Offer waiting tickets to everyone again"), a ticket nobody has taken
     * because everyone at the desk already let it time out or turned it down is offered to all of them again.
     */
    public function reopenWaiting(): int
    {
        $minutes = $this->settings->int('fifo.reopen_waiting_minutes');

        if (! $this->enabled() || $minutes <= 0) {
            return 0;
        }

        $entries = OutageQueueEntry::query()->pending()
            ->where('auto_assign_blocked', false)
            ->whereNotNull('rejected_user_ids')->where('attempts', '>', 0)
            ->where('updated_at', '<=', now()->subMinutes($minutes))
            ->whereDoesntHave('activeAssignment')
            ->limit(200)->get();

        foreach ($entries as $entry) {
            AuditContext::as('system:fifo-reopen', fn () => $this->reopenToEveryone($entry, null, "Waiting over {$minutes} minutes: offered to everyone at the desk again"));
        }

        return $entries->count();
    }

    /**
     * Manager assignment / override (spec §27.7). Recorded with who, when and why, plus the
     * eligibility checks the target failed so the override is fully explainable.
     */
    public function manualAssign(Outage $outage, QueueType $type, User $target, User $by, string $reason): OutageAssignment
    {
        if (! $this->settings->bool('fifo.manual_override') || ! $by->can($type->overridePermission())) {
            throw new AuthorizationException('You are not authorised to override FIFO assignment.');
        }

        $entry = $outage->openQueueEntries()->where('queue_type', $type->value)->first()
            ?? $this->enqueueWithoutProcessing($outage, $type);

        $entry->loadMissing(['queueDefinition', 'outage.priority', 'outage.olts:id', 'outage.services:id']);

        $this->evaluator->beginRun();
        $checks = $this->evaluator->checks($entry, $target->loadMissing([
            'teams.olts:id',
            'shiftAssignments' => fn ($q) => $q->activeAt(now()),
            'leaves' => fn ($q) => $q->approvedAt(now()),
            'skills' => fn ($q) => $q->where('is_active', true),
            'regions',
        ]));

        // Overrides never bypass hard controls: the target must be active and permitted.
        // (A step of a custom workflow has no permission of its own: its team decides, and a manager may go outside it.)
        if (! $checks['active']['passed'] || ! ($checks['permission']['passed'] ?? true)) {
            throw ValidationException::withMessages(['user_id' => 'The selected user is inactive or lacks permission to work this queue.']);
        }

        // A visit kept for some technicians (Shifting / Relocation) is not overridden to anyone else. (A dispatcher
        // without it may be given one: the override is recorded with that failed check.)
        if ($type === QueueType::VisitTechnician && ! ($checks['visit_type']['passed'] ?? true)) {
            throw ValidationException::withMessages(['user_id' => "{$target->name} is not allowed this kind of visit ({$checks['visit_type']['detail']})."]);
        }

        $failed = collect($checks)->reject(fn (array $check) => $check['passed'])->map(fn (array $check) => $check['label'].': '.$check['detail'])->values()->all();

        return $this->assign($entry, $target, AssignmentType::Override, $by, $reason, [
            'override' => true,
            'overridden_by' => $by->name,
            'failed_checks' => $failed,
            'checks' => $checks,
        ]);
    }

    /** Dispatcher selects a specific technician; only fully eligible technicians are allowed (AC #5). */
    public function dispatchToTechnician(Outage $outage, User $technician, User $by, ?int $teamId, ?int $subTeamId, ?string $reason): OutageAssignment
    {
        return DB::transaction(function () use ($outage, $technician, $by, $teamId, $subTeamId, $reason): OutageAssignment {
            $entry = $this->enqueueWithoutProcessing($outage, QueueType::Technician, ['team_id' => $teamId, 'sub_team_id' => $subTeamId]);
            $entry->loadMissing(['queueDefinition', 'outage.priority', 'outage.olts:id', 'outage.services:id']);

            $this->evaluator->beginRun();
            $row = $this->evaluator->evaluateAll($entry)->first(fn (array $row) => $row['user']->id === $technician->id);

            if (! $row || ! $row['eligible']) {
                $failed = $row ? collect($row['checks'])->reject(fn ($c) => $c['passed'])->pluck('label')->implode(', ') : 'not in the eligible population';

                throw ValidationException::withMessages(['technician_id' => "{$technician->name} is not eligible ({$failed})."]);
            }

            return $this->assign($entry, $technician, AssignmentType::Dispatch, $by, $reason ?: 'Selected by dispatcher', [
                'checks' => $row['checks'],
                'selected_by' => $by->name,
            ]);
        });
    }

    /** Mark the open entry of a queue as done once the workflow step is complete. */
    public function complete(Outage $outage, QueueType $type, ?User $by, string $note = 'Workflow step completed'): void
    {
        $entries = $outage->openQueueEntries()->where('queue_type', $type->value)->get();

        foreach ($entries as $entry) {
            foreach ($entry->assignments()->active()->get() as $assignment) {
                $status = $by && $assignment->user_id !== $by->id ? AssignmentStatus::Released : AssignmentStatus::Completed;
                $this->closeAssignment($assignment, $status, $status === AssignmentStatus::Released ? "Actioned by {$by->name}" : $note, $by, $status === AssignmentStatus::Completed ? 'completed' : 'released');
            }

            $entry->forceFill(['status' => QueueEntryStatus::Completed, 'completed_at' => now()])->save();
        }

        if ($entries->isNotEmpty() && ! $type->isParallel()) {
            $outage->forceFill(['current_queue' => null, 'current_assignee_id' => null])->save();
        }
    }

    /** Close every open queue item (outage resolved / rejected). History is retained. */
    public function cancelAll(Outage $outage, string $reason, ?User $by): void
    {
        foreach ($outage->openQueueEntries()->get() as $entry) {
            foreach ($entry->assignments()->active()->get() as $assignment) {
                $this->closeAssignment($assignment, AssignmentStatus::Completed, $reason, $by, 'completed');
            }

            $entry->forceFill(['status' => QueueEntryStatus::Cancelled, 'completed_at' => now()])->save();
        }

        $outage->forceFill(['current_queue' => null, 'current_assignee_id' => null])->save();
    }

    /** PriorityChanged: re-rank open queue items so FIFO order is recalculated. */
    public function refreshPriority(Outage $outage): void
    {
        $outage->loadMissing('priority');

        $outage->openQueueEntries()->update([
            'priority_id' => $outage->priority_id,
            'priority_rank' => $outage->priority?->queue_rank ?? 999,
            'updated_at' => now(),
        ]);

        event(new PriorityChanged($outage->id, $outage->ticket_number, [], ['priority' => $outage->priority?->name]));

        $this->scheduleProcessing();
    }

    public function setAvailability(User $user, AvailabilityStatus $status, ?User $by, ?string $reason = null): void
    {
        DB::transaction(function () use ($user, $status, $by, $reason): void {
            $previous = $user->availability_status;

            if ($previous === $status) {
                return;
            }

            $now = now();

            UserAvailability::query()
                ->where('user_id', $user->id)
                ->whereNull('effective_to')
                ->update(['effective_to' => $now, 'updated_at' => $now]);

            UserAvailability::query()->create([
                'user_id' => $user->id,
                'availability_status' => $status,
                'effective_from' => $now,
                'reason' => $reason,
                'updated_by' => $by?->id,
            ]);

            $user->forceFill(['availability_status' => $status, 'availability_changed_at' => $now])->save();

            $mode = $this->settings->get('fifo.requeue_on_unavailable');

            if (! in_array($status, [AvailabilityStatus::Available, AvailabilityStatus::Busy], true) && $mode !== 'none') {
                $statuses = $mode === 'all' ? AssignmentStatus::activeValues() : [AssignmentStatus::Assigned->value];

                foreach ($user->assignments()->whereIn('status', $statuses)->get() as $assignment) {
                    $this->release($assignment, 'User became '.$status->getLabel(), $by);
                }
            }

            event(new TechnicianAvailabilityChanged(null, null, [$user->id], ['status' => $status->value]));

            $this->scheduleProcessing();
        });
    }

    /**
     * Enforce FIFO ownership on workflow steps (AC #34): when a step's ticket is assigned,
     * only its assignee or an override holder may work it. Acting implicitly accepts.
     */
    public function authorizeWork(Outage $outage, QueueType $type, User $user): void
    {
        if (! $this->enabled() || ! $this->settings->bool('fifo.enforce_assignee')) {
            return;
        }

        $entry = $outage->openQueueEntries()->where('queue_type', $type->value)->first();

        if (! $entry) {
            return;
        }

        $assignment = $entry->activeAssignment()->first();

        if ($assignment && $assignment->user_id === $user->id) {
            if ($assignment->status === AssignmentStatus::Assigned) {
                $this->accept($assignment, $user);
            }

            return;
        }

        if ($user->can($type->overridePermission())) {
            return;
        }

        $owner = $assignment?->user?->name;

        throw new AuthorizationException($owner
            ? "This ticket is assigned to {$owner} in the {$type->getLabel()} queue."
            : "This ticket is waiting in the {$type->getLabel()} FIFO queue and has not been assigned to you.");
    }

    // ------------------------------------------------------------------ read models

    /**
     * Operational queue view (spec §27.6): position, age and assignee / next eligible user.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function snapshot(QueueType $type, array $filters = [], int $previewLimit = 25): Collection
    {
        $entries = OutageQueueEntry::query()
            ->open()
            ->where('queue_type', $type->value)
            ->when($filters['department_id'] ?? null, fn ($q, $v) => $q->where('department_id', $v))
            ->when($filters['team_id'] ?? null, fn ($q, $v) => $q->where('team_id', $v))
            ->when($filters['sub_team_id'] ?? null, fn ($q, $v) => $q->where('sub_team_id', $v))
            ->when($filters['priority_id'] ?? null, fn ($q, $v) => $q->where('priority_id', $v))
            ->when($filters['outage_type_id'] ?? null, fn ($q, $v) => $q->whereHas('outage', fn ($o) => $o->where('outage_type_id', $v)))
            ->when($filters['vertical_id'] ?? null, fn ($q, $v) => $q->whereHas('outage', fn ($o) => $o->where('vertical_id', $v)))
            ->with([
                'queueDefinition', 'priority', 'team:id,name', 'subTeam:id,name', 'activeAssignment.user:id,name',
                'outage' => fn ($q) => $q->with(['priority', 'status', 'olts:id', 'services:id']),
            ])
            ->fifoOrder($this->settings->bool('fifo.priority_aware'))
            ->limit(500)
            ->get();

        $this->evaluator->beginRun();
        $pendingPosition = 0;
        $previewed = 0;

        return $entries->map(function (OutageQueueEntry $entry) use (&$pendingPosition, &$previewed, $previewLimit): array {
            $isPending = $entry->status === QueueEntryStatus::Pending;
            $next = null;

            if ($isPending) {
                $pendingPosition++;

                if ($entry->auto_assign_blocked) {
                    $next = 'Held for manual assignment';
                } elseif ($previewed < $previewLimit) {
                    $previewed++;
                    $decision = $this->evaluator->decide($entry);
                    $next = $decision['user']?->name ?? 'No eligible user available';
                    if ($decision['user']) {
                        $this->evaluator->recordAssignment($decision['user']->id);
                    }
                }
            }

            return [
                'entry' => $entry,
                'position' => $isPending ? $pendingPosition : null,
                'age_minutes' => (int) $entry->queued_at->diffInMinutes(now()),
                'assigned_to' => $entry->activeAssignment?->user?->name,
                'assignment' => $entry->activeAssignment,
                'next_eligible' => $next,
            ];
        });
    }

    /** Rule-by-rule explanation for a queue entry, all candidates included. */
    public function explain(OutageQueueEntry $entry): Collection
    {
        $entry->loadMissing(['queueDefinition', 'outage.priority', 'outage.olts:id', 'outage.services:id']);
        $this->evaluator->beginRun();

        return $this->evaluator->evaluateAll($entry, includeInactive: true);
    }

    // ------------------------------------------------------------------ internals

    /**
     * Run queue processing once the current transaction commits. Failures are reported but
     * never bubble up: the user's action already succeeded and the scheduler sweep will retry.
     */
    private function scheduleProcessing(?QueueType $type = null): void
    {
        DB::afterCommit(function () use ($type): void {
            try {
                $this->process($type);
            } catch (\Throwable $exception) {
                report($exception);
            }
        });
    }

    private function enqueueWithoutProcessing(Outage $outage, QueueType $type, array $options = []): OutageQueueEntry
    {
        $existing = $outage->openQueueEntries()->where('queue_type', $type->value)->first();

        if ($existing) {
            $existing->forceFill(array_filter([
                'team_id' => $options['team_id'] ?? null,
                'sub_team_id' => $options['sub_team_id'] ?? null,
            ]))->save();

            return $existing;
        }

        $outage->loadMissing('priority');
        $definition = $this->resolveDefinition($outage, $type);

        $entry = OutageQueueEntry::query()->create([
            'outage_id' => $outage->id,
            'queue_definition_id' => $definition?->id,
            'queue_type' => $type,
            'priority_id' => $outage->priority_id,
            'priority_rank' => $outage->priority?->queue_rank ?? 999,
            'department_id' => $definition?->department_id ?? Department::query()->where('code', $type->defaultDepartmentCode())->value('id'),
            'team_id' => $options['team_id'] ?? $definition?->team_id,
            'sub_team_id' => $options['sub_team_id'] ?? null,
            'queued_at' => now(),
            'status' => QueueEntryStatus::Pending,
        ]);

        $this->deskSla->stamp($entry, $outage);

        if (! $type->isParallel()) {
            $outage->forceFill(['current_queue' => $type])->save();
        }
        $this->history($outage, null, $type, null, null, 'queued', 'Placed in '.$type->getLabel().' queue');

        return $entry;
    }

    private function returnToQueue(OutageAssignment $assignment, ?int $excludeUserId, bool $requeue, string $reason): ?OutageQueueEntry
    {
        $entry = OutageQueueEntry::query()->lockForUpdate()->find($assignment->queue_entry_id);

        if (! $entry || ! in_array($entry->status->value, QueueEntryStatus::openValues(), true)) {
            return $entry;
        }

        $excluded = $entry->rejected_user_ids ?? [];
        if ($excludeUserId && ! in_array($excludeUserId, $excluded, true)) {
            $excluded[] = $excludeUserId;
        }

        $attempts = $entry->attempts + ($excludeUserId ? 1 : 0);
        $threshold = $entry->queueDefinition?->escalate_after_attempts ?: $this->settings->int('fifo.escalate_after_attempts');

        // Original queued_at is preserved: a returned ticket keeps its place in line.
        $entry->forceFill([
            'status' => QueueEntryStatus::Pending,
            'assigned_at' => null,
            'rejected_user_ids' => $excluded,
            'attempts' => $attempts,
            'auto_assign_blocked' => ! $requeue,
        ])->save();

        $outage = $assignment->outage;
        if (! $entry->queue_type->isParallel()) {
            $outage->forceFill(['current_assignee_id' => null])->save();
        }

        if (! $requeue || ($threshold > 0 && $attempts >= $threshold && ! $entry->escalated_at)) {
            $entry->forceFill(['escalated_at' => now()])->save();

            $this->notifier->notifyUsers(NotificationEvent::QueueEscalation, $outage, $this->notifier->teamLeaders($outage), [NotificationChannel::InApp->value, NotificationChannel::Mail->value], [
                'reason' => $requeue ? "Repeated rejection/timeout ({$attempts} attempts): {$reason}" : "Held for manual assignment: {$reason}",
                'attempts' => $attempts,
            ]);
            $this->notifier->dispatch(NotificationEvent::QueueEscalation, $outage, ['reason' => $reason, 'attempts' => $attempts]);
        }

        event(new OutageUpdated($outage->id, $outage->ticket_number));

        if ($requeue) {
            $this->scheduleProcessing($entry->queue_type);
        }

        return $entry;
    }

    private function closeAssignment(OutageAssignment $assignment, AssignmentStatus $status, string $reason, ?User $by, string $action): void
    {
        $column = match ($status) {
            AssignmentStatus::Completed => 'completed_at',
            AssignmentStatus::Rejected => 'rejected_at',
            AssignmentStatus::TimedOut => 'timed_out_at',
            default => 'released_at',
        };

        $assignment->forceFill(['status' => $status, $column => now(), 'release_reason' => $reason])->save();

        $this->history($assignment->outage, $assignment, $assignment->queue_type, $assignment->user_id, null, $action, $reason, $by);
    }

    private function history(Outage $outage, ?OutageAssignment $assignment, QueueType $type, ?int $previousUserId, ?int $newUserId, string $action, ?string $reason, ?User $by = null): void
    {
        OutageAssignmentHistory::query()->create([
            'outage_id' => $outage->id,
            'assignment_id' => $assignment?->id,
            'queue_type' => $type,
            'previous_user_id' => $previousUserId,
            'new_user_id' => $newUserId,
            'action' => $action,
            'reason' => $reason,
            'performed_by' => $by?->id,
            'trigger' => AuditContext::trigger(),
            'performed_at' => now(),
        ]);
    }

    private function ackTimeoutMinutes(OutageQueueEntry $entry, Outage $outage): int
    {
        return (int) ($outage->slaPolicy?->ack_timeout_minutes
            ?? $entry->queueDefinition?->ack_timeout_minutes
            ?? $this->settings->int('fifo.ack_timeout_minutes'));
    }

    private function positionOf(OutageQueueEntry $entry): int
    {
        $priorityAware = $this->settings->bool('fifo.priority_aware');

        return OutageQueueEntry::query()
            ->pending()
            ->where('queue_type', $entry->queue_type->value)
            ->where(function ($q) use ($entry, $priorityAware): void {
                if ($priorityAware) {
                    $q->where('priority_rank', '<', $entry->priority_rank)
                        ->orWhere(fn ($same) => $same->where('priority_rank', $entry->priority_rank)->where('queued_at', '<', $entry->queued_at));
                } else {
                    $q->where('queued_at', '<', $entry->queued_at);
                }
            })
            ->count() + 1;
    }

    private function specificity(QueueDefinition $definition): int
    {
        return ($definition->major_outage_type_id ? 2 : 0) + ($definition->vertical_id ? 1 : 0);
    }
}
