<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum QueueEntryStatus: string implements HasColor, HasLabel
{
    case Pending = 'pending';
    case Assigned = 'assigned';
    case Accepted = 'accepted';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    public function getLabel(): string
    {
        return ucfirst($this->value);
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Pending => 'warning',
            self::Assigned => 'info',
            self::Accepted => 'primary',
            self::Completed => 'success',
            self::Cancelled => 'gray',
        };
    }

    public static function openValues(): array
    {
        return [self::Pending->value, self::Assigned->value, self::Accepted->value];
    }
}
