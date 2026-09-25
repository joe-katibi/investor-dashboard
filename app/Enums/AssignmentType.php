<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum AssignmentType: string implements HasColor, HasLabel
{
    case AutoFifo = 'auto_fifo';
    case Dispatch = 'dispatch';
    case Manual = 'manual';
    case Override = 'override';

    public function getLabel(): string
    {
        return match ($this) {
            self::AutoFifo => 'Automatic FIFO',
            self::Dispatch => 'Dispatcher Selection',
            self::Manual => 'Manual Assignment',
            self::Override => 'Manager Override',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::AutoFifo => 'success',
            self::Dispatch => 'info',
            self::Manual => 'warning',
            self::Override => 'danger',
        };
    }
}
