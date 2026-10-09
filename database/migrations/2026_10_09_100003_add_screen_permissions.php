<?php

use App\Models\User;
use App\Support\Permissions;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Screens that used to come with a module's "view" permission get a switch of their own
 * (dashboards, the site visits list, the 4G engineers screen). Nothing changes on the day this
 * runs: every role — and every person given the permission directly — that could see a screen
 * keeps it. From then on a screen can be hidden from a role under Roles & Permissions.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable(config('permission.table_names.permissions', 'permissions'))) {
            return;
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (Permissions::SCREENS as $screen => $parent) {
            // A fresh database has no permissions yet: the seeder creates and grants them.
            if (! Permission::query()->where('name', $parent)->where('guard_name', 'web')->exists()) {
                continue;
            }

            $permission = Permission::findOrCreate($screen, 'web');

            Role::query()->where('guard_name', 'web')->whereHas('permissions', fn ($q) => $q->where('name', $parent))->get()
                ->each(fn (Role $role) => $role->givePermissionTo($permission));

            User::query()->whereHas('permissions', fn ($q) => $q->where('name', $parent))->get()
                ->each(fn (User $user) => $user->givePermissionTo($permission));
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        if (! Schema::hasTable(config('permission.table_names.permissions', 'permissions'))) {
            return;
        }

        Permission::query()->whereIn('name', array_keys(Permissions::SCREENS))->get()->each->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
