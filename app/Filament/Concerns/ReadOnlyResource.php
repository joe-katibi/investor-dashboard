<?php

namespace App\Filament\Concerns;

use BackedEnum;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/**
 * Read-only resources (audit trail, notification log): anyone allowed to open the list may view
 * a record; nothing else is ever authorised. See GuardsWithPermission for why this goes through
 * getAuthorizationResponse (Filament allows actions on models without a policy).
 */
trait ReadOnlyResource
{
    public static function getAuthorizationResponse(string|UnitEnum $action, ?Model $record = null): Response
    {
        $action = $action instanceof UnitEnum ? ($action instanceof BackedEnum ? (string) $action->value : $action->name) : $action;

        return in_array($action, ['viewAny', 'view'], true) && static::canViewAny() ? Response::allow() : Response::deny();
    }
}
