<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/** Where an outage / site visit row came from: imported history. Rows raised in the system have no source (null). */
enum DataSource: string implements HasLabel
{
    case IncidentLog = 'incident_log';
    case TicketTracker = 'ticket_tracker';
    case ServiceDelivery = 'service_delivery';
    case OmsExport = 'oms_export';

    public function getLabel(): string
    {
        return match ($this) {
            self::IncidentLog => 'Incident log',
            self::TicketTracker => 'Ticket tracker',
            self::ServiceDelivery => 'Service Delivery workbook',
            self::OmsExport => 'OMS outage export',
        };
    }

    /** @return list<string> the sources that hold network outages */
    public static function outageSources(): array
    {
        return [self::IncidentLog->value, self::OmsExport->value];
    }

    /** @return list<string> the sources that hold site visits */
    public static function visitSources(): array
    {
        return [self::TicketTracker->value, self::ServiceDelivery->value];
    }
}
