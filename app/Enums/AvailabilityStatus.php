<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum AvailabilityStatus: string implements HasColor, HasLabel
{
    case Available = 'available';
    case Busy = 'busy';
    case OnBreak = 'on_break';
    case Offline = 'offline';

    public function getLabel(): string
    {
        return match ($this) {
            self::Available => 'Available',
            self::Busy => 'Busy',
            self::OnBreak => 'On Break',
            self::Offline => 'Offline',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Available => 'success',
            self::Busy => 'warning',
            self::OnBreak => 'info',
            self::Offline => 'gray',
        };
    }
}
