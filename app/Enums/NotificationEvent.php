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
    case CreditDue = 'credit_due';
    case CreditShared = 'credit_shared';
    case SmsSent = 'sms_sent';
    case ScheduledReport = 'scheduled_report';

    public function getLabel(): string
    {
        return match ($this) {
            self::OutageCreated => 'Outage Created',
            self::NocEscalation => 'Escalated to NOC',
            self::NocConfirmed => 'NOC Confirmed',
            self::NocRejected => 'NOC Rejected',
            self::InfrastructureEscalation => 'Escalated to Infrastructure',
            self::Dispatched => 'Team Dispatched',
            self::StatusChanged => 'Status Changed',
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
            self::CreditDue => 'Credit Possibly Due',
            self::CreditShared => 'Credit Shared',
            self::SmsSent => 'SMS Sent',
            self::ScheduledReport => 'Scheduled Report',
        };
    }
}
