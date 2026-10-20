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
            'dashboard.view' => 'Screen: command centre dashboard (needs “View outages”)',
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
            'outage.cx_follow_up' => 'Record CX callback / site visit on NOC-rejected tickets',
            'outage.request_support' => 'Request a support technician on site (drop cable / premises)',
            'outage.support_work' => 'Complete support technician work',
            'outage.it_review' => 'IT review: resolve, escalate to NOC and confirm system outages',
        ],
        'Planned Maintenance' => [
            'maintenance.request' => 'Request planned maintenance (raise a CRF)',
            'maintenance.manage' => 'Complete the CRF, send for sign-off, resend, cancel',
            'maintenance.reschedule' => 'Reschedule planned maintenance',
            'maintenance.notify_customers' => 'Confirm customers were notified (close planned maintenance)',
            'maintenance.configure' => 'Configure maintenance templates, reminders and signatories',
            'field_maintenance.raise' => 'Raise preventive / corrective maintenance on site (with photos)',
            'preventive.approve_dispatch' => 'Approve preventive maintenance downtime as Dispatcher',
            'preventive.approve_manager' => 'Approve preventive maintenance downtime as Infrastructure Manager',
        ],
        '4G NOC' => [
            'mobility.view' => 'View 4G outages, dashboard and topology',
            'mobility.dashboard' => 'Screen: 4G dashboard (needs “View 4G outages”)',
            'mobility.engineers' => 'Screen: 4G engineers & technicians availability (needs “View 4G outages”)',
            'mobility.create' => 'Create 4G outage tickets',
            'mobility.edit' => 'Edit 4G ticket details',
            'mobility.assign' => 'Assign engineers / technicians and vendors',
            'mobility.vendor_update' => 'Record vendor updates and send vendor notifications',
            'mobility.work' => 'Engineer / technician: accept, investigate and mark resolved (cannot close)',
            'mobility.handle_transmission' => 'Receive transmission outages (4G Engineer)',
            'mobility.handle_enodeb' => 'Receive eNodeB outages (4G Technician)',
            'mobility.verify' => 'NOC: verify restoration, close tickets and set statuses',
            'mobility.reopen' => 'Reopen closed 4G tickets',
            'mobility.cancel' => 'Cancel 4G tickets',
            'mobility.escalate' => 'Escalate 4G tickets',
            'mobility.configure' => 'Manage vendors, 4G topology, impact rules, vendor templates and 4G settings',
        ],
        'Customer Tickets & Site Visits' => [
            'ticket.view' => 'View customer tickets, site visits, customers and the ticket dashboard',
            'ticket.dashboard' => 'Screen: Customer Care dashboard (needs “View customer tickets”)',
            'visit.view' => 'Screen: site visits list (needs “View customer tickets”)',
            'ticket.create' => 'Raise customer tickets',
            'ticket.work' => 'Work tickets at your desk: escalate, return, follow up and close',
            'ticket.approve' => 'Approve / decline refund, credit and billing requests',
            'visit.create' => 'Raise and schedule site visits',
            'visit.dispatch' => 'Dispatch site visits: assign technicians, reschedule, escalate and verify',
            'visit.dispatch_shifting' => 'Dispatch Shifting / Relocation visits: assign, reschedule, relocate, verify and close them (the Shifting Dispatch desk gives this too)',
            'visit.work' => 'Technician: travel, arrive, pause and complete site visits',
            'visit.work_shifting' => 'Technician: Shifting / Relocation visits (only technicians with this are given them)',
            'ticket.override' => 'Assign / reassign and override FIFO on customer tickets and site visits',
            'ticket.intervene' => 'Team leader: intervene on / take over a ticket',
            'ticket.reopen' => 'Reopen closed tickets and site visits',
            'ticket.cancel' => 'Cancel tickets / mark duplicates',
            'customer.manage' => 'Manage and import customers',
            'ticket.configure' => 'Configure regions, categories, visit types, reasons, desk routes and ticket settings',
        ],
        'Custom Workflows' => [
            'custom.view' => 'View every ticket of the custom workflows (without it: the tickets you raised, hold, or that wait for your team)',
            'custom.raise' => 'Raise tickets in custom workflows (a flow can narrow this to roles)',
            'custom.cancel' => 'Cancel tickets of custom workflows',
        ],
        'FIFO Queues' => [
            'queue.view' => 'View FIFO queues',
            'queue.work_mobility_noc' => 'Receive 4G tickets to verify (NOC)',
            'queue.work_mobility_engineer' => 'Receive 4G tickets as engineer / technician',
            'queue.work_noc' => 'Receive NOC queue tickets',
            'queue.work_infrastructure' => 'Receive Infrastructure dispatch tickets',
            'queue.work_technician' => 'Receive technician tickets',
            'queue.work_cx' => 'Receive CX follow-up tickets (NOC rejected)',
            'queue.work_support' => 'Receive support technician requests',
            'queue.work_it' => 'Receive IT review / confirmation tickets',
            'queue.work_cx_tier2' => 'Desk: CX Tier II (troubleshooting, vetting)',
            'queue.work_billing' => 'Desk: Billing',
            'queue.work_billing_manager' => 'Desk: Billing Manager',
            'queue.work_commercial' => 'Desk: Commercial / Sales Admin (refunds)',
            'queue.work_cx_manager' => 'Desk: CX Manager (credit approval)',
            'queue.work_cx_callback' => 'Desk: CX Frontline Follow-up (customer call-backs)',
            'queue.work_ticket_noc' => 'Desk: NOC troubleshooting for customer tickets',
            'queue.work_ticket_4g_noc' => 'Desk: 4G NOC (4G customer tickets)',
            'queue.work_ticket_ip' => 'Desk: IP Team (4G customer tickets)',
            'queue.work_ticket_rf' => 'Desk: RF Team (4G customer tickets)',
            'queue.work_ticket_core' => 'Desk: Core Team (4G customer tickets)',
            'queue.work_visit_dispatch' => 'Desk: Site Visit Dispatch',
            'queue.work_shifting_dispatch' => 'Desk: Shifting Dispatch (Shifting / Relocation visits)',
            'queue.work_visit_technician' => 'Desk: Site Visit Technician',
            'queue.work_shifting_technician' => 'Desk: Shifting technician (Shifting / Relocation visits only)',
            'queue.work_infra_support_dispatch' => 'Desk: Infrastructure Support Dispatch',
            'queue.work_infra_support_tech' => 'Desk: Infrastructure Support Technician',
            'queue.work_design' => 'Desk: Design',
            'queue.work_access' => 'Desk: Access Team',
            'queue.work_cx_field_support' => 'Desk: CX Field Support (confirm, reschedule, call back)',
            'queue.work_ticket_follow_up' => 'Desk: follow-up calls on tickets pending past the trigger',
            'queue.work_visit_aftercare' => 'Desk: CX Follow-up – Site Visit (the call 72 hours after a visit is closed)',
            'queue.override' => 'Assign / reassign and override FIFO on outages and custom workflows (customer tickets: see Customer Tickets & Site Visits)',
        ],
        'Configuration' => [
            'topology.manage' => 'Manage OLT topology',
            'sla.manage' => 'Manage SLA policies',
            'templates.manage' => 'Manage email templates',
            'notifications.manage' => 'Manage notification rules',
            'master_data.manage' => 'Manage verticals, services, types, statuses, priorities',
            'config.admin' => 'Administer FIFO & system settings',
            'workflow.configure' => 'Workflow configuration: steps, who works them, times, targets and switches of every flow',
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

    /**
     * Screens that have a switch of their own, and the permission that used to show them. A role
     * with the parent got the screen when the switch was introduced; untick it to hide the screen
     * from that role (a CX agent who should only raise tickets, not see the dashboards).
     */
    public const SCREENS = [
        'dashboard.view' => 'outage.view',
        'mobility.dashboard' => 'mobility.view',
        'mobility.engineers' => 'mobility.view',
        'ticket.dashboard' => 'ticket.view',
        'visit.view' => 'ticket.view',
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
