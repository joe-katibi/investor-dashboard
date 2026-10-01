<?php

namespace App\Filament\Concerns;

use BackedEnum;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use UnitEnum;

/**
 * Permission-based access for configuration resources (spec §4.4). Master data referenced
 * by history is never hard-deleted from the UI (spec §21) — it is deactivated instead —
 * unless the resource explicitly opts in with $allowsDelete.
 *
 * Resources set: protected static string $permission = 'topology.manage';
 * Optionally:    protected static ?string $viewPermission = 'reports.view';
 *                protected static bool $allowsDelete = true;
 */
trait GuardsWithPermission
{
    protected static function allows(?string $permission): bool
    {
        return $permission !== null && (bool) Auth::user()?->can($permission);
    }

    protected static function managePermission(): string
    {
        return static::$permission;
    }

    /**
     * Filament authorises its Create / Edit / Delete actions and pages through this method, which
     * by default asks a Laravel policy and ALLOWS the action when the model has none. These
     * resources are permission-based (no policies), so every action is routed to the checks below —
     * otherwise anyone able to view the list could also create and edit.
     */
    public static function getAuthorizationResponse(string|UnitEnum $action, ?Model $record = null): Response
    {
        $action = $action instanceof UnitEnum ? ($action instanceof BackedEnum ? (string) $action->value : $action->name) : $action;

        $allowed = match ($action) {
            'viewAny' => static::canViewAny(),
            'view' => $record ? static::canView($record) : static::canViewAny(),
            'create' => static::canCreate(),
            'update' => $record ? static::canEdit($record) : static::allows(static::managePermission()),
            'delete' => $record ? static::canDelete($record) : static::canDeleteAny(),
            'deleteAny' => static::canDeleteAny(),
            'reorder', 'replicate' => static::allows(static::managePermission()),
            default => false, // force delete, restore, anything unknown
        };

        return $allowed ? Response::allow() : Response::deny();
    }

    public static function canViewAny(): bool
    {
        return static::allows(property_exists(static::class, 'viewPermission') && static::$viewPermission ? static::$viewPermission : static::managePermission())
            || static::allows(static::managePermission());
    }

    public static function canView(Model $record): bool
    {
        return static::canViewAny();
    }

    public static function canCreate(): bool
    {
        return static::allows(static::managePermission());
    }

    public static function canEdit(Model $record): bool
    {
        return static::allows(static::managePermission());
    }

    public static function canDelete(Model $record): bool
    {
        return static::deletable() && static::allows(static::managePermission());
    }

    public static function canDeleteAny(): bool
    {
        return static::deletable() && static::allows(static::managePermission());
    }

    public static function canForceDelete(Model $record): bool
    {
        return false;
    }

    public static function canForceDeleteAny(): bool
    {
        return false;
    }

    protected static function deletable(): bool
    {
        return property_exists(static::class, 'allowsDelete') && static::$allowsDelete;
    }
}
