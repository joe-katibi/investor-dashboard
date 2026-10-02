<?php

namespace App\Enums;

/**
 * Stable codes of the workflow statuses the engine drives. Names, colours and flags
 * remain editable in the UI; only the code is fixed.
 */
enum StatusCode: string
{
    case SupportUnconfirmed = 'support_unconfirmed';
    case NocConfirmed = 'noc_confirmed';
    case NocReject = 'noc_reject';
    case AssignedToInfrastructure = 'assigned_to_infrastructure';
    case TeamDispatched = 'team_dispatched';
    case RestoreReported = 'restore_reported';
    case NocRestoreConfirmed = 'noc_restore_confirmed';
    case Resolved = 'outage_resolved';
    case ScheduledMaintenance = 'scheduled_maintenance';
    case PartialRestore = 'partial_restore';
    case AwaitingFieldAccess = 'awaiting_field_access';
    case VendorEscalated = 'vendor_escalated';
    case AwaitingCustomerConfirmation = 'awaiting_customer_confirmation';
    case InfrastructureConfirmed = 'infrastructure_confirmed';

    // NOC rejected: Customer Experience (Tier II) calls the customer back / raises a site visit.
    case CxFollowUp = 'cx_follow_up';

    // System outages: IT reviews first, may escalate to NOC, and confirms the fix at the end.
    case ItReview = 'it_review';
    case EscalatedToNoc = 'escalated_to_noc';
    case AwaitingItConfirmation = 'awaiting_it_confirmation';

    // Planned maintenance: Infrastructure request → NOC CRF → sign-off → normal field work → CX tells customers.
    case MaintenanceRequested = 'maintenance_requested';
    case CrfPendingSignoff = 'crf_pending_signoff';
    case CxCustomerNotification = 'cx_customer_notification';
    case MaintenanceCancelled = 'maintenance_cancelled';

    // Preventive maintenance raised on site: Dispatcher + Infrastructure Manager approve the downtime.
    case PmPendingApproval = 'pm_pending_approval';
    case PmRejected = 'pm_rejected';

    // 4G NOC: NOC → engineer / technician → NOC, or NOC → external vendor → NOC. Only NOC closes.
    case MobilityNew = 'mobility_new';
    case MobilityOpen = 'mobility_open';
    case MobilityAssigned = 'mobility_assigned';
    case MobilityInProgress = 'mobility_in_progress';
    case MobilityMonitoring = 'mobility_monitoring';
    case MobilityResolvedPendingNoc = 'mobility_resolved_pending_noc';
    case MobilityVendorAssigned = 'mobility_vendor_assigned';
    case MobilityVendorNotified = 'mobility_vendor_notified';
    case MobilityVendorAcknowledged = 'mobility_vendor_acknowledged';
    case MobilityVendorInvestigating = 'mobility_vendor_investigating';
    case MobilityVendorResolvedPendingNoc = 'mobility_vendor_resolved_pending_noc';
    case MobilityClosed = 'mobility_closed';
    case MobilityCancelled = 'mobility_cancelled';

    /** Resolved by the engineer / vendor and waiting for the NOC to verify and close. */
    public function isMobilityPendingVerification(): bool
    {
        return in_array($this, [self::MobilityResolvedPendingNoc, self::MobilityVendorResolvedPendingNoc], true);
    }
}
