<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum NotificationEvent: string implements HasLabel
{
    case OutageCreated = 'outage_created';
    case NocEscalation = 'noc_escalation';
    case NocConfirmed = 'noc_confirmed';
    case NocRejected = 'noc_rejected';
    case InfrastructureEscalation = 'infrastructure_escalation';
    case Dispatched = 'dispatched';
    case StatusChanged = 'status_changed';
    case UpdatePosted = 'update_posted';
    case RestoreReported = 'restore_reported';
    case RestoreConfirmed = 'restore_confirmed';
    case Resolved = 'resolved';
    case PriorityChanged = 'priority_changed';
    case Assigned = 'assigned';
    case AssignmentRejected = 'assignment_rejected';
    case AssignmentTimeout = 'assignment_timeout';
    case QueueEscalation = 'queue_escalation';
    case SlaPreBreach = 'sla_pre_breach';
    case SlaBreach = 'sla_breach';
    case SlaEscalation = 'sla_escalation';
    // SLA per desk: a ticket waiting at a desk past (or close to) that desk's target, and an outage not yet on the field.
    case DeskSlaPreBreach = 'desk_sla_pre_breach';
    case DeskSlaBreach = 'desk_sla_breach';
    case FieldSlaPreBreach = 'field_sla_pre_breach';
    case FieldSlaBreach = 'field_sla_breach';
    case CreditDue = 'credit_due';
    case CreditShared = 'credit_shared';
    case SmsSent = 'sms_sent';
    case ScheduledReport = 'scheduled_report';
    case CxFollowUp = 'cx_follow_up';
    case SiteVisitRaised = 'site_visit_raised';
    case SupportRequested = 'support_requested';
    case SupportCompleted = 'support_completed';
    case ItEscalation = 'it_escalation';
    case ReturnedToIt = 'returned_to_it';
    case MaintenanceRequested = 'maintenance_requested';
    case CrfSignoffRequested = 'crf_signoff_requested';
    case CrfSigned = 'crf_signed';
    case CrfChangesRequested = 'crf_changes_requested';
    case CrfApproved = 'crf_approved';
    case MaintenanceReminder = 'maintenance_reminder';
    case MaintenanceStarted = 'maintenance_started';
    case MaintenanceRescheduled = 'maintenance_rescheduled';
    case MaintenanceCompleted = 'maintenance_completed';
    case MaintenanceCancelled = 'maintenance_cancelled';
    case PreventiveRequested = 'preventive_requested';
    case PreventiveApproved = 'preventive_approved';
    case PreventiveRejected = 'preventive_rejected';
    case CorrectiveRaised = 'corrective_raised';

    // 4G NOC
    case MobilityVendorNotified = 'mobility_vendor_notified';
    case MobilityVendorMailFailed = 'mobility_vendor_mail_failed';
    case MobilityVendorUpdate = 'mobility_vendor_update';
    case MobilityVendorSilent = 'mobility_vendor_silent';
    case MobilityVendorEscalation = 'mobility_vendor_escalation';
    case MobilityResolvedPending = 'mobility_resolved_pending';
    case MobilityVerificationFailed = 'mobility_verification_failed';
    case MobilityReopened = 'mobility_reopened';
    case MobilityCancelled = 'mobility_cancelled';
    case MobilityEscalated = 'mobility_escalated';

    // Customer tickets and site visits
    case TicketRouted = 'ticket_routed';
    case TicketReturned = 'ticket_returned';
    case TicketApproval = 'ticket_approval';
    case TicketFollowUpDue = 'ticket_follow_up_due';
    case TicketCreditReady = 'ticket_credit_ready';
    case TicketReopened = 'ticket_reopened';
    case TicketIntervention = 'ticket_intervention';
    case VisitScheduled = 'visit_scheduled';
    case VisitRescheduled = 'visit_rescheduled';
    case VisitCompleted = 'visit_completed';
    case TicketCustomerSms = 'ticket_customer_sms';

    /** Events of the customer ticketing module, in lifecycle order (for its alert settings). */
    public static function ticketing(): array
    {
        return [
            self::OutageCreated, self::Assigned, self::TicketRouted, self::TicketReturned, self::TicketApproval, self::VisitScheduled,
            self::VisitRescheduled, self::VisitCompleted, self::UpdatePosted, self::StatusChanged, self::TicketFollowUpDue,
            self::TicketCreditReady, self::TicketIntervention, self::Resolved, self::TicketReopened, self::AssignmentRejected,
            self::AssignmentTimeout, self::QueueEscalation, self::SlaPreBreach, self::SlaBreach, self::SlaEscalation,
            self::DeskSlaPreBreach, self::DeskSlaBreach,
        ];
    }

    public function getLabel(): string
    {
        return match ($this) {
            self::TicketRouted => 'Ticket: Escalated to Another Desk',
            self::TicketReturned => 'Ticket: Returned to the Previous Desk',
            self::TicketApproval => 'Ticket: Request Approved / Declined',
            self::TicketFollowUpDue => 'Ticket: Pending Past the Follow-up Time',
            self::TicketCreditReady => 'Ticket: Ready for Credit',
            self::TicketReopened => 'Ticket: Reopened',
            self::TicketIntervention => 'Ticket: Team Leader Intervention',
            self::VisitScheduled => 'Site Visit: Technician Assigned / Scheduled',
            self::VisitRescheduled => 'Site Visit: Rescheduled',
            self::VisitCompleted => 'Site Visit: Completed by the Technician',
            self::TicketCustomerSms => 'Customer SMS (ticket)',
            self::MobilityVendorNotified => '4G: Vendor Notification Sent',
            self::MobilityVendorMailFailed => '4G: Vendor Notification Failed',
            self::MobilityVendorUpdate => '4G: Vendor Update Recorded',
            self::MobilityVendorSilent => '4G: No Vendor Update – NOC Escalation',
            self::MobilityVendorEscalation => '4G: No Vendor Update – Management Escalation',
            self::MobilityResolvedPending => '4G: Resolved – Pending NOC Confirmation',
            self::MobilityVerificationFailed => '4G: NOC Could Not Verify the Fix',
            self::MobilityReopened => '4G: Ticket Reopened',
            self::MobilityCancelled => '4G: Ticket Cancelled',
            self::MobilityEscalated => '4G: Ticket Escalated by NOC',
            self::PreventiveRequested => 'Preventive Maintenance Awaiting Approval',
            self::PreventiveApproved => 'Preventive Maintenance Downtime Approved',
            self::PreventiveRejected => 'Preventive Maintenance Rejected',
            self::CorrectiveRaised => 'Corrective Maintenance Raised',
            self::MaintenanceRequested => 'Planned Maintenance Requested',
            self::CrfSignoffRequested => 'CRF Sent for Sign-off',
            self::CrfSigned => 'CRF Signed by a Signatory',
            self::CrfChangesRequested => 'CRF Changes Requested',
            self::CrfApproved => 'CRF Fully Signed',
            self::MaintenanceReminder => 'Maintenance Reminder',
            self::MaintenanceStarted => 'Maintenance Started',
            self::MaintenanceRescheduled => 'Maintenance Rescheduled',
            self::MaintenanceCompleted => 'Maintenance Completed (customers notified)',
            self::MaintenanceCancelled => 'Maintenance Cancelled',
            self::CxFollowUp => 'CX Follow-up Recorded (callback)',
            self::SiteVisitRaised => 'Site Visit Raised',
            self::SupportRequested => 'Support Technician Requested',
            self::SupportCompleted => 'Support Technician Work Completed',
            self::ItEscalation => 'Assigned to IT for review',
            self::ReturnedToIt => 'NOC Resolved – Returned to IT',
            self::OutageCreated => 'Outage Created',
            self::NocEscalation => 'Escalated to NOC',
            self::NocConfirmed => 'NOC Confirmed',
            self::NocRejected => 'NOC Rejected',
            self::InfrastructureEscalation => 'Escalated to Infrastructure',
            self::Dispatched => 'Team Dispatched',
            self::StatusChanged => 'Status Changed',
            self::UpdatePosted => 'Progress Update Posted',
            self::RestoreReported => 'Restoration Reported',
            self::RestoreConfirmed => 'NOC Restore Confirmed',
            self::Resolved => 'Outage Resolved',
            self::PriorityChanged => 'Priority Changed',
            self::Assigned => 'Ticket Assigned',
            self::AssignmentRejected => 'Assignment Rejected',
            self::AssignmentTimeout => 'Acknowledgement Timeout',
            self::QueueEscalation => 'Queue Escalation (repeated reject/timeout)',
            self::SlaPreBreach => 'SLA Pre-Breach',
            self::SlaBreach => 'SLA Breach',
            self::SlaEscalation => 'SLA Escalation',
            self::DeskSlaPreBreach => 'Desk SLA: Target Almost Up',
            self::DeskSlaBreach => 'Desk SLA: Target Breached at a Desk',
            self::FieldSlaPreBreach => 'Field: Not Yet Dispatched (warning)',
            self::FieldSlaBreach => 'Field: Not Dispatched Within the Target',
            self::CreditDue => 'Credit Possibly Due',
            self::CreditShared => 'Credit Shared',
            self::SmsSent => 'SMS Sent',
            self::ScheduledReport => 'Scheduled Report',
        };
    }
}
