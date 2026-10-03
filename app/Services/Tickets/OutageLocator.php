<?php

namespace App\Services\Tickets;

use App\Models\Outage;

/**
 * Is this customer sitting under an ongoing network outage? Looks at every open FTTH / core
 * outage on the customer's OLT — NOC-confirmed or still unconfirmed — and follows the outage's
 * own selection down the tree: an outage raised on an FDT covers every slot and port below it,
 * one raised on particular ports covers only those.
 */
class OutageLocator
{
    /**
     * @return array{outage: Outage, certain: bool, confirmed: bool}|null certain = false when the
     *                                                                    customer's FDT / slot / port is not on file, so the match is only "possibly affected"
     */
    public function ongoingFor(?int $oltId, ?int $fdtId = null, ?int $slotId = null, ?int $portId = null): ?array
    {
        if (! $oltId) {
            return null;
        }

        $candidates = Outage::query()
            ->standard()
            ->active()
            ->whereHas('olts', fn ($q) => $q->where('olts.id', $oltId))
            ->with(['status', 'fdts:id,olt_id', 'slots:id,fdt_id', 'ports:id,slot_id'])
            ->orderByDesc('start_time')
            ->limit(100)
            ->get();

        $possible = null;

        foreach ($candidates as $outage) {
            $match = $this->covers($outage, $oltId, $fdtId, $slotId, $portId);

            if ($match === true) {
                return ['outage' => $outage, 'certain' => true, 'confirmed' => $outage->noc_confirmed_at !== null];
            }

            if ($match === null) {
                $possible ??= ['outage' => $outage, 'certain' => false, 'confirmed' => $outage->noc_confirmed_at !== null];
            }
        }

        return $possible;
    }

    /** true = affected, false = not affected, null = cannot tell (customer's position is incomplete). */
    private function covers(Outage $outage, int $oltId, ?int $fdtId, ?int $slotId, ?int $portId): ?bool
    {
        $fdts = $outage->fdts->where('olt_id', $oltId);

        if ($fdts->isEmpty()) {
            return true; // the whole OLT
        }

        if (! $fdtId) {
            return null;
        }

        if (! $fdts->contains('id', $fdtId)) {
            return false;
        }

        $slots = $outage->slots->where('fdt_id', $fdtId);

        if ($slots->isEmpty()) {
            return true; // the whole FDT
        }

        if (! $slotId) {
            return null;
        }

        if (! $slots->contains('id', $slotId)) {
            return false;
        }

        $ports = $outage->ports->where('slot_id', $slotId);

        if ($ports->isEmpty()) {
            return true; // the whole slot
        }

        return $portId ? $ports->contains('id', $portId) : null;
    }
}
