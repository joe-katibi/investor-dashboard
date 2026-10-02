<?php

namespace App\Policies;

use App\Enums\StatusCode;
use App\Models\ChangeRequest;
use App\Models\MaintenanceApproval;
use App\Models\MajorOutageType;
use App\Models\Outage;
use App\Models\OutageStatus;
use App\Models\OutageType;
use App\Models\SupportRequest;
use App\Models\User;

/**
 * Permission + state checks for every workflow transition (spec §9: transitions are
 * permission-controlled). The same policy guards the UI and the workflow service.
 */
class OutagePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('outage.view');
    }

    /** 4G NOC tickets are a separate audience from the FTTH / core / system tickets. */
    public function view(User $user, Outage $outage): bool
    {
        return $outage->workflow !== null ? $user->can('mobility.view') : $user->can('outage.view');
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
        return $user->can('queue.override') && ! $outage->is_closed && $outage->workflow === null;
    }

    // ------------------------------------------------------------------ 4G NOC

    public function createMobility(User $user): bool
    {
        return $user->can('mobility.create');
    }

    public function mobilityEdit(User $user, Outage $outage): bool
    {
        return $outage->workflow !== null && ! $outage->is_closed && $user->can('mobility.edit');
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
        return $outage->workflow !== null
            && ! $outage->is_closed
            && $user->can('mobility.verify')
            && $this->statusIs($outage, StatusCode::MobilityResolvedPendingNoc, StatusCode::MobilityVendorResolvedPendingNoc);
    }

    public function mobilitySetStatus(User $user, Outage $outage): bool
    {
        return $outage->workflow !== null
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
        return $outage->workflow !== null && $outage->is_closed && $user->can('mobility.reopen');
    }

    public function mobilityCancel(User $user, Outage $outage): bool
    {
        return $outage->workflow !== null && ! $outage->is_closed && $user->can('mobility.cancel');
    }

    public function mobilityEscalate(User $user, Outage $outage): bool
    {
        return $outage->workflow !== null && ! $outage->is_closed && $user->can('mobility.escalate');
    }

    public function mobilityAttach(User $user, Outage $outage): bool
    {
        return $outage->workflow !== null && $this->addUpdate($user, $outage);
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
