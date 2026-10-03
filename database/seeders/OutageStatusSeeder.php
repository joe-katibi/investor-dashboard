<?php

namespace Database\Seeders;

use App\Enums\StatusCode;
use App\Models\OutageStatus;
use Illuminate\Database\Seeder;

/** Status catalogue (spec §10). Workflow statuses carry a stable code the engine relies on. */
class OutageStatusSeeder extends Seeder
{
    public function run(): void
    {
        // [code, name, colour, prompt final reason, closed, resolved, pauses SLA, manually selectable, sort]
        foreach ([
            [StatusCode::SupportUnconfirmed, 'Support Unconfirmed Outage', 'gray', false, false, false, false, false, 1],
            [StatusCode::NocConfirmed, 'NOC Confirmed Outage', 'info', false, false, false, false, false, 2],
            [StatusCode::NocReject, 'NOC Reject', 'danger', true, true, false, false, false, 3],
            [StatusCode::AssignedToInfrastructure, 'Assigned to Infrastructure', 'warning', false, false, false, false, false, 4],
            [StatusCode::InfrastructureConfirmed, 'Infrastructure Confirmed Outage', 'warning', false, false, false, false, true, 5],
            [StatusCode::TeamDispatched, 'Team Dispatched', 'primary', false, false, false, false, false, 6],
            [StatusCode::AwaitingFieldAccess, 'Awaiting Field Access', 'warning', true, false, false, false, true, 7],
            [StatusCode::VendorEscalated, 'Vendor Escalated', 'warning', false, false, false, false, true, 8],
            [StatusCode::PartialRestore, 'Partial Restore', 'info', false, false, false, false, true, 9],
            [StatusCode::RestoreReported, 'Restore Reported – Awaiting NOC', 'info', false, false, false, false, false, 10],
            [StatusCode::NocRestoreConfirmed, 'NOC Restore Confirmed', 'success', false, false, false, false, false, 11],
            [StatusCode::AwaitingCustomerConfirmation, 'Awaiting Customer Confirmation', 'info', true, false, false, false, true, 12],
            [StatusCode::Resolved, 'Outage Resolved', 'success', true, true, true, false, false, 13],
            [StatusCode::ScheduledMaintenance, 'Scheduled Maintenance', 'gray', false, false, false, true, true, 14],
            // NOC rejected: waiting for CX Tier II to call the customer back. Not a confirmed outage, so the SLA is paused.
            [StatusCode::CxFollowUp, 'NOC Rejected – CX Follow-up', 'danger', false, false, false, true, false, 15],
            [StatusCode::ItReview, 'IT Review', 'gray', false, false, false, false, false, 16],
            [StatusCode::EscalatedToNoc, 'Escalated to NOC by IT', 'info', false, false, false, false, false, 17],
            [StatusCode::AwaitingItConfirmation, 'NOC Resolved – Awaiting IT Confirmation', 'success', false, false, false, false, false, 18],
            [StatusCode::MaintenanceRequested, 'Maintenance Requested – NOC Review', 'gray', false, false, false, false, false, 19],
            [StatusCode::CrfPendingSignoff, 'CRF – Awaiting Sign-off', 'warning', false, false, false, false, false, 20],
            [StatusCode::CxCustomerNotification, 'Maintenance Done – CX Notifying Customers', 'success', false, false, false, false, false, 21],
            [StatusCode::MaintenanceCancelled, 'Maintenance Cancelled', 'gray', true, true, false, false, false, 22],
            [StatusCode::PmPendingApproval, 'Preventive Maintenance – Awaiting Approval', 'warning', false, false, false, false, false, 23],
            [StatusCode::PmRejected, 'Preventive Maintenance – Rejected', 'gray', true, true, false, false, false, 24],
        ] as [$code, $name, $color, $prompt, $closed, $resolved, $pauses, $manual, $sort]) {
            OutageStatus::query()->updateOrCreate(['code' => $code->value], [
                'name' => $name, 'color' => $color, 'prompt_final_reason' => $prompt, 'is_closed' => $closed,
                'is_resolved' => $resolved, 'pauses_sla' => $pauses, 'is_manual_selectable' => $manual,
                'is_system' => true, 'sort_order' => $sort, 'is_active' => true,
            ]);
        }

        $this->mobility();
        $this->ticketing();
    }

    /**
     * Statuses of customer tickets and site visits. They follow the desk a ticket is at and are set
     * by the ticket workflow, never by "Set status". Existing statuses keep their name, colour,
     * order and "pauses SLA".
     */
    public function ticketing(): void
    {
        $ticket = OutageStatus::WORKFLOW_TICKET;
        $visit = OutageStatus::WORKFLOW_VISIT;

        // [code, name, colour, closed, resolved, used by, sort]
        foreach ([
            [StatusCode::TicketOpen, 'Ticket Open', 'gray', false, false, $ticket, 60],
            [StatusCode::TicketInProgress, 'Ticket In Progress', 'primary', false, false, $ticket, 61],
            [StatusCode::TicketEscalated, 'Escalated to Another Desk', 'warning', false, false, $ticket, 62],
            [StatusCode::TicketPendingApproval, 'Pending Approval', 'warning', false, false, $ticket, 63],
            [StatusCode::TicketPendingCustomer, 'Pending Customer Call-back', 'info', false, false, $ticket, 64],
            [StatusCode::TicketSiteVisit, 'Site Visit Raised', 'info', false, false, $ticket, 65],
            [StatusCode::TicketClosed, 'Ticket Closed', 'success', true, true, $ticket, 66],
            [StatusCode::TicketDeclined, 'Declined', 'gray', true, false, $ticket, 67],
            [StatusCode::TicketCancelled, 'Ticket Cancelled', 'gray', true, false, $ticket, 68],
            [StatusCode::TicketDuplicate, 'Duplicate', 'gray', true, false, $ticket, 69],
            [StatusCode::VisitOpen, 'Visit Open', 'gray', false, false, $visit, 70],
            [StatusCode::VisitPendingScheduled, 'Pending-Scheduled', 'info', false, false, $visit, 71],
            [StatusCode::VisitPendingWithoutTeam, 'Pending Without Team', 'warning', false, false, $visit, 72],
            [StatusCode::VisitInProgress, 'Visit In Progress', 'primary', false, false, $visit, 73],
            [StatusCode::VisitPendingEscalated, 'Pending Escalated', 'danger', false, false, $visit, 74],
            [StatusCode::VisitPendingVerification, 'Pending Dispatcher Verification', 'info', false, false, $visit, 75],
            [StatusCode::VisitPendingConfirmation, 'Pending Customer Confirmation', 'info', false, false, $visit, 76],
            [StatusCode::VisitPendingCustomer, 'Pending Customer (Call-back / Reschedule)', 'warning', false, false, $visit, 77],
            [StatusCode::VisitClosed, 'Successfully Closed', 'success', true, true, $visit, 78],
            [StatusCode::VisitClosedEscalated, 'Closed Escalated', 'success', true, true, $visit, 79],
        ] as [$code, $name, $color, $closed, $resolved, $workflow, $sort]) {
            $status = OutageStatus::query()->firstOrNew(['code' => $code->value]);
            $status->forceFill(['workflow' => $workflow, 'is_closed' => $closed, 'is_resolved' => $resolved, 'is_system' => true, 'is_manual_selectable' => false]);

            if (! $status->exists) {
                $name = OutageStatus::query()->where('name', $name)->exists() ? 'Ticket: '.$name : $name;
                $status->forceFill(['name' => $name, 'color' => $color, 'sort_order' => $sort, 'prompt_final_reason' => false, 'pauses_sla' => false, 'is_active' => true]);
            }

            $status->save();
        }
    }

    /**
     * 4G NOC statuses (spec §10). Which ones a ticket can take depends on its outage type's workflow:
     * internal (engineer / technician) or vendor. They are driven by the 4G workflow, never by "Set status".
     * Existing statuses keep their name, colour, order and "pauses SLA".
     */
    public function mobility(): void
    {
        // [code, name, colour, closed, resolved, sort]
        foreach ([
            [StatusCode::MobilityNew, 'New', 'gray', false, false, 40],
            [StatusCode::MobilityOpen, 'Open', 'info', false, false, 41],
            [StatusCode::MobilityAssigned, 'Assigned', 'warning', false, false, 42],
            [StatusCode::MobilityInProgress, 'In Progress', 'primary', false, false, 43],
            [StatusCode::MobilityVendorAssigned, 'Vendor Assigned', 'warning', false, false, 44],
            [StatusCode::MobilityVendorNotified, 'Vendor Notification Sent', 'warning', false, false, 45],
            [StatusCode::MobilityVendorAcknowledged, 'Vendor Acknowledged', 'info', false, false, 46],
            [StatusCode::MobilityVendorInvestigating, 'Vendor Investigating', 'primary', false, false, 47],
            [StatusCode::MobilityMonitoring, 'Monitoring', 'info', false, false, 48],
            [StatusCode::MobilityResolvedPendingNoc, 'Resolved – Pending NOC Confirmation', 'success', false, false, 49],
            [StatusCode::MobilityVendorResolvedPendingNoc, 'Vendor Resolved – Pending NOC Verification', 'success', false, false, 50],
            [StatusCode::MobilityClosed, 'Closed', 'success', true, true, 51],
            [StatusCode::MobilityCancelled, 'Cancelled', 'gray', true, false, 52],
        ] as [$code, $name, $color, $closed, $resolved, $sort]) {
            $status = OutageStatus::query()->firstOrNew(['code' => $code->value]);

            // The engine relies on these flags; name, colour, order and "pauses SLA" stay editable.
            $status->forceFill(['workflow' => OutageStatus::WORKFLOW_MOBILITY, 'is_closed' => $closed, 'is_resolved' => $resolved, 'is_system' => true, 'is_manual_selectable' => false]);

            if (! $status->exists) {
                // Status names are unique: a custom status already called "Open" or "Closed" keeps its name.
                $name = OutageStatus::query()->where('name', $name)->exists() ? '4G '.$name : $name;
                $status->forceFill(['name' => $name, 'color' => $color, 'sort_order' => $sort, 'prompt_final_reason' => false, 'pauses_sla' => false, 'is_active' => true]);
            }

            $status->save();
        }
    }
}
