<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/** Whether a technician is staff or works for a contractor (the "Type" column of the technician list). */
enum EngagementType: string implements HasColor, HasLabel
{
    case Inhouse = 'inhouse';
    case Outsourced = 'outsourced';

    public function getLabel(): string
    {
        return match ($this) {
            self::Inhouse => 'In-house',
            self::Outsourced => 'Outsourced',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Inhouse => 'primary',
            self::Outsourced => 'gray',
        };
    }

    public static function fromList(string $value): ?self
    {
        return match (strtolower(trim($value))) {
            'inhouse', 'in-house', 'in house' => self::Inhouse,
            'outsourced' => self::Outsourced,
            default => null,
        };
    }
}
