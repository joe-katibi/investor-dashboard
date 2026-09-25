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
}
