<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum TeamType: string implements HasColor, HasLabel
{
    case InHouse = 'in_house';
    case Outsource = 'outsource';

    public function getLabel(): string
    {
        return match ($this) {
            self::InHouse => 'In-house',
            self::Outsource => 'Outsource',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::InHouse => 'primary',
            self::Outsource => 'warning',
        };
    }
}
