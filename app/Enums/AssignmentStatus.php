<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum AssignmentStatus: string implements HasColor, HasLabel
{
    case Assigned = 'assigned';
    case Accepted = 'accepted';
    case Rejected = 'rejected';
    case Released = 'released';
    case TimedOut = 'timed_out';
    case Completed = 'completed';

    public function getLabel(): string
    {
        return match ($this) {
            self::Assigned => 'Awaiting Acceptance',
            self::Accepted => 'Accepted',
            self::Rejected => 'Rejected',
            self::Released => 'Released',
            self::TimedOut => 'Timed Out',
            self::Completed => 'Completed',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Assigned => 'warning',
            self::Accepted => 'primary',
            self::Rejected, self::TimedOut => 'danger',
            self::Released => 'gray',
            self::Completed => 'success',
        };
    }

    /** Statuses that count towards a user's workload. */
    public static function activeValues(): array
    {
        return [self::Assigned->value, self::Accepted->value];
    }
}
