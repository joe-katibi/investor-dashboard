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

    public function getLabel(): string
    {
        return match ($this) {
            self::NocReview => 'NOC Review',
            self::Infrastructure => 'Infrastructure Dispatch',
            self::Technician => 'Technician',
            self::NocRestore => 'NOC Restore Confirmation',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::NocReview => 'info',
            self::Infrastructure => 'warning',
            self::Technician => 'primary',
            self::NocRestore => 'success',
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
        };
    }

    /** Department code that normally owns the queue. */
    public function defaultDepartmentCode(): string
    {
        return match ($this) {
            self::NocReview, self::NocRestore => 'NOC',
            self::Infrastructure, self::Technician => 'INFRA',
        };
    }
}
