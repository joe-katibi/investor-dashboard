<?php

use App\Services\Fifo\EligibilityEvaluator;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Technicians who only do Shifting / Relocation visits: "Desk: Shifting technician" (queue.work_shifting_technician)
 * qualifies for Shifting visits only. The role "Shifting / Relocation Technician" works it instead of the Site Visit
 * Technician desk, so its people no longer receive Support, Installation or Wi-Fi Extender visits. Someone who also
 * has the Site Visit Technician desk (another role) still receives every kind.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('permissions') || ! DB::table('permissions')->where('name', 'visit.work')->exists()) {
            return;
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Permission::findOrCreate(EligibilityEvaluator::SHIFTING_TECHNICIAN_DESK, 'web');

        $role = Role::query()->where('name', 'Shifting / Relocation Technician')->where('guard_name', 'web')->first();
        if ($role) {
            $role->givePermissionTo(EligibilityEvaluator::SHIFTING_TECHNICIAN_DESK);
            if ($role->hasPermissionTo('queue.work_visit_technician')) {
                $role->revokePermissionTo('queue.work_visit_technician');
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        $role = Role::query()->where('name', 'Shifting / Relocation Technician')->where('guard_name', 'web')->first();
        if ($role && Permission::query()->where('name', 'queue.work_visit_technician')->exists()) {
            $role->givePermissionTo('queue.work_visit_technician');
        }
    }
};
