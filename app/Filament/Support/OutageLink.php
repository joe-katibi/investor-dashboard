<?php

namespace App\Filament\Support;

use App\Filament\Resources\MobilityOutages\MobilityOutageResource;
use App\Filament\Resources\Outages\OutageResource;
use App\Models\Outage;

/** The ticket page of an outage: 4G NOC tickets live under "4G NOC", everything else under "Outages". */
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

        return $outage->workflow !== null
            ? MobilityOutageResource::getUrl('view', ['record' => $outage->id])
            : OutageResource::getUrl('view', ['record' => $outage->id]);
    }
}
