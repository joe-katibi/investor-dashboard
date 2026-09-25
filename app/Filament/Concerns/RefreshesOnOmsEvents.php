<?php

namespace App\Filament\Concerns;

use App\Events\OmsBroadcastEvent;

/**
 * Re-renders a Livewire component (page, table, widget) whenever an OMS event is pushed over
 * Reverb, so operational screens update without a browser refresh (spec §27.9). Polling is
 * only a slow fallback for when the websocket is unavailable.
 */
trait RefreshesOnOmsEvents
{
    public function getListeners(): array
    {
        $listeners = parent::getListeners();

        foreach (OmsBroadcastEvent::NAMES as $event) {
            $listeners['echo-private:'.OmsBroadcastEvent::OPERATIONS_CHANNEL.',.'.$event] = 'onOmsEvent';
        }

        return $listeners;
    }

    /** Receiving the event is enough: Livewire re-renders and every query runs fresh. */
    public function onOmsEvent(): void {}
}
