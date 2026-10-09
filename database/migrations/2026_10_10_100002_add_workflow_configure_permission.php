<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * "Workflow configuration" is a permission of its own. On an installation in use it is given to
 * the roles that already administer the system settings (config.admin), so the people who set up
 * FIFO and SLA can open the new screen; from then on it is granted like any other permission.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable(config('permission.table_names.permissions', 'permissions'))
            || ! Permission::query()->where('name', 'config.admin')->where('guard_name', 'web')->exists()) {
            return; // a fresh database: the seeder creates and grants it
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $permission = Permission::findOrCreate('workflow.configure', 'web');

        Role::query()->where('guard_name', 'web')->whereHas('permissions', fn ($q) => $q->where('name', 'config.admin'))->get()
            ->each(fn (Role $role) => $role->givePermissionTo($permission));

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        if (Schema::hasTable(config('permission.table_names.permissions', 'permissions'))) {
            Permission::query()->where('name', 'workflow.configure')->get()->each->delete();
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }
    }
};
