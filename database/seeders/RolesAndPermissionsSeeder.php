<?php

namespace Database\Seeders;

use App\Support\Permissions;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Roles from spec §4.1 mapped to permission bundles. Idempotent: safe to re-run after
 * adding permissions; existing role customisations are preserved (only missing grants are added).
 */
class RolesAndPermissionsSeeder extends Seeder
{
    public const ROLES = [
        'Super Admin' => ['*'],

        // NOC
        'NOC Manager' => [
            'outage.*', '!outage.delete', 'queue.*', 'reports.*', 'audit.view', 'workforce.manage', 'sla.manage',
            'notifications.manage', 'templates.manage',
        ],
        'NOC Team Leader' => [
            'outage.view', 'outage.create', 'outage.edit', 'outage.confirm', 'outage.update_progress', 'outage.confirm_restoration',
            'outage.close', 'outage.change_status', 'outage.update_credit_sms', 'outage.override_priority',
            'queue.view', 'queue.work_noc', 'queue.override', 'reports.view', 'reports.export', 'workforce.manage',
        ],
        'NOC Tier II' => [
            'outage.view', 'outage.create', 'outage.edit', 'outage.confirm', 'outage.update_progress', 'outage.confirm_restoration',
            'outage.close', 'outage.change_status', 'outage.update_credit_sms', 'queue.view', 'queue.work_noc', 'reports.view',
        ],
        'NOC Tier I' => [
            'outage.view', 'outage.create', 'outage.confirm', 'outage.update_progress', 'outage.confirm_restoration',
            'queue.view', 'queue.work_noc', 'reports.view',
        ],

        // Customer Experience
        'CX Manager' => [
            'outage.view', 'outage.create', 'outage.update_credit_sms', 'outage.update_progress', 'outage.cx_follow_up',
            'queue.view', 'queue.override', 'reports.view', 'reports.export', 'workforce.manage',
        ],
        'CX Team Leader' => [
            'outage.view', 'outage.create', 'outage.update_credit_sms', 'outage.update_progress', 'outage.cx_follow_up',
            'queue.view', 'queue.override', 'reports.view',
        ],
        // Tier II receives NOC-rejected tickets (FIFO) to call the customer back / raise a site visit.
        'CX Tier II' => [
            'outage.view', 'outage.create', 'outage.update_credit_sms', 'outage.update_progress', 'outage.cx_follow_up',
            'queue.view', 'queue.work_cx',
        ],
        'CX Agent' => ['outage.view', 'outage.create', 'outage.update_credit_sms'],

        // Dispatch
        'Dispatch Manager' => [
            'outage.view', 'outage.dispatch', 'outage.update_progress', 'outage.support_work', 'queue.view', 'queue.work_infrastructure', 'queue.override',
            'reports.view', 'reports.export', 'workforce.manage',
        ],
        'Dispatch Team Leader' => [
            'outage.view', 'outage.dispatch', 'outage.update_progress', 'outage.support_work', 'queue.view', 'queue.work_infrastructure', 'queue.override', 'reports.view',
        ],
        'Dispatcher' => ['outage.view', 'outage.dispatch', 'outage.update_progress', 'queue.view', 'queue.work_infrastructure'],
        // Drop cable → customer premises. Works support requests in parallel with the outage technician.
        'Support/Installation Technician' => [
            'outage.view', 'outage.update_progress', 'outage.support_work', 'queue.work_support',
        ],

        // Infrastructure
        'Infrastructure Manager' => [
            'outage.view', 'outage.edit', 'outage.dispatch', 'outage.update_progress', 'outage.update_restoration', 'outage.change_status',
            'outage.request_support', 'queue.view', 'queue.work_infrastructure', 'queue.override', 'topology.manage', 'reports.view', 'reports.export', 'workforce.manage',
        ],
        'Infrastructure Team Leader' => [
            'outage.view', 'outage.dispatch', 'outage.update_progress', 'outage.update_restoration', 'outage.change_status',
            'outage.request_support', 'queue.view', 'queue.work_infrastructure', 'queue.override', 'topology.manage', 'reports.view', 'workforce.manage',
        ],
        'Infrastructure Dispatcher' => [
            'outage.view', 'outage.dispatch', 'outage.update_progress', 'outage.request_support', 'queue.view', 'queue.work_infrastructure',
        ],
        'Outage Technician' => [
            'outage.view', 'outage.update_progress', 'outage.update_restoration', 'outage.change_status', 'outage.request_support', 'queue.work_technician',
        ],

        // IT: first line for system outages; escalates core / network causes to NOC and confirms the fix.
        'IT Manager' => [
            'outage.view', 'outage.create', 'outage.edit', 'outage.update_progress', 'outage.it_review', 'outage.change_status',
            'queue.view', 'queue.work_it', 'queue.override', 'reports.view', 'reports.export', 'workforce.manage',
        ],
        'IT Team Leader' => [
            'outage.view', 'outage.create', 'outage.edit', 'outage.update_progress', 'outage.it_review', 'outage.change_status',
            'queue.view', 'queue.work_it', 'queue.override', 'reports.view', 'workforce.manage',
        ],
        'IT Engineer' => ['outage.view', 'outage.create', 'outage.update_progress', 'outage.it_review', 'queue.view', 'queue.work_it'],

        // Management (read-only command centre)
        'Executive' => ['outage.view', 'queue.view', 'reports.view', 'reports.export'],
    ];

    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $all = Permissions::all();

        foreach ($all as $name) {
            Permission::findOrCreate($name, 'web');
        }

        foreach (self::ROLES as $roleName => $patterns) {
            $role = Role::findOrCreate($roleName, 'web');
            $granted = $this->expand($patterns, $all);
            $missing = array_diff($granted, $role->permissions()->pluck('name')->all());

            if ($missing !== []) {
                $role->givePermissionTo($missing);
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /** Supports "*", "group.*" and "!exclusion" patterns. */
    private function expand(array $patterns, array $all): array
    {
        $granted = [];

        foreach ($patterns as $pattern) {
            if (str_starts_with($pattern, '!')) {
                continue;
            }

            $granted = array_merge($granted, $pattern === '*'
                ? $all
                : (str_ends_with($pattern, '.*') ? array_filter($all, fn ($p) => str_starts_with($p, substr($pattern, 0, -1))) : [$pattern]));
        }

        $excluded = array_map(fn ($p) => substr($p, 1), array_filter($patterns, fn ($p) => str_starts_with($p, '!')));

        return array_values(array_diff(array_unique($granted), $excluded));
    }
}
