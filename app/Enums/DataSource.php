<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Where an outage / ticket row came from. Rows raised in the system itself have no source (null).
 * Demo rows are generated to fill months with no real data and are always shown as such.
 */
enum DataSource: string implements HasColor, HasLabel
{
    case IncidentLog = 'incident_log';
    case TicketTracker = 'ticket_tracker';
    case Demo = 'demo';

    public function getLabel(): string
    {
        return match ($this) {
            self::IncidentLog => 'Incident log (real)',
            self::TicketTracker => 'Ticket tracker (real)',
            self::Demo => 'Demo data',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::IncidentLog, self::TicketTracker => 'success',
            self::Demo => 'warning',
        };
    }

    public function isReal(): bool
    {
        return $this !== self::Demo;
    }
}
