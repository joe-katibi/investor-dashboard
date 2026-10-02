<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * The operational FIFO queues an outage passes through (spec §27.5 / §28).
 */
enum QueueType: string implements HasColor, HasLabel
{
    case NocReview = 'noc_review';
    case Infrastructure = 'infrastructure';
    case Technician = 'technician';
    case NocRestore = 'noc_restore';
    case CxFollowUp = 'cx_follow_up';
    case SupportTechnician = 'support_technician';
    case ItReview = 'it_review';
    case ItConfirmation = 'it_confirmation';
    case CxNotify = 'cx_notify';

    // 4G NOC: the engineer / technician working a ticket, and the NOC verifying a reported fix.
    case MobilityEngineer = 'mobility_engineer';
    case MobilityNoc = 'mobility_noc';

    public function getLabel(): string
    {
        return match ($this) {
            self::NocReview => 'NOC Review',
            self::Infrastructure => 'Infrastructure Dispatch',
            self::Technician => 'Technician',
            self::NocRestore => 'NOC Restore Confirmation',
            self::CxFollowUp => 'CX Follow-up',
            self::SupportTechnician => 'Support Technician',
            self::ItReview => 'IT Review',
            self::ItConfirmation => 'IT Confirmation',
            self::CxNotify => 'CX Customer Notification',
            self::MobilityEngineer => '4G Engineer / Technician',
            self::MobilityNoc => '4G NOC Verification',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::NocReview => 'info',
            self::Infrastructure => 'warning',
            self::Technician => 'primary',
            self::NocRestore => 'success',
            self::CxFollowUp => 'danger',
            self::SupportTechnician => 'warning',
            self::ItReview, self::ItConfirmation => 'gray',
            self::CxNotify => 'success',
            self::MobilityEngineer => 'primary',
            self::MobilityNoc => 'success',
        };
    }

    /** Permission a user needs to receive tickets from this queue. */
    public function defaultPermission(): string
    {
        return match ($this) {
            self::NocReview => 'queue.work_noc',
            self::Infrastructure => 'queue.work_infrastructure',
            self::Technician => 'queue.work_technician',
            self::NocRestore => 'queue.work_noc',
            self::CxFollowUp, self::CxNotify => 'queue.work_cx',
            self::SupportTechnician => 'queue.work_support',
            self::ItReview, self::ItConfirmation => 'queue.work_it',
            self::MobilityEngineer => 'queue.work_mobility_engineer',
            self::MobilityNoc => 'queue.work_mobility_noc',
        };
    }

    /** Department code that normally owns the queue. */
    public function defaultDepartmentCode(): string
    {
        return match ($this) {
            self::NocReview, self::NocRestore => 'NOC',
            self::Infrastructure, self::Technician => 'INFRA',
            self::CxFollowUp, self::CxNotify => 'CX',
            self::SupportTechnician => 'DISPATCH',
            self::ItReview, self::ItConfirmation => 'IT',
            self::MobilityEngineer, self::MobilityNoc => 'MOBILITY',
        };
    }

    /**
     * Parallel queues run alongside the main flow on the same ticket (a support technician
     * works the drop cable while the outage technician works the live line). They never
     * take over the ticket's current queue / owner.
     */
    public function isParallel(): bool
    {
        return $this === self::SupportTechnician;
    }

    public function isMobility(): bool
    {
        return in_array($this, [self::MobilityEngineer, self::MobilityNoc], true);
    }
}
