<?php

namespace Database\Seeders;

use App\Models\Department;
use App\Models\Service;
use App\Models\Team;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * System outages routed by the affected system (chosen under "Affected services"):
 *
 *   Savbill, Grafana                 → the "CRM Managers" team
 *   CPaaS                            → the "CPaaS Manager" team
 *   Sav-Money, Customer App, Mail…   → the IT desk, as before
 *
 * Safe on a system in use — it only adds what is missing:
 *
 *   php artisan db:seed --class=SystemRoutingSeeder --force
 *
 * The systems are added to the affected services, the two teams and the two roles ("CRM Manager",
 * "CPaaS Manager") are created when they do not exist, and a system is pointed at its team only
 * the first time (a routing changed later under Configuration → Affected Services is kept).
 * Afterwards: give the people the role and put them in the team (Users / Teams).
 */
class SystemRoutingSeeder extends Seeder
{
    /** Affected system => team that reviews its outages (null = the IT desk). */
    public const SYSTEMS = [
        'Billing (Savbill)' => 'CRM Managers',
        'Grafana' => 'CRM Managers',
        'CPaaS' => 'CPaaS Manager',
        'Sav-Money' => null,
        'Customer App' => null,
        'Mail' => null,
    ];

    public const ROLES = ['CRM Manager', 'CPaaS Manager'];

    /** What a system owner needs to review, escalate to NOC and confirm a system outage. */
    public const PERMISSIONS = ['outage.view', 'outage.create', 'outage.update_progress', 'outage.it_review', 'queue.view', 'queue.work_it', 'reports.view'];

    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $existing = Permission::query()->pluck('name')->all();

        foreach (self::ROLES as $name) {
            if (! Role::query()->where('name', $name)->where('guard_name', 'web')->exists()) {
                Role::findOrCreate($name, 'web')->givePermissionTo(array_values(array_intersect(self::PERMISSIONS, $existing)));
            }
        }

        // Teams belong to a department: IT, or whichever comes first where IT was renamed away.
        $departmentId = Department::query()->where('code', 'IT')->value('id') ?? Department::query()->orderBy('id')->value('id');
        $newTeams = [];

        foreach (self::SYSTEMS as $system => $teamName) {
            $service = Service::query()->firstOrCreate(['name' => $system, 'vertical_id' => null], ['is_active' => true]);

            if ($teamName === null) {
                continue;
            }

            $team = Team::query()->where('name', $teamName)->first();

            if (! $team) {
                $team = Team::query()->create([
                    'name' => $teamName, 'department_id' => $departmentId, 'team_type' => 'in_house', 'is_active' => true,
                    'description' => 'Reviews system outages of: '.implode(', ', array_keys(self::SYSTEMS, $teamName, true)).'.',
                ]);
                $newTeams[$teamName] = true;
            }

            // Only when the team or the system is new: a routing an administrator changed is not put back.
            if ($service->review_team_id === null && (isset($newTeams[$teamName]) || $service->wasRecentlyCreated)) {
                $service->forceFill(['review_team_id' => $team->id])->save();
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
