<?php

use App\Enums\QueueType;
use App\Models\OutageType;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * 4G: transmission outages go to the 4G engineers, eNodeB outages to the 4G technicians. Who is
 * given which ticket is a permission, so it has to exist on a system that is already running — or
 * FIFO would find nobody to give 4G tickets to:
 *
 *   4G Engineer    → receive transmission outages
 *   4G Technician  → receive eNodeB outages
 *   any other role (or person) that already receives 4G tickets → both, exactly as before
 *
 * A fresh install gets all of this from the seeders; there this migration finds nothing to do.
 */
return new class extends Migration
{
    public function up(): void
    {
        $queue = QueueType::MobilityEngineer->defaultPermission();

        if (! Schema::hasTable('permissions') || Permission::query()->where('name', $queue)->where('guard_name', 'web')->doesntExist()) {
            return;
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $transmission = Permission::findOrCreate(OutageType::handlerPermission(OutageType::DOMAIN_TRANSMISSION), 'web');
        $enodeb = Permission::findOrCreate(OutageType::handlerPermission(OutageType::DOMAIN_ENODEB), 'web');
        $receives = fn ($query) => $query->where('name', $queue);

        foreach (Role::query()->where('guard_name', 'web')->whereHas('permissions', $receives)->get() as $role) {
            $role->givePermissionTo(match ($role->name) {
                '4G Engineer' => [$transmission],
                '4G Technician' => [$enodeb],
                default => [$transmission, $enodeb],
            });
        }

        // People given the queue permission directly (not through a role) keep receiving both.
        foreach (User::query()->whereHas('permissions', $receives)->get() as $user) {
            $user->givePermissionTo([$transmission, $enodeb]);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        if (! Schema::hasTable('permissions')) {
            return;
        }

        Permission::query()->whereIn('name', array_column(OutageType::HANDLERS, 0))->get()->each->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
