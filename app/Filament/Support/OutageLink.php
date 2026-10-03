<?php

namespace App\Filament\Support;

use App\Filament\Resources\MobilityOutages\MobilityOutageResource;
use App\Filament\Resources\Outages\OutageResource;
use App\Filament\Resources\SiteVisits\SiteVisitResource;
use App\Filament\Resources\Tickets\TicketResource;
use App\Models\Outage;

/** The ticket page of an outage: 4G under "4G NOC", customer tickets and site visits under "Customer Care", the rest under "Outages". */
final class OutageLink
{
    public static function url(Outage|int|null $outage): ?string
    {
        if ($outage === null) {
            return null;
        }

        $outage = $outage instanceof Outage ? $outage : Outage::query()->select(['id', 'workflow'])->find($outage);

        if (! $outage) {
            return null;
        }

        return match (true) {
            $outage->isSiteVisit() => SiteVisitResource::getUrl('view', ['record' => $outage->id]),
            $outage->isCxTicket() => TicketResource::getUrl('view', ['record' => $outage->id]),
            $outage->isMobility() => MobilityOutageResource::getUrl('view', ['record' => $outage->id]),
            default => OutageResource::getUrl('view', ['record' => $outage->id]),
        };
    }
}
