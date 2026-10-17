<?php

namespace Database\Seeders;

use App\Enums\QueueType;
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
            'outage.*', '!outage.delete', 'queue.*', 'reports.*', 'audit.view', 'workforce.manage', 'sla.manage', 'maintenance.*',
            'notifications.manage', 'templates.manage',
        ],
        'NOC Team Leader' => [
            'outage.view', 'outage.create', 'outage.edit', 'outage.confirm', 'outage.update_progress', 'outage.confirm_restoration',
            'outage.close', 'outage.change_status', 'outage.update_credit_sms', 'outage.override_priority',
            'queue.view', 'queue.work_noc', 'queue.override', 'reports.view', 'reports.export', 'workforce.manage',
            'maintenance.manage', 'maintenance.reschedule',
        ],
        'NOC Tier II' => [
            'outage.view', 'outage.create', 'outage.edit', 'outage.confirm', 'outage.update_progress', 'outage.confirm_restoration',
            'outage.close', 'outage.change_status', 'outage.update_credit_sms', 'queue.view', 'queue.work_noc', 'reports.view',
            'maintenance.manage',
        ],
        'NOC Tier I' => [
            'outage.view', 'outage.create', 'outage.confirm', 'outage.update_progress', 'outage.confirm_restoration',
            'queue.view', 'queue.work_noc', 'reports.view',
        ],

        // Customer Experience
        'CX Manager' => [
            'outage.view', 'outage.create', 'outage.update_credit_sms', 'outage.update_progress', 'outage.cx_follow_up',
            'queue.view', 'queue.override', 'reports.view', 'reports.export', 'workforce.manage', 'maintenance.notify_customers',
        ],
        'CX Team Leader' => [
            'outage.view', 'outage.create', 'outage.update_credit_sms', 'outage.update_progress', 'outage.cx_follow_up',
            'queue.view', 'queue.override', 'reports.view', 'maintenance.notify_customers',
        ],
        // Tier II receives NOC-rejected tickets (FIFO) to call the customer back / raise a site visit,
        // and completed planned maintenance to tell customers all is well.
        'CX Tier II' => [
            'outage.view', 'outage.create', 'outage.update_credit_sms', 'outage.update_progress', 'outage.cx_follow_up',
            'queue.view', 'queue.work_cx', 'maintenance.notify_customers',
        ],
        'CX Agent' => ['outage.view', 'outage.create', 'outage.update_credit_sms'],

        // Dispatch
        'Dispatch Manager' => [
            'outage.view', 'outage.dispatch', 'outage.update_progress', 'outage.support_work', 'queue.view', 'queue.work_infrastructure', 'queue.override',
            'reports.view', 'reports.export', 'workforce.manage', 'preventive.approve_dispatch',
        ],
        'Dispatch Team Leader' => [
            'outage.view', 'outage.dispatch', 'outage.update_progress', 'outage.support_work', 'queue.view', 'queue.work_infrastructure', 'queue.override', 'reports.view',
            'preventive.approve_dispatch',
        ],
        'Dispatcher' => ['outage.view', 'outage.dispatch', 'outage.update_progress', 'queue.view', 'queue.work_infrastructure', 'preventive.approve_dispatch'],
        // Drop cable → customer premises. Works support requests in parallel with the outage technician.
        'Support/Installation Technician' => [
            'outage.view', 'outage.update_progress', 'outage.support_work', 'queue.work_support',
        ],

        // Infrastructure
        'Infrastructure Manager' => [
            'outage.view', 'outage.edit', 'outage.dispatch', 'outage.update_progress', 'outage.update_restoration', 'outage.change_status',
            'outage.request_support', 'queue.view', 'queue.work_infrastructure', 'queue.override', 'topology.manage', 'reports.view', 'reports.export', 'workforce.manage',
            'maintenance.request', 'maintenance.reschedule', 'field_maintenance.raise', 'preventive.approve_manager',
        ],
        'Infrastructure Team Leader' => [
            'outage.view', 'outage.dispatch', 'outage.update_progress', 'outage.update_restoration', 'outage.change_status',
            'outage.request_support', 'queue.view', 'queue.work_infrastructure', 'queue.override', 'topology.manage', 'reports.view', 'workforce.manage',
            'maintenance.request', 'maintenance.reschedule', 'field_maintenance.raise',
        ],
        'Infrastructure Dispatcher' => [
            'outage.view', 'outage.dispatch', 'outage.update_progress', 'outage.request_support', 'queue.view', 'queue.work_infrastructure',
            'maintenance.request', 'preventive.approve_dispatch',
        ],
        'Outage Technician' => [
            'outage.view', 'outage.update_progress', 'outage.update_restoration', 'outage.change_status', 'outage.request_support', 'queue.work_technician',
            'field_maintenance.raise',
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
        // Owners of a business system: they review the system outages routed to their team (Affected Services → reviewed by).
        'CRM Manager' => SystemRoutingSeeder::PERMISSIONS,
        'CPaaS Manager' => SystemRoutingSeeder::PERMISSIONS,

        // 4G NOC: its own tickets, statuses, vendors and alert lists. Only the NOC closes a ticket;
        // engineers / technicians work it and mark it "Resolved – Pending NOC Confirmation".
        '4G NOC Manager' => [
            'mobility.*', '!mobility.handle_transmission', '!mobility.handle_enodeb',
            'queue.view', 'queue.work_mobility_noc', 'queue.override', 'reports.view', 'reports.export', 'audit.view',
            'workforce.manage', 'sla.manage', 'notifications.manage', 'templates.manage',
        ],
        '4G NOC' => [
            'mobility.view', 'mobility.create', 'mobility.edit', 'mobility.assign', 'mobility.vendor_update', 'mobility.work',
            'mobility.verify', 'mobility.reopen', 'mobility.cancel', 'mobility.escalate',
            'queue.view', 'queue.work_mobility_noc', 'reports.view',
        ],
        // Both sit in the 4G engineer / technician queue; which tickets each one is given is a permission:
        // transmission outages go to the engineers, eNodeB outages to the technicians.
        '4G Engineer' => ['mobility.view', 'mobility.work', 'queue.work_mobility_engineer', 'mobility.handle_transmission'],
        '4G Technician' => ['mobility.view', 'mobility.work', 'queue.work_mobility_engineer', 'mobility.handle_enodeb'],

        // Customer Care: customer tickets worked desk by desk, and site visits. One desk permission per desk.
        'Ticketing Manager' => [
            'ticket.*', 'visit.*', 'customer.manage', 'queue.view', 'queue.override', 'reports.view', 'reports.export', 'audit.view',
            'workforce.manage', 'templates.manage', 'notifications.manage',
        ],
        'CX Frontline' => ['ticket.view', 'ticket.create'],
        'CX Retention' => ['ticket.view', 'ticket.create'],
        'CX Follow-up Agent' => ['ticket.view', 'ticket.create', 'ticket.work', 'queue.view', 'queue.work_cx_callback', 'queue.work_ticket_follow_up'],
        'CX Field Support' => ['ticket.view', 'ticket.work', 'ticket.reopen', 'queue.view', 'queue.work_cx_field_support', 'queue.work_ticket_follow_up'],
        'CX Follow-up – Site Visit Agent' => ['ticket.view', 'visit.view', 'ticket.create', 'queue.view', 'queue.work_visit_aftercare'],
        'Billing Agent' => ['ticket.view', 'ticket.work', 'queue.view', 'queue.work_billing'],
        'Billing Manager' => ['ticket.view', 'ticket.work', 'ticket.approve', 'queue.view', 'queue.work_billing_manager', 'queue.override', 'ticket.override', 'reports.view'],
        'Commercial / Sales Admin' => ['ticket.view', 'ticket.work', 'ticket.approve', 'queue.view', 'queue.work_commercial'],
        'Site Visit Dispatcher' => [
            'ticket.view', 'ticket.work', 'ticket.cancel', 'visit.create', 'visit.dispatch', 'queue.view', 'queue.work_visit_dispatch', 'reports.view',
        ],
        'Site Visit Technician' => ['ticket.view', 'visit.work', 'queue.work_visit_technician'],
        'Shifting / Relocation Technician' => ['ticket.view', 'visit.work', 'visit.work_shifting', 'queue.work_visit_technician'],
        'Shifting / Relocation Dispatcher' => [
            'ticket.view', 'ticket.work', 'ticket.cancel', 'visit.create', 'visit.dispatch', 'visit.dispatch_shifting', 'queue.view', 'queue.work_visit_dispatch', 'reports.view',
        ],
        'Infra Support Dispatcher' => ['ticket.view', 'ticket.work', 'queue.view', 'queue.work_infra_support_dispatch'],
        'Infra Support Technician' => ['ticket.view', 'ticket.work', 'queue.work_infra_support_tech'],
        'Design Engineer' => ['ticket.view', 'ticket.work', 'queue.view', 'queue.work_design'],
        'Access Officer' => ['ticket.view', 'ticket.work', 'queue.view', 'queue.work_access'],

        // Management (read-only command centre)
        'Executive' => ['outage.view', 'mobility.view', 'queue.view', 'reports.view', 'reports.export'],
    ];

    /** Roles the customer ticketing module adds. */
    public const TICKETING_ROLES = [
        'Ticketing Manager', 'CX Frontline', 'CX Retention', 'CX Follow-up Agent', 'CX Field Support', 'Billing Agent', 'Billing Manager',
        'Commercial / Sales Admin', 'Site Visit Dispatcher', 'Site Visit Technician', 'Shifting / Relocation Technician', 'Shifting / Relocation Dispatcher', 'CX Follow-up – Site Visit Agent', 'Infra Support Dispatcher', 'Infra Support Technician',
        'Design Engineer', 'Access Officer',
    ];

    /** What the customer ticketing module gives the roles that already existed. */
    public const TICKETING_GRANTS = [
        'NOC Manager' => ['ticket.view', 'ticket.work', 'queue.work_ticket_noc'],
        'NOC Team Leader' => ['ticket.view', 'ticket.work', 'queue.work_ticket_noc'],
        'NOC Tier II' => ['ticket.view', 'ticket.work', 'queue.work_ticket_noc'],
        'NOC Tier I' => ['ticket.view', 'ticket.work', 'queue.work_ticket_noc'],
        'CX Manager' => ['ticket.*', 'visit.create', 'customer.manage', 'queue.work_cx_manager'],
        'CX Team Leader' => ['ticket.view', 'ticket.create', 'ticket.work', 'ticket.intervene', 'ticket.reopen', 'ticket.cancel', 'visit.create'],
        'CX Tier II' => ['ticket.view', 'ticket.create', 'ticket.work', 'visit.create', 'queue.work_cx_tier2'],
        'CX Agent' => ['ticket.view', 'ticket.create'],
        'Dispatch Manager' => [
            'ticket.view', 'ticket.work', 'ticket.intervene', 'ticket.reopen', 'ticket.cancel', 'visit.create', 'visit.dispatch', 'queue.work_visit_dispatch',
        ],
        'Dispatch Team Leader' => ['ticket.view', 'ticket.work', 'ticket.intervene', 'ticket.reopen', 'visit.create', 'visit.dispatch', 'queue.work_visit_dispatch'],
        'Dispatcher' => ['ticket.view', 'ticket.work', 'visit.create', 'visit.dispatch', 'queue.work_visit_dispatch'],
        'Support/Installation Technician' => ['ticket.view', 'visit.work', 'queue.work_visit_technician'],
        'Infrastructure Dispatcher' => ['ticket.view', 'ticket.work', 'queue.work_infra_support_dispatch'],
        'Outage Technician' => ['ticket.view', 'ticket.work', 'queue.work_infra_support_tech'],
        'Executive' => ['ticket.view'],
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
            $granted = $this->withScreens($this->expand(array_merge($patterns, self::TICKETING_GRANTS[$roleName] ?? []), $all));
            $missing = array_diff($granted, $role->permissions()->pluck('name')->all());

            if ($missing !== []) {
                $role->givePermissionTo($missing);
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public const MOBILITY_ROLES = ['4G NOC Manager', '4G NOC', '4G Engineer', '4G Technician'];

    /**
     * Only what the 4G NOC module adds, for installations whose roles are already tuned: the new
     * permissions and the four 4G roles. A role that already exists is left exactly as it is, and
     * no other role is touched — except that Executive gets "View 4G outages" the first time.
     */
    public function mobility(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $all = Permissions::all();
        $firstTime = ! Permission::query()->where('name', 'mobility.view')->exists();

        foreach ($all as $name) {
            if (str_starts_with($name, 'mobility.') || str_contains($name, 'work_mobility_')) {
                Permission::findOrCreate($name, 'web');
            }
        }

        $existing = Permission::query()->pluck('name')->all();

        foreach (self::MOBILITY_ROLES as $roleName) {
            if (Role::query()->where('name', $roleName)->where('guard_name', 'web')->exists()) {
                continue;
            }

            // Permissions of other modules are granted only where they already exist here.
            Role::findOrCreate($roleName, 'web')->givePermissionTo(array_values(array_intersect($this->withScreens($this->expand(self::ROLES[$roleName], $all)), $existing)));
        }

        if ($firstTime) {
            Role::query()->where('name', 'Executive')->where('guard_name', 'web')->first()?->givePermissionTo('mobility.view');
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * Only what the customer ticketing module adds, for installations whose roles are already tuned:
     * the new permissions and the new roles (a role that already exists is left exactly as it is).
     * The first time, the existing CX / NOC / Dispatch / technician roles also get their ticketing
     * permissions (see TICKETING_GRANTS); on later runs no existing role is touched.
     */
    public function ticketing(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $all = Permissions::all();
        $firstTime = ! Permission::query()->where('name', 'ticket.view')->exists();
        $mine = fn (string $name) => str_starts_with($name, 'ticket.') || str_starts_with($name, 'visit.') || $name === 'customer.manage'
            || in_array($name, array_map(fn (QueueType $desk) => $desk->defaultPermission(), QueueType::ticketing()), true);

        foreach (array_filter($all, $mine) as $name) {
            Permission::findOrCreate($name, 'web');
        }

        $existing = Permission::query()->pluck('name')->all();

        foreach (self::TICKETING_ROLES as $roleName) {
            if (Role::query()->where('name', $roleName)->where('guard_name', 'web')->exists()) {
                continue;
            }

            // Permissions of other modules are granted only where they already exist here.
            Role::findOrCreate($roleName, 'web')->givePermissionTo(array_values(array_intersect($this->withScreens($this->expand(self::ROLES[$roleName], $all)), $existing)));
        }

        if ($firstTime) {
            foreach (self::TICKETING_GRANTS as $roleName => $patterns) {
                Role::query()->where('name', $roleName)->where('guard_name', 'web')->first()
                    ?->givePermissionTo(array_values(array_intersect($this->withScreens($this->expand($patterns, $all)), $existing)));
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /** A role that may view a module also gets that module's screens (dashboards, lists) by default. */
    private function withScreens(array $granted): array
    {
        foreach (Permissions::SCREENS as $screen => $parent) {
            if (in_array($parent, $granted, true)) {
                $granted[] = $screen;
            }
        }

        // Managers and team leaders (who may override FIFO) see and may cancel tickets of custom workflows.
        if (in_array('queue.override', $granted, true)) {
            array_push($granted, 'custom.view', 'custom.cancel');
        }

        return array_values(array_unique($granted));
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
