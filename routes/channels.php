<?php

use App\Events\OmsBroadcastEvent;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

// Personal channel: assignment alerts and Filament database-notification refreshes.
Broadcast::channel('App.Models.User.{id}', function (User $user, int $id): bool {
    return $user->is_active && $user->id === $id;
});

// Shared operations channel: queue, dashboard and outage-list refresh signals.
Broadcast::channel(OmsBroadcastEvent::OPERATIONS_CHANNEL, function (User $user): bool {
    return $user->is_active && $user->can('outage.view');
});
