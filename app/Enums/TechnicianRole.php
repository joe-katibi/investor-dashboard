<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/** The technician roles of the technician list (each is a role of the same name in the system). */
enum TechnicianRole: string implements HasLabel
{
    case SiteVisit = 'Site Visit Technician';
    case Shifting = 'Shifting / Relocation Technician';
    case Outage = 'Outage Technician';

    public function getLabel(): string
    {
        return $this->value;
    }

    public static function fromList(string $value): ?self
    {
        $value = strtolower(trim(preg_replace('/\s+/', ' ', $value)));

        foreach (self::cases() as $case) {
            if (strtolower($case->value) === $value) {
                return $case;
            }
        }

        return null;
    }
}
