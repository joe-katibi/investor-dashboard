<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/** Where an outage / site visit row came from: imported history. Rows raised in the system have no source (null). */
enum DataSource: string implements HasLabel
{
    case IncidentLog = 'incident_log';
    case TicketTracker = 'ticket_tracker';
    case ServiceDelivery = 'service_delivery';

    public function getLabel(): string
    {
        return match ($this) {
            self::IncidentLog => 'Incident log',
            self::TicketTracker => 'Ticket tracker',
            self::ServiceDelivery => 'Service Delivery workbook',
        };
    }

    /** @return list<string> the sources that hold site visits */
    public static function visitSources(): array
    {
        return [self::TicketTracker->value, self::ServiceDelivery->value];
    }
}
