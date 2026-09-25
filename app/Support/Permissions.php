<?php

namespace App\Support;

/**
 * Permission catalogue (spec §4.4). Access is granted by permission, never by role name;
 * roles are just editable bundles of these permissions.
 */
final class Permissions
{
    public const GROUPS = [
        'Outages' => [
            'outage.view' => 'View outages',
            'outage.create' => 'Create outages',
            'outage.edit' => 'Edit outage details',
            'outage.delete' => 'Delete outages',
            'outage.confirm' => 'NOC confirm / reject',
            'outage.dispatch' => 'Dispatch to teams / technicians',
            'outage.update_progress' => 'Post progress updates',
            'outage.update_restoration' => 'Report restoration',
            'outage.confirm_restoration' => 'NOC restore confirmation',
            'outage.close' => 'Resolve / close outages',
            'outage.change_status' => 'Set operational statuses',
            'outage.update_credit_sms' => 'Update SMS / credit flags',
            'outage.override_priority' => 'Override calculated priority',
        ],
        'FIFO Queues' => [
            'queue.view' => 'View FIFO queues',
            'queue.work_noc' => 'Receive NOC queue tickets',
            'queue.work_infrastructure' => 'Receive Infrastructure dispatch tickets',
            'queue.work_technician' => 'Receive technician tickets',
            'queue.override' => 'Assign / reassign and override FIFO',
        ],
        'Configuration' => [
            'topology.manage' => 'Manage OLT topology',
            'sla.manage' => 'Manage SLA policies',
            'templates.manage' => 'Manage email templates',
            'notifications.manage' => 'Manage notification rules',
            'master_data.manage' => 'Manage verticals, services, types, statuses, priorities',
            'config.admin' => 'Administer FIFO & system settings',
        ],
        'People' => [
            'users.manage' => 'Manage users',
            'teams.manage' => 'Manage departments & teams',
            'roles.manage' => 'Manage roles & permissions',
            'workforce.manage' => 'Manage shifts, leave, skills & availability',
        ],
        'Reporting & Audit' => [
            'reports.view' => 'View reports & dashboard',
            'reports.export' => 'Export reports (CSV / Excel / PDF)',
            'audit.view' => 'View audit trail & notification logs',
        ],
    ];

    /** @return list<string> */
    public static function all(): array
    {
        return array_merge(...array_map('array_keys', array_values(self::GROUPS)));
    }

    public static function label(string $permission): string
    {
        foreach (self::GROUPS as $permissions) {
            if (isset($permissions[$permission])) {
                return $permissions[$permission];
            }
        }

        return $permission;
    }
}
