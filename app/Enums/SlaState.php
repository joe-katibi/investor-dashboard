<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum SlaState: string implements HasColor, HasLabel
{
    case Compliant = 'compliant';
    case AtRisk = 'at_risk';
    case Breached = 'breached';
    case Met = 'met';
    case Missed = 'missed';
    case NotApplicable = 'not_applicable';

    public function getLabel(): string
    {
        return match ($this) {
            self::Compliant => 'Compliant',
            self::AtRisk => 'Approaching Breach',
            self::Breached => 'Breached',
            self::Met => 'Resolved within SLA',
            self::Missed => 'Resolved outside SLA',
            self::NotApplicable => 'N/A',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Compliant, self::Met => 'success',
            self::AtRisk => 'warning',
            self::Breached, self::Missed => 'danger',
            self::NotApplicable => 'gray',
        };
    }

    /** @return list<self> */
    public static function live(): array
    {
        return [self::Compliant, self::AtRisk, self::Breached];
    }
}
