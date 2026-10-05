<?php

namespace Database\Seeders;

use App\Models\MajorOutageType;
use App\Models\OutageType;
use App\Models\Vertical;
use Illuminate\Database\Seeder;

/** Outage types and ticket prefixes (spec §5). Requires MajorOutageTypeSeeder and VerticalSeeder. */
class OutageTypeSeeder extends Seeder
{
    public function run(): void
    {
        $major = MajorOutageType::query()->pluck('id', 'code');

        // [code, name, major type, prefix, planned, emergency, sort, field maintenance kind]
        foreach ([
            ['regular', 'Regular Outage', MajorOutageType::NETWORK, 'OUT', false, false, 1, null],
            ['emergency', 'Emergency Outage', MajorOutageType::NETWORK, 'EMO', false, true, 2, null],
            ['planned', 'Planned Maintenance', MajorOutageType::NETWORK, 'PLM', true, false, 3, null],
            ['core', 'SEACOM / Core Service Outage', MajorOutageType::CORE, 'CORE', false, false, 4, null],
            ['system', 'System Outage', MajorOutageType::SYSTEM, 'SYS', false, false, 5, null],
            // Raised on site by an Infrastructure technician (with before / after photos).
            ['preventive', 'Preventive Maintenance', MajorOutageType::NETWORK, 'PVM', false, false, 6, OutageType::PREVENTIVE],
            ['corrective', 'Corrective Maintenance', MajorOutageType::NETWORK, 'CRM', false, false, 7, OutageType::CORRECTIVE],
        ] as [$code, $name, $majorCode, $prefix, $planned, $emergency, $sort, $kind]) {
            OutageType::query()->updateOrCreate(['code' => $code], [
                'name' => $name,
                'major_outage_type_id' => $major[$majorCode],
                'prefix' => $prefix,
                'is_planned' => $planned,
                'is_emergency' => $emergency,
                // Reported restored with the three site photos (findings, after restoration, power readings).
                'requires_site_photos' => in_array($code, ['regular', 'emergency'], true),
                'maintenance_kind' => $kind,
                'sort_order' => $sort,
                'is_active' => true,
            ]);
        }

        $this->mobility();
        $this->ticketing();
    }

    /**
     * The two system types of the customer ticketing module. Customer tickets are numbered
     * TKT-000123; site visits by their visit type (INS-1, WiE-1, SUP-1, SHI-1).
     * Created only when missing. Requires the "Customer Ticket" major type.
     */
    public function ticketing(): void
    {
        $major = MajorOutageType::query()->where('code', MajorOutageType::CUSTOMER)->value('id');
        $vertical = Vertical::query()->where('code', Vertical::FTTH)->value('id');

        // [code, name, prefix, workflow, sort, description]
        foreach ([
            [OutageType::CODE_CX_TICKET, 'Customer Ticket', 'TKT', OutageType::WORKFLOW_CX_TICKET, 30,
                'Raised by CX for a customer (billing, refund, credit, troubleshooting) and worked desk by desk until closed.'],
            [OutageType::CODE_SITE_VISIT, 'Site Visit', 'SV', OutageType::WORKFLOW_SITE_VISIT, 31,
                'Appointment at the customer premises: dispatcher → technician → dispatcher → CX Field Support confirms.'],
        ] as [$code, $name, $prefix, $workflow, $sort, $description]) {
            OutageType::query()->firstOrCreate(['code' => $code], [
                'name' => $name,
                'major_outage_type_id' => $major,
                'vertical_id' => $vertical,
                'prefix' => $prefix,
                'workflow' => $workflow,
                'description' => $description,
                'sort_order' => $sort,
                'is_active' => true,
            ]);
        }
    }

    /**
     * 4G NOC outage types. More (fibre cut, battery, generator…) can be added under Configuration →
     * Outage Types: each one only picks a workflow, a fault domain and a prefix.
     * Created only when missing, so edited types survive re-seeding. Requires the 4G major type.
     */
    public function mobility(): void
    {
        $major = MajorOutageType::query()->where('code', MajorOutageType::MOBILITY)->value('id');
        $vertical = Vertical::query()->where('code', Vertical::MOBILITY_4G)->value('id');

        // [code, name, prefix, workflow, fault domain, sort, description]
        foreach ([
            ['mobility_enodeb', 'eNodeB Outage', '4G-ENB', OutageType::WORKFLOW_MOBILITY_INTERNAL, OutageType::DOMAIN_ENODEB, 20,
                'One cell, several cells or the whole 4G site (eNodeB) down. 4G NOC → Engineer / Technician → 4G NOC.'],
            ['mobility_transmission', 'Transmission Outage', '4G-TRX', OutageType::WORKFLOW_MOBILITY_INTERNAL, OutageType::DOMAIN_TRANSMISSION, 21,
                'Transmission node / link failure (SSG, CSG, ASG, RSG; microwave or fibre). 4G NOC → Transmission Engineer → 4G NOC.'],
            ['mobility_power', 'Power Outage', '4G-PWR', OutageType::WORKFLOW_MOBILITY_VENDOR, OutageType::DOMAIN_POWER, 22,
                'Power failure at a site, handled by the power vendor (ATC, TOA, UTEL…). 4G NOC → Vendor → NOC records vendor updates → NOC verifies.'],
        ] as [$code, $name, $prefix, $workflow, $domain, $sort, $description]) {
            OutageType::query()->firstOrCreate(['code' => $code], [
                'name' => $name,
                'major_outage_type_id' => $major,
                'vertical_id' => $vertical,
                'prefix' => $prefix,
                'workflow' => $workflow,
                'fault_domain' => $domain,
                'description' => $description,
                'sort_order' => $sort,
                'is_active' => true,
            ]);
        }
    }
}
