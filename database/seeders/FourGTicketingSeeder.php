<?php

namespace Database\Seeders;

use App\Enums\QueueType;
use App\Models\DelayReason;
use App\Models\DeskRoute;
use App\Models\FinalReason;
use App\Models\OutageType;
use App\Models\Priority;
use App\Models\TicketCategory;
use App\Models\TicketSubcategory;
use App\Models\VendorTemplate;
use App\Models\Vertical;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Customer tickets of the 4G vertical: a starter set of categories, final reasons and reasons for
 * delay (all editable under Customer Care), the desks of the 4G flow — 4G NOC, IP Team, RF Team,
 * Core Team — with their roles, routes and queues, and the e-mails to their vendors.
 *
 * Create-only: what already exists (and may have been edited) is left exactly as it is.
 */
class FourGTicketingSeeder extends Seeder
{
    /** [code, name, first desk, [subcategory, …]] — a subcategory is "name" or [name, priority code]. */
    public const CATEGORIES = [
        ['4G-NET', '4G Connectivity', QueueType::CxTier2, [
            ['No service / no signal', 'high'], ['Connected but cannot browse', 'high'], 'Intermittent connection', 'Slow speeds',
            'Weak signal indoors / coverage',
        ]],
        ['4G-DEV', '4G Router / Device', QueueType::CxTier2, ['Router not powering on', 'Router configuration', 'SIM not detected', 'Router replacement']],
        ['4G-SVC', '4G Service & Account', QueueType::CxTier2, ['Data bundle / balance issue', 'Activation / new connection', 'Suspension / reconnection']],
        ['4G-BILL', '4G Billing', QueueType::Billing, ['Wrong bill / double charge (4G)', 'Payment not reflected (4G)']],
        ['4G-GEN', '4G General', QueueType::CxTier2, ['General enquiry (4G)', 'Complaint (4G)']],
    ];

    public const FINAL_REASONS = [
        'Resolved remotely by CX Tier II (4G)', 'Resolved by the 4G NOC', 'Resolved by the IP Team', 'Resolved by the RF Team',
        'Resolved by the Core Team', 'Resolved by the vendor (4G)', 'Router replaced / reconfigured (4G)', 'Coverage limitation — customer advised (4G)',
        'Information / enquiry answered (4G)', 'Complaint resolved — customer satisfied (4G)',
    ];

    /** [code (≤ 10), name, within our control] */
    public const DELAY_REASONS = [
        ['4G-VENDOR', 'Waiting for the vendor (4G)', false],
        ['4G-SITE', 'Site / sector down (4G)', true],
        ['4G-POWER', 'Power outage at the site (4G)', false],
        ['4G-TX', 'Transmission / backhaul fault (4G)', true],
        ['4G-CORE', 'Core network issue (4G)', true],
        ['4G-CUST', 'Customer not reachable (4G)', false],
        ['4G-ACCESS', 'Site access delayed (4G)', false],
    ];

    /** The 4G flow: CX Tier II → 4G NOC → IP / RF / Core (and back) → NOC validates → CX Tier II confirms. */
    public const ROUTES = [
        'cx_tier2' => ['ticket_4g_noc'],
        'ticket_4g_noc' => ['ticket_ip', 'ticket_rf', 'ticket_core', 'cx_tier2'],
        'ticket_ip' => ['ticket_4g_noc', 'ticket_rf', 'ticket_core'],
        'ticket_rf' => ['ticket_4g_noc', 'ticket_ip', 'ticket_core'],
        'ticket_core' => ['ticket_4g_noc', 'ticket_ip', 'ticket_rf'],
    ];

    /** New roles: the three engineering teams. */
    public const ROLES = [
        '4G IP Team' => ['ticket.view', 'ticket.work', 'queue.view', 'queue.work_ticket_ip'],
        '4G RF Team' => ['ticket.view', 'ticket.work', 'queue.view', 'queue.work_ticket_rf'],
        '4G Core Team' => ['ticket.view', 'ticket.work', 'queue.view', 'queue.work_ticket_core'],
    ];

    /** What the 4G NOC roles are given for these tickets (added to what they have). */
    public const GRANTS = [
        '4G NOC' => ['ticket.view', 'ticket.work', 'queue.work_ticket_4g_noc'],
        '4G NOC Manager' => ['ticket.view', 'ticket.work', 'ticket.intervene', 'ticket.reopen', 'ticket.cancel', 'queue.work_ticket_4g_noc'],
    ];

    public function run(): void
    {
        $this->permissionsAndRoles();
        app(QueueDefinitionSeeder::class)->ticketing();

        $fourG = Vertical::query()->where('code', Vertical::MOBILITY_4G)->value('id');

        if (! $fourG) {
            return; // no 4G vertical on this installation: nothing to attach the catalogue to
        }

        $priority = Priority::query()->pluck('id', 'code');

        foreach (self::CATEGORIES as $c => [$code, $name, $first, $subcategories]) {
            $category = TicketCategory::query()->firstOrCreate(['code' => $code], ['name' => $name, 'vertical_id' => $fourG, 'sort_order' => 50 + $c, 'is_active' => true]);

            foreach ($subcategories as $s => $subcategory) {
                [$subName, $priorityCode] = is_array($subcategory) ? $subcategory : [$subcategory, 'medium'];

                TicketSubcategory::query()->firstOrCreate(['code' => $code.'-'.str($subName)->slug()->upper()->limit(45, '')], [
                    'ticket_category_id' => $category->id, 'name' => $subName, 'first_desk' => $first,
                    'allows_site_visit' => false, 'check_outage' => false, 'priority_id' => $priority[$priorityCode] ?? null,
                    'sort_order' => $s + 1, 'is_active' => true,
                ]);
            }
        }

        foreach (self::FINAL_REASONS as $i => $name) {
            FinalReason::query()->firstOrCreate(['name' => $name], ['applies_to' => 'ticket', 'vertical_id' => $fourG, 'sort_order' => 100 + $i, 'is_active' => true]);
        }

        foreach (self::DELAY_REASONS as $i => [$code, $name, $ours]) {
            DelayReason::query()->firstOrCreate(['code' => $code], ['name' => $name, 'within_control' => $ours, 'vertical_id' => $fourG, 'sort_order' => 100 + $i, 'is_active' => true]);
        }

        foreach (self::ROUTES as $from => $targets) {
            foreach ($targets as $i => $to) {
                DeskRoute::query()->firstOrCreate(['from_desk' => $from, 'to_desk' => $to], ['sort_order' => 50 + $i, 'is_active' => true]);
            }
        }

        $this->vendorTemplates();
    }

    private function permissionsAndRoles(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach ([QueueType::Ticket4gNoc, QueueType::TicketIp, QueueType::TicketRf, QueueType::TicketCore] as $desk) {
            Permission::findOrCreate($desk->defaultPermission(), 'web');
        }

        $existing = Permission::query()->pluck('name')->all();

        foreach (self::ROLES as $name => $permissions) {
            if (! Role::query()->where('name', $name)->where('guard_name', 'web')->exists()) {
                Role::findOrCreate($name, 'web')->givePermissionTo(array_values(array_intersect($permissions, $existing)));
            }
        }

        foreach (self::GRANTS as $name => $permissions) {
            $role = Role::query()->where('name', $name)->where('guard_name', 'web')->first();
            $missing = array_values(array_diff(array_intersect($permissions, $existing), $role?->permissions()->pluck('name')->all() ?? []));

            if ($role && $missing !== []) {
                $role->givePermissionTo($missing);
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /** E-mails to the vendor of a customer ticket (IP / RF / Core vendors), editable under 4G NOC → Vendor Templates. */
    private function vendorTemplates(): void
    {
        $type = OutageType::query()->where('code', OutageType::CODE_CX_TICKET)->value('id');

        if (! $type) {
            return;
        }

        $line = fn (string $label, string $value) => "<p><strong>{$label}:</strong> {$value}</p>";
        $details = $line('Ticket', '{{ticket_number}}').$line('Issue', '{{issue}}').$line('Team', '{{team}}').$line('Area', '{{location}}')
            .$line('Priority', '{{priority}}').$line('Description', '{{description}}');

        foreach ([
            'TKT_VENDOR_NEW' => [VendorTemplate::TICKET_CREATED, 'Customer ticket {{ticket_number}} — {{issue}} — action needed',
                '<p>Dear {{vendor_contact}},</p><p>We need your help on a 4G customer ticket.</p>'.$details.$line('Notes', '{{reason}}')
                .'<p>Please reply with your findings and expected time to fix. Contact: {{noc_contact}}.</p>'],
            'TKT_VENDOR_REMIND' => [VendorTemplate::REMINDER, 'Reminder: customer ticket {{ticket_number}} — awaiting your update',
                '<p>Dear {{vendor_contact}},</p><p>We have not yet received your update on this ticket.</p>'.$details
                .'<p>{{reason}}</p><p>Please reply with your progress. Contact: {{noc_contact}}.</p>'],
            'TKT_VENDOR_CLOSED' => [VendorTemplate::CLOSED, 'Customer ticket {{ticket_number}} — closed',
                '<p>Dear {{vendor_contact}},</p><p>The customer has confirmed the service is working; the ticket is closed. Thank you for your support.</p>'.$details],
        ] as $code => [$trigger, $subject, $body]) {
            VendorTemplate::query()->firstOrCreate(['code' => $code], [
                'name' => 'Customer ticket — '.VendorTemplate::TRIGGERS[$trigger], 'trigger' => $trigger, 'outage_type_id' => $type,
                'send_to_vendor' => true, 'cc_vendor_cc' => true, 'to' => [], 'cc' => [], 'subject' => $subject, 'body' => $body,
                'description' => 'Sent to the vendor of a 4G customer ticket (IP, RF or Core team).', 'is_active' => true,
            ]);
        }
    }
}
