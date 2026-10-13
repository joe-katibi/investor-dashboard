<?php

namespace Database\Seeders;

use App\Enums\QueueType;
use App\Models\DelayReason;
use App\Models\DeskRoute;
use App\Models\FinalReason;
use App\Models\Priority;
use App\Models\TicketCategory;
use App\Models\TicketSubcategory;
use App\Models\VisitType;
use App\Support\VisitPhotos;
use Illuminate\Database\Seeder;

/**
 * Starting catalogue of the customer ticketing module, taken from the ticket tracker in use:
 * visit types (INS / WiE / SUP / SHI), issue categories and subcategories with their routing,
 * reasons for delay (D01–D14), final reasons and the allowed desk-to-desk hand-offs.
 *
 * Create-only: every record is matched on its code / name and left alone when it exists, so
 * whatever is edited on screen survives re-seeding.
 */
class TicketCatalogSeeder extends Seeder
{
    /** [code, name, within our control, what fixes it] */
    public const DELAY_REASONS = [
        ['D01', 'Rescheduled by the customer', false, 'Customer-caused: record it; book the visit window when the ticket is logged.'],
        ['D02', 'Client unreachable', false, 'Confirm a second contact number at logging; SMS before dispatch.'],
        ['D03', 'Client not picking', false, 'Retry (3 attempts over 2 hours), then SMS; log every attempt.'],
        ['D04', 'Client phone on voice mail', false, 'Retry and SMS.'],
        ['D05', 'Weather challenges', false, 'Force majeure.'],
        ['D06', 'Affected by an outage', false, 'Area / core outage handled by NOC; the ticket is linked to the outage record.'],
        ['D07', 'Escalated to infrastructure', true, 'Internal hand-off: track the escalation turnaround and agree a target with Infrastructure.'],
        ['D08', 'Missed appointment', true, 'Crew scheduling and appointment reminders.'],
        ['D09', 'Raised late', true, 'Ticket logged late by the front line; coach on logging at first contact.'],
        ['D10', 'Raised under wrong department', true, 'Routing guide for agents; pick the right category at logging.'],
        ['D11', 'Re-escalation from another department', true, 'Review bounce-back cases between departments.'],
        ['D12', 'No electricity', false, 'Customer premises power; advise the customer and reschedule.'],
        ['D13', 'Access issue', false, 'Book access (landlord / estate) at logging; the Access team follows up.'],
        ['D14', 'Lack of CPEs', true, 'Van stock minimums for ONUs / routers; recover CPEs on disconnect.'],
    ];

    /** Field outcomes, as on the tracker's Lists tab. */
    public const VISIT_REASONS = [
        'ADSS cable / poles relocation done', 'Broken core along the line - Respliced', 'Broken core at the FAT Respliced', 'Burnt drop cable replaced',
        'Cable cut by an abnormal height lorry', 'Cable damaged by rodents - Replaced', 'Cable secured - sagging cable', 'Cable vandalized - Replaced',
        'Client CPE relocation', 'Configuration of SSID and password', 'Core re-spliced at the joint', 'Core with low / bad power swapped',
        'CPE damaged by the customer - swapped', 'CPE re-mounted', 'Damaged Ethernet cable replaced', 'Damaged ATB replaced', 'Damaged enclosure replaced',
        'Damaged FAT replaced', 'DLink reset and reconfigured', 'Dlink unplugged from power - powered', 'Downtime caused by competitor - Resolved',
        'Drop cable burnt due to UEDCL - Replaced', 'Drop cable cut by UMEME team', 'Drop cable cut by the gardener', 'Drop cable kink - Straightened',
        'Drop cable re-routed', 'Dusty coupler cleaned and link restored', 'Extender cable delivered and installed', 'Extender LAN cable disconnected - Reconnected',
        'Extender moved to a central location', 'Failing core at the FAT swapped', 'Faulty coupler replaced', 'Faulty ONT replaced',
        'Faulty ONU button - ONU swapped', 'Faulty RJ connectors replaced', 'Faulty splitter replaced', 'Faulty WIFI interface - Swapped',
        'Faulty / damaged Dlink - Client advised', 'Fiber drop cable re-spliced', 'Fiber drop replaced due to a cut', 'Fibre cable rerun - QoS',
        'Fibre cable rerun - QoS - ADSS', 'GPON drop cable patched back at the FAT', 'GPON ONU disconnected from patchcord', 'GPON - Faulty patchcord replaced',
        'GPON - Faulty pigtail replaced', 'GPON - Loose patchcord tightened', 'Hardline FOC cut - Respliced', 'Inhouse shifting done successfully',
        'Installation done successfully', 'IP conflict resolved', 'Kinked core at FAT - Straightened', 'Kinked / strained patch cord released',
        'Link reprovisioned and service resumed', 'Loose patch cord tightened', 'Loose pigtail at ONT / ATB tightened', 'Loose power adapter - Properly fixed',
        'Low hanging cable tensioned higher', 'Misplaced ONU - ONU lost', 'ONU not powering - Replaced', 'ONU overheating - Swapped',
        'ONU physically damaged - Replaced', 'ONU powered off / on standby - Switched on', 'ONU reset / configured',
        'ONU with only power light on - Faulty ONU replaced', 'Patchcord mismatch - Rectified', 'Patchcord missing - New patchcord issued',
        'Pole replaced / erected', 'Poor connectorization - Resolved', 'RJ45 connectors recrimped', 'Shifting done successfully',
        'Snapped fiber at FAT - Respliced', 'Splicing done at the ATB - Broken core', 'Splicing done at the FAT', 'Suspected faulty Dlink - Keeps resetting',
        'Unplugged RJ45 connector plugged back', 'WIFI interface disabled - Enabled', 'WPS button pressed - WIFI resumed', 'Proactive power optimisation complete',
        'ONU upgrade - Dual band', 'Faulty adapter replaced', 'Cable damaged, replaced - Poor weather conditions', 'Equipment picked up', 'Damaged pigtail replaced',
        'FAT installed and powered', 'FAT powered', 'MDU constructed', 'Port changed and reprovisioned', 'Cable damaged by construction workers - Replaced',
        'Extender configuration done', 'Client confirmed uptime upon site visit',
    ];

    /** Outcomes that apply to tickets and visits alike: [name, delay reason code it normally comes with]. */
    public const SHARED_REASONS = [
        ['Client affected by area outage', 'D06'],
        ['Client confirmed uptime upon call - Before site visit', null],
        ['No fault found - client found OK', null],
        ['User problem - Customer training done', null],
        ['High client expectation / over utilization', null],
        ['Short WIFI coverage - Advised to buy an extender', null],
        ['Will call back when available / needs our help', 'D01'],
        ['Area is NSR (not serviceable)', null],
        ['No package / account not active', null],
        ['Customer no longer interested', null],
    ];

    /** Outcomes of customer tickets worked at a desk. */
    public const TICKET_REASONS = [
        'Resolved remotely by CX Tier II', 'Resolved by NOC', 'Billing query resolved', 'Payment allocated / reflected', 'Package changed',
        'Account reconnected', 'Refund processed', 'Refund not done - customer withdrew the request', 'Credit applied to the account',
        'Request declined - customer advised', 'Information / enquiry answered', 'Complaint resolved - customer satisfied',
    ];

    /**
     * [category code, category name, [first desk, approval desk, fulfilment desk], visit type prefix, [subcategories…]]
     * A subcategory is "name" or [name, check for an ongoing outage, priority code].
     */
    public const CATEGORIES = [
        ['TECH', 'Technical Support', [QueueType::CxTier2, null, null], 'SUP', [
            ['LOS Flashing Red', true, 'high'], ['PON Flashing', true, 'high'], ['Persistent Offline', true, 'high'], 'Intermittent Connection',
            'Slow Speeds', 'Bad Optical power', 'Wifi Coverage', 'Faulty ONU', 'Faulty adaptor', 'Faulty LAN ports', 'Drop Cable Cut',
            'Drop Cable Sagging low', 'Cable Re-routing', 'ONU Upgrade', 'ONU Swap', 'Migration', 'Onu Delivery', 'Ethernet cable delivery',
            'Equipment Pickup', 'Pole Shifting', 'FAT Relocation',
        ]],
        ['EXT', 'Wi-Fi Extender', [QueueType::CxTier2, null, null], 'WiE', ['Extender Delivery', 'Extender Configuration', 'Survey for extender']],
        ['SHIFT', 'Shifting / Relocation', [QueueType::CxTier2, null, null], 'SHI', ['Shifting', 'Inhouse Shifting']],
        ['INST', 'Installation', [QueueType::CxTier2, null, null], 'INS', ['New Installation', 'Delayed Installation']],
        ['BILL', 'Billing', [QueueType::Billing, null, null], null, [
            'Wrong bill / double charge', 'Payment not reflected', 'Package change', 'Invoice / statement request', 'Suspension / reconnection',
        ]],
        // Commercial assesses; an approved refund goes to Billing to be paid out.
        ['REFUND', 'Refund', [QueueType::Commercial, QueueType::Commercial, QueueType::Billing], null, ['Refund request']],
        // CX Tier II vets (checks usage), the CX Manager approves, Billing applies the credit.
        ['CREDIT', 'Credit / Compensation', [QueueType::CxTier2, QueueType::CxManager, QueueType::Billing], null, ['Credit request - downtime', 'Credit request - other']],
        ['GEN', 'General', [QueueType::CxTier2, null, null], null, ['General enquiry', 'Complaint']],
    ];

    /** from desk => desks it may hand a ticket to. "Return" (back to where it came from) needs no route. */
    public const ROUTES = [
        'cx_tier2' => ['ticket_noc', 'billing', 'commercial', 'cx_manager', 'cx_callback'],
        'billing' => ['billing_manager', 'cx_callback', 'cx_tier2', 'commercial'],
        'billing_manager' => ['billing', 'cx_callback'],
        'commercial' => ['billing', 'cx_tier2', 'cx_callback'],
        'cx_manager' => ['billing', 'cx_tier2'],
        'cx_callback' => ['cx_tier2', 'billing'],
        'ticket_noc' => ['cx_tier2', 'infra_support_dispatch'],
        'visit_dispatch' => ['infra_support_dispatch', 'design', 'ticket_noc', 'access', 'cx_field_support'],
        'infra_support_dispatch' => ['infra_support_tech', 'design', 'ticket_noc', 'access'],
        'design' => ['access', 'infra_support_dispatch'],
        'access' => ['design'],
        'cx_field_support' => ['visit_dispatch'],
    ];

    public function run(): void
    {
        $priority = Priority::query()->pluck('id', 'code');

        // [prefix, name, default SLA minutes (null = the ticket setting), description]
        foreach ([
            ['INS', 'Installation', null, 'New customer installation.'],
            ['WiE', 'Wi-Fi Extender', null, 'Extender delivery, survey and configuration.'],
            ['SUP', 'Support', 180, 'Fault repair at the customer premises (LOS, bad optical power, faulty ONU, drop cable…).'],
            ['SHI', 'Shifting', 4320, 'Relocation of the service: in-house or to another address.'],
        ] as $i => [$prefix, $name, $sla, $description]) {
            VisitType::query()->firstOrCreate(['code_prefix' => $prefix], [
                'name' => $name, 'default_sla_minutes' => $sla, 'description' => $description, 'requires_evidence' => true, 'sort_order' => $i + 1, 'is_active' => true,
                // An installation and a relocation are completed with the nine photos; the others with two (App\Support\VisitPhotos).
                'photo_set' => in_array($prefix, ['INS', 'SHI'], true) ? VisitPhotos::INSTALLATION : VisitPhotos::STANDARD,
            ]);
        }

        foreach (self::DELAY_REASONS as $i => [$code, $name, $ours, $fix]) {
            DelayReason::query()->firstOrCreate(['code' => $code], [
                'name' => $name, 'within_control' => $ours, 'description' => $fix, 'sort_order' => $i + 1, 'is_active' => true,
            ]);
        }

        $delay = DelayReason::query()->pluck('id', 'code');
        $sort = 0;

        foreach (self::SHARED_REASONS as [$name, $code]) {
            FinalReason::query()->firstOrCreate(['name' => $name], ['applies_to' => null, 'delay_reason_id' => $code ? ($delay[$code] ?? null) : null, 'sort_order' => ++$sort, 'is_active' => true]);
        }

        foreach (self::TICKET_REASONS as $name) {
            FinalReason::query()->firstOrCreate(['name' => $name], ['applies_to' => 'ticket', 'sort_order' => ++$sort, 'is_active' => true]);
        }

        foreach (self::VISIT_REASONS as $name) {
            FinalReason::query()->firstOrCreate(['name' => $name], ['applies_to' => 'visit', 'sort_order' => ++$sort, 'is_active' => true]);
        }

        $visitTypes = VisitType::query()->pluck('id', 'code_prefix');

        foreach (self::CATEGORIES as $c => [$code, $name, [$first, $approval, $fulfilment], $visitPrefix, $subcategories]) {
            $category = TicketCategory::query()->firstOrCreate(['code' => $code], ['name' => $name, 'sort_order' => $c + 1, 'is_active' => true]);

            foreach ($subcategories as $s => $subcategory) {
                [$subName, $checkOutage, $priorityCode] = is_array($subcategory) ? $subcategory : [$subcategory, false, 'medium'];

                TicketSubcategory::query()->firstOrCreate(['code' => $code.'-'.str($subName)->slug()->upper()->limit(50, '')], [
                    'ticket_category_id' => $category->id,
                    'name' => $subName,
                    'first_desk' => $first,
                    'approval_desk' => $approval,
                    'fulfilment_desk' => $fulfilment,
                    'allows_site_visit' => $visitPrefix !== null,
                    'visit_type_id' => $visitPrefix ? ($visitTypes[$visitPrefix] ?? null) : null,
                    'check_outage' => $checkOutage,
                    'priority_id' => $priority[$priorityCode] ?? null,
                    'sort_order' => $s + 1,
                    'is_active' => true,
                ]);
            }
        }

        foreach (self::ROUTES as $from => $targets) {
            foreach ($targets as $i => $to) {
                DeskRoute::query()->firstOrCreate(['from_desk' => $from, 'to_desk' => $to], ['sort_order' => $i + 1, 'is_active' => true]);
            }
        }
    }
}
