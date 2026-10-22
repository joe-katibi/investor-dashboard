<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/** Where an outage / site visit row came from: imported history. Rows raised in the system have no source (null). */
enum DataSource: string implements HasLabel
{
    case IncidentLog = 'incident_log';
    case TicketTracker = 'ticket_tracker';

    public function getLabel(): string
    {
        return match ($this) {
            self::IncidentLog => 'Incident log',
            self::TicketTracker => 'Ticket tracker',
        };
    }
}
