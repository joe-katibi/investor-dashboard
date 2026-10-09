<?php

namespace App\Policies;

use App\Enums\QueueType;
use App\Enums\StatusCode;
use App\Models\ChangeRequest;
use App\Models\DeskRoute;
use App\Models\MaintenanceApproval;
use App\Models\MajorOutageType;
use App\Models\Outage;
use App\Models\OutageStatus;
use App\Models\OutageType;
use App\Models\SupportRequest;
use App\Models\Ticket;
use App\Models\User;

/**
 * Permission + state checks for every workflow transition (spec §9: transitions are
 * permission-controlled). The same policy guards the UI and the workflow service.
 */
class OutagePolicy
{
    /** What applies to a ticket of a custom workflow; every other ability of this policy is for other kinds of ticket. */
    private const CUSTOM_ABILITIES = ['view', 'addUpdate', 'workCustomStep', 'cancelCustom', 'overrideAssignment'];

    /**
     * A ticket of a custom workflow only knows its own actions: none of the outage, 4G or customer
     * ticket transitions apply to it, whatever permissions the person holds.
     */
    public function before(User $user, string $ability, mixed ...$arguments): ?bool
    {
        $outage = $arguments[0] ?? null;

        if ($outage instanceof Outage && $outage->isCustomFlow() && ! in_array($ability, self::CUSTOM_ABILITIES, true)) {
            return false;
        }

        return null;
    }

    // ------------------------------------------------------------------ custom workflows

    public function raiseCustom(User $user): bool
    {
        return $user->can('custom.raise');
    }

    /** The step is worked by whoever holds the ticket — or someone who may override FIFO. */
    public function workCustomStep(User $user, Outage $outage): bool
    {
        return $outage->isCustomFlow() && ! $outage->is_closed
            && ((int) $outage->current_assignee_id === (int) $user->id || $user->can('queue.override'));
    }

    public function cancelCustom(User $user, Outage $outage): bool
    {
        return $outage->isCustomFlow() && ! $outage->is_closed
            && ($user->can('custom.cancel') || ((int) $outage->created_by === (int) $user->id && $outage->current_assignee_id === null));
    }

    /** Everyone with "custom.view"; otherwise the raiser, anyone who holds or held it, and the team of its current step. */
    private function viewCustom(User $user, Outage $outage): bool
    {
        return $user->can('custom.view')
            || (int) $outage->created_by === (int) $user->id
            || $outage->assignments()->where('user_id', $user->id)->exists()
            || $outage->openQueueEntries()->whereIn('team_id', $user->teams()->pluck('teams.id'))->exists();
    }

    public function viewAny(User $user): bool
    {
        return $user->can('outage.view');
    }

    /** 4G NOC tickets are a separate audience from the FTTH / core / system tickets. */
    public function view(User $user, Outage $outage): bool
    {
        return match (true) {
            $outage->isTicket() => $user->can('ticket.view'),
            $outage->isCustomFlow() => $this->viewCustom($user, $outage),
            $outage->isMobility() => $user->can('mobility.view'),
            default => $user->can('outage.view'),
        };
    }

    public function create(User $user): bool
    {
        return $user->can('outage.create');
    }

    public function update(User $user, Outage $outage): bool
    {
        return $user->can('outage.edit') && ! $outage->is_closed && $outage->workflow === null;
    }

    public function delete(User $user, Outage $outage): bool
    {
        return $user->can('outage.delete');
    }

    public function deleteAny(User $user): bool
    {
        return $user->can('outage.delete');
    }

    /** NOC confirm / reject. */
    public function confirm(User $user, Outage $outage): bool
    {
        return $user->can('outage.confirm')
            && ! $outage->is_closed
            && $this->statusIs($outage, StatusCode::SupportUnconfirmed);
    }

    public function dispatch(User $user, Outage $outage): bool
    {
        return $user->can('outage.dispatch')
            && ! $outage->is_closed
            // Field maintenance is raised on site, not NOC-confirmed; it can still be re-dispatched.
            && ($outage->noc_confirmed_at !== null || ($outage->maintenance_kind !== null && $outage->dispatched_at !== null))
            && MajorOutageType::flags($outage->major_outage_type_id)['requires_infrastructure']
            // Planned maintenance is only dispatched once the CRF is fully signed.
            && ! $this->statusIs($outage, StatusCode::RestoreReported, StatusCode::NocRestoreConfirmed, StatusCode::MaintenanceRequested,
                StatusCode::CrfPendingSignoff, StatusCode::CxCustomerNotification);
    }

    public function addUpdate(User $user, Outage $outage): bool
    {
        if ($outage->isTicket()) {
            return ! $outage->is_closed && $user->canAny(['ticket.work', 'visit.dispatch', 'visit.work', 'ticket.create', 'ticket.intervene']);
        }

        // Custom workflows: whoever may see the ticket can add to its time line while it is open.
        if ($outage->isCustomFlow()) {
            return ! $outage->is_closed && $this->viewCustom($user, $outage);
        }

        if ($outage->workflow !== null) {
            return $outage->is_closed
                ? $user->can('mobility.verify')
                : $user->canAny(['mobility.work', 'mobility.vendor_update', 'mobility.verify', 'mobility.create']);
        }

        return $outage->is_closed ? $user->can('outage.edit') : $user->can('outage.update_progress');
    }

    public function reportRestored(User $user, Outage $outage): bool
    {
        $ready = MajorOutageType::flags($outage->major_outage_type_id)['requires_infrastructure']
            ? $outage->dispatched_at !== null
            : $outage->noc_confirmed_at !== null;

        return $user->can('outage.update_restoration')
            && ! $outage->is_closed
            && $ready
            && ! $this->statusIs($outage, StatusCode::RestoreReported, StatusCode::NocRestoreConfirmed, StatusCode::SupportUnconfirmed,
                StatusCode::MaintenanceRequested, StatusCode::CrfPendingSignoff, StatusCode::CxCustomerNotification);
    }

    /** The site photos are added by whoever will report the outage restored, while the work is going on. */
    public function addSitePhotos(User $user, Outage $outage): bool
    {
        return $outage->offersSitePhotos() && $this->reportRestored($user, $outage);
    }

    public function confirmRestoration(User $user, Outage $outage): bool
    {
        return $user->can('outage.confirm_restoration')
            && ! $outage->is_closed
            && $this->statusIs($outage, StatusCode::RestoreReported);
    }

    public function resolve(User $user, Outage $outage): bool
    {
        return $user->can('outage.close')
            && ! $outage->is_closed
            && $this->statusIs($outage, StatusCode::NocRestoreConfirmed);
    }

    public function changeStatus(User $user, Outage $outage): bool
    {
        return $user->can('outage.change_status') && ! $outage->is_closed && $outage->workflow === null;
    }

    public function updateFlags(User $user, Outage $outage): bool
    {
        return $user->can('outage.update_credit_sms') && $outage->workflow === null;
    }

    public function overrideAssignment(User $user, Outage $outage): bool
    {
        return $user->can('queue.override') && ! $outage->is_closed && ($outage->workflow === null || $outage->isCustomFlow());
    }

    // ------------------------------------------------------------------ 4G NOC

    public function createMobility(User $user): bool
    {
        return $user->can('mobility.create');
    }

    public function mobilityEdit(User $user, Outage $outage): bool
    {
        return $outage->isMobility() && ! $outage->is_closed && $user->can('mobility.edit');
    }

    /** NOC assigns (or re-assigns) the engineer / technician of an internal ticket. */
    public function mobilityAssign(User $user, Outage $outage): bool
    {
        return $outage->workflow === OutageType::WORKFLOW_MOBILITY_INTERNAL
            && ! $outage->is_closed
            && $user->can('mobility.assign')
            && $this->statusIs($outage, StatusCode::MobilityNew, StatusCode::MobilityOpen, StatusCode::MobilityAssigned,
                StatusCode::MobilityInProgress, StatusCode::MobilityMonitoring);
    }

    /** The assigned engineer accepts the ticket and starts investigating. */
    public function mobilityStart(User $user, Outage $outage): bool
    {
        return $outage->workflow === OutageType::WORKFLOW_MOBILITY_INTERNAL
            && ! $outage->is_closed
            && $user->can('mobility.work')
            && $this->statusIs($outage, StatusCode::MobilityAssigned);
    }

    /** Engineer / technician: "Resolved – Pending NOC Confirmation". They can never close. */
    public function mobilityResolve(User $user, Outage $outage): bool
    {
        return $outage->workflow === OutageType::WORKFLOW_MOBILITY_INTERNAL
            && ! $outage->is_closed
            && $user->can('mobility.work')
            && $this->statusIs($outage, StatusCode::MobilityNew, StatusCode::MobilityOpen, StatusCode::MobilityAssigned,
                StatusCode::MobilityInProgress, StatusCode::MobilityMonitoring);
    }

    /** NOC verifies the restoration and closes (or sends the ticket back). */
    public function mobilityClose(User $user, Outage $outage): bool
    {
        return $outage->isMobility()
            && ! $outage->is_closed
            && $user->can('mobility.verify')
            && $this->statusIs($outage, StatusCode::MobilityResolvedPendingNoc, StatusCode::MobilityVendorResolvedPendingNoc);
    }

    public function mobilitySetStatus(User $user, Outage $outage): bool
    {
        return $outage->isMobility()
            && ! $outage->is_closed
            && $user->canAny(['mobility.verify', 'mobility.work'])
            && ! $this->statusIs($outage, StatusCode::MobilityResolvedPendingNoc, StatusCode::MobilityVendorResolvedPendingNoc);
    }

    /** NOC captures what the vendor reported (vendors never use the system). */
    public function mobilityVendorUpdate(User $user, Outage $outage): bool
    {
        return $outage->workflow === OutageType::WORKFLOW_MOBILITY_VENDOR
            && ! $outage->is_closed
            && $outage->vendor_id !== null
            && $user->can('mobility.vendor_update');
    }

    public function mobilityNotifyVendor(User $user, Outage $outage): bool
    {
        return $this->mobilityVendorUpdate($user, $outage)
            && ! $this->statusIs($outage, StatusCode::MobilityVendorResolvedPendingNoc);
    }

    public function mobilityChangeVendor(User $user, Outage $outage): bool
    {
        return $outage->workflow === OutageType::WORKFLOW_MOBILITY_VENDOR
            && ! $outage->is_closed
            && $user->can('mobility.assign')
            && ! $this->statusIs($outage, StatusCode::MobilityVendorResolvedPendingNoc);
    }

    public function mobilityReopen(User $user, Outage $outage): bool
    {
        return $outage->isMobility() && $outage->is_closed && $user->can('mobility.reopen');
    }

    public function mobilityCancel(User $user, Outage $outage): bool
    {
        return $outage->isMobility() && ! $outage->is_closed && $user->can('mobility.cancel');
    }

    public function mobilityEscalate(User $user, Outage $outage): bool
    {
        return $outage->isMobility() && ! $outage->is_closed && $user->can('mobility.escalate');
    }

    public function mobilityAttach(User $user, Outage $outage): bool
    {
        return $outage->isMobility() && $this->addUpdate($user, $outage);
    }

    // ------------------------------------------------------------------ customer tickets / site visits

    public function createTicket(User $user): bool
    {
        return $user->can('ticket.create');
    }

    public function createVisit(User $user): bool
    {
        return $user->can('visit.create');
    }

    /** Hand the ticket to another desk. Anyone working tickets may; the FIFO owner check is in the workflow. */
    public function ticketRoute(User $user, Outage $outage): bool
    {
        return $this->openTicketAtDesk($outage)
            && ! $outage->current_queue->isFieldDesk()
            && $user->canAny($outage->isSiteVisit() ? ['visit.dispatch', 'ticket.work'] : ['ticket.work'])
            && DeskRoute::targets($outage->current_queue) !== [];
    }

    /** Send it back to the desk it came from. */
    public function ticketReturn(User $user, Outage $outage): bool
    {
        return $this->openTicketAtDesk($outage)
            && $outage->current_queue !== QueueType::VisitTechnician
            && $outage->current_queue !== $outage->ticket?->home_desk
            && $user->canAny(['ticket.work', 'visit.dispatch', 'visit.work']);
    }

    /** Approve / decline a refund, credit or billing request waiting at an approval desk. */
    public function ticketApprove(User $user, Outage $outage): bool
    {
        return $this->openTicketAtDesk($outage)
            && $outage->isCxTicket()
            && $outage->current_queue->isApprovalDesk()
            && $outage->ticket?->approval_state === Ticket::APPROVAL_PENDING
            && $user->can('ticket.approve');
    }

    /** Log a call to the customer. */
    public function ticketFollowUp(User $user, Outage $outage): bool
    {
        return $outage->isTicket() && ! $outage->is_closed && $user->canAny(['ticket.work', 'visit.dispatch']);
    }

    /** Book a site visit for a customer ticket (the ticket then waits for the visit). */
    public function ticketRaiseVisit(User $user, Outage $outage): bool
    {
        return $this->openTicketAtDesk($outage)
            && $outage->isCxTicket()
            && $user->can('visit.create')
            && $user->can('ticket.work');
    }

    /**
     * Close with a final reason: a customer ticket at its desk, or a site visit closed without
     * field work by the dispatcher / CX Field Support.
     */
    public function ticketClose(User $user, Outage $outage): bool
    {
        if (! $this->openTicketAtDesk($outage)) {
            return false;
        }

        if ($outage->isSiteVisit()) {
            return in_array($outage->current_queue, [QueueType::VisitDispatch, QueueType::CxFieldSupport], true)
                && $outage->ticket?->completed_at === null
                && $user->canAny(['visit.dispatch', 'ticket.work']);
        }

        // A request still waiting for approval is approved or declined first.
        return $user->can('ticket.work')
            && ! ($outage->current_queue->isApprovalDesk() && $outage->ticket?->approval_state === Ticket::APPROVAL_PENDING);
    }

    public function ticketCancel(User $user, Outage $outage): bool
    {
        return $outage->isTicket() && ! $outage->is_closed && $user->can('ticket.cancel');
    }

    public function ticketReopen(User $user, Outage $outage): bool
    {
        return $outage->isTicket() && $outage->is_closed && $user->can('ticket.reopen');
    }

    /** Team leader takes the ticket over at its desk. */
    public function ticketIntervene(User $user, Outage $outage): bool
    {
        return $this->openTicketAtDesk($outage)
            && $user->can('ticket.intervene')
            && ! $this->interventionRunning($outage);
    }

    public function ticketEndIntervention(User $user, Outage $outage): bool
    {
        return $outage->isTicket()
            && ! $outage->is_closed
            && $this->interventionRunning($outage)
            && ((int) $outage->ticket?->intervention_by === (int) $user->id || $user->can('queue.override'));
    }

    public function ticketLinkOutage(User $user, Outage $outage): bool
    {
        return $outage->isTicket() && ! $outage->is_closed && $user->canAny(['ticket.work', 'ticket.create', 'visit.dispatch']);
    }

    public function ticketAttach(User $user, Outage $outage): bool
    {
        return $outage->isTicket() && $this->addUpdate($user, $outage);
    }

    /** Dispatcher assigns (or re-assigns) the technician of a visit. */
    public function visitAssign(User $user, Outage $outage): bool
    {
        return $outage->isSiteVisit()
            && ! $outage->is_closed
            && in_array($outage->current_queue, [QueueType::VisitDispatch, QueueType::VisitTechnician], true)
            && $user->can('visit.dispatch');
    }

    /** New date / time for the visit: dispatch, or CX Field Support while it holds the visit. */
    public function visitReschedule(User $user, Outage $outage): bool
    {
        return $outage->isSiteVisit()
            && ! $outage->is_closed
            && $outage->ticket?->completed_at === null
            && ($user->can('visit.dispatch') || ($outage->current_queue === QueueType::CxFieldSupport && $user->can('ticket.work')));
    }

    public function visitEnRoute(User $user, Outage $outage): bool
    {
        return $this->visitInField($user, $outage) && $outage->ticket?->en_route_at === null && $outage->ticket?->arrived_at === null;
    }

    public function visitArrive(User $user, Outage $outage): bool
    {
        return $this->visitInField($user, $outage) && $outage->ticket?->arrived_at === null;
    }

    public function visitPause(User $user, Outage $outage): bool
    {
        return $this->visitInField($user, $outage) && $outage->ticket?->arrived_at !== null && $outage->ticket?->paused_at === null;
    }

    public function visitResume(User $user, Outage $outage): bool
    {
        return $this->visitInField($user, $outage) && $outage->ticket?->paused_at !== null;
    }

    /** Technician: work done (final reason, FAT number, photos). Back to the dispatcher. */
    public function visitComplete(User $user, Outage $outage): bool
    {
        return $this->visitInField($user, $outage) && $outage->current_queue === QueueType::VisitTechnician;
    }

    public function visitNotCompleted(User $user, Outage $outage): bool
    {
        return $this->visitComplete($user, $outage);
    }

    /** Dispatcher's final close (or rejection) of the completed work. */
    public function visitVerify(User $user, Outage $outage): bool
    {
        return $outage->isSiteVisit()
            && ! $outage->is_closed
            && $outage->current_queue === QueueType::VisitDispatch
            && $outage->ticket?->completed_at !== null
            && $user->can('visit.dispatch');
    }

    /** CX Field Support confirms the closure with the customer. */
    public function visitConfirm(User $user, Outage $outage): bool
    {
        return $outage->isSiteVisit()
            && ! $outage->is_closed
            && $outage->current_queue === QueueType::CxFieldSupport
            && $outage->ticket?->verified_at !== null
            && $user->can('ticket.work');
    }

    private function openTicketAtDesk(Outage $outage): bool
    {
        return $outage->isTicket() && ! $outage->is_closed && $outage->current_queue !== null && $outage->current_queue->isTicketing();
    }

    private function visitInField(User $user, Outage $outage): bool
    {
        return $outage->isSiteVisit()
            && ! $outage->is_closed
            && $outage->current_queue !== null
            && $outage->current_queue->isFieldDesk()
            && $outage->ticket?->completed_at === null
            && $user->can('visit.work');
    }

    private function interventionRunning(Outage $outage): bool
    {
        return $outage->ticket?->intervention_started_at !== null && $outage->ticket?->intervention_ended_at === null;
    }

    // ------------------------------------------------------------------ NOC reject → CX follow-up

    /** CX Tier II records the customer callback (and any site visit) on a NOC-rejected ticket. */
    public function cxFollowUp(User $user, Outage $outage): bool
    {
        return $user->can('outage.cx_follow_up')
            && ! $outage->is_closed
            && $this->statusIs($outage, StatusCode::CxFollowUp);
    }

    // ------------------------------------------------------------------ support technician (parallel)

    /** Field team asks for a support technician (drop cable → premises) on an ongoing, dispatched outage. */
    public function requestSupport(User $user, Outage $outage): bool
    {
        return $user->can('outage.request_support')
            && ! $outage->is_closed
            && $outage->dispatched_at !== null
            && ! $this->statusIs($outage, StatusCode::RestoreReported, StatusCode::NocRestoreConfirmed, StatusCode::CxCustomerNotification)
            && ! $this->hasOpenSupportRequest($outage);
    }

    public function completeSupport(User $user, Outage $outage): bool
    {
        return $user->can('outage.support_work')
            && ! $outage->is_closed
            && $this->hasOpenSupportRequest($outage);
    }

    public function cancelSupport(User $user, Outage $outage): bool
    {
        return ($user->can('outage.request_support') || $user->can('queue.override'))
            && ! $outage->is_closed
            && $this->hasOpenSupportRequest($outage);
    }

    // ------------------------------------------------------------------ system outages: IT first

    /** IT reviews a new system outage: resolve it or escalate to NOC. */
    public function itReview(User $user, Outage $outage): bool
    {
        return $user->can('outage.it_review')
            && ! $outage->is_closed
            && $this->statusIs($outage, StatusCode::ItReview);
    }

    /** IT confirms (or refuses) the fix NOC returned. */
    public function itConfirm(User $user, Outage $outage): bool
    {
        return $user->can('outage.it_review')
            && ! $outage->is_closed
            && $this->statusIs($outage, StatusCode::AwaitingItConfirmation);
    }

    /** NOC has fixed an IT-escalated issue and hands it back to IT. */
    public function returnToIt(User $user, Outage $outage): bool
    {
        return $user->can('outage.confirm')
            && ! $outage->is_closed
            && $this->statusIs($outage, StatusCode::EscalatedToNoc);
    }

    // ------------------------------------------------------------------ field maintenance (preventive / corrective)

    public function raiseFieldMaintenance(User $user): bool
    {
        return $user->can('field_maintenance.raise');
    }

    /** Dispatcher / Infrastructure Manager approval still open for this user (one approval per person). */
    public function approvePreventive(User $user, Outage $outage): bool
    {
        return ! $outage->is_closed
            && $this->statusIs($outage, StatusCode::PmPendingApproval)
            && MaintenanceApproval::openSlotFor($outage, $user) !== null;
    }

    public function rejectPreventive(User $user, Outage $outage): bool
    {
        return $this->approvePreventive($user, $outage);
    }

    // ------------------------------------------------------------------ planned maintenance

    public function requestMaintenance(User $user): bool
    {
        return $user->can('maintenance.request');
    }

    /** NOC completes the CRF and sends it for sign-off (also re-issues after "changes requested"). */
    public function prepareCrf(User $user, Outage $outage): bool
    {
        return $user->can('maintenance.manage')
            && ! $outage->is_closed
            && $this->statusIs($outage, StatusCode::MaintenanceRequested);
    }

    public function resendSignoff(User $user, Outage $outage): bool
    {
        return $user->can('maintenance.manage')
            && ! $outage->is_closed
            && $this->statusIs($outage, StatusCode::CrfPendingSignoff);
    }

    /** Until the field work is reported done, the window can move (on the day or before). */
    public function rescheduleMaintenance(User $user, Outage $outage): bool
    {
        return ($user->can('maintenance.reschedule') || $user->can('maintenance.manage'))
            && ! $outage->is_closed
            && $outage->restored_at === null
            && $this->isMaintenance($outage);
    }

    public function cancelMaintenance(User $user, Outage $outage): bool
    {
        return $user->can('maintenance.manage')
            && ! $outage->is_closed
            && $outage->restored_at === null
            && $this->isMaintenance($outage);
    }

    /** CX confirms customers were told the maintenance is complete (closes the ticket). */
    public function notifyCustomers(User $user, Outage $outage): bool
    {
        return $user->can('maintenance.notify_customers')
            && ! $outage->is_closed
            && $this->statusIs($outage, StatusCode::CxCustomerNotification);
    }

    public function viewCrf(User $user, Outage $outage): bool
    {
        return $user->can('outage.view') && $this->isMaintenance($outage);
    }

    private function isMaintenance(Outage $outage): bool
    {
        if ($outage->relationLoaded('changeRequest')) {
            return $outage->changeRequest !== null;
        }

        return ChangeRequest::query()->where('outage_id', $outage->id)->exists();
    }

    private function hasOpenSupportRequest(Outage $outage): bool
    {
        // Tables eager-load the relation so row actions don't query per row.
        if ($outage->relationLoaded('openSupportRequest')) {
            return $outage->openSupportRequest !== null;
        }

        return SupportRequest::query()->open()->where('outage_id', $outage->id)->exists();
    }

    private function statusIs(Outage $outage, StatusCode ...$codes): bool
    {
        // Resolve from status_id (never a possibly stale loaded relation).
        $code = OutageStatus::codeForId($outage->status_id);

        return in_array($code, array_map(fn (StatusCode $c) => $c->value, $codes), true);
    }
}
