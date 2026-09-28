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
}
