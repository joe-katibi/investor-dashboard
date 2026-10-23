<?php

namespace Database\Seeders;

use App\Models\OutageType;
use App\Models\Priority;
use App\Models\SlaPolicy;
use App\Models\Vertical;
use Illuminate\Database\Seeder;

/** SLA policies (spec §11). Requires PrioritySeeder and OutageTypeSeeder. */
class SlaPolicySeeder extends Seeder
{
    public function run(): void
    {
        $priority = Priority::query()->pluck('id', 'code');

        // [name, priority, resolution min, pre-breach min, ack timeout min, escalate after min]
        // The agreed FTTH service level: restored within 22 hours, whatever the priority.
        foreach ([
            ['Critical – 22h', 'critical', 1320, 60, 10, 120],
            ['High – 22h', 'high', 1320, 60, 15, 240],
            ['Medium – 22h', 'medium', 1320, 120, 20, null],
            ['Low – 22h', 'low', 1320, 180, 30, null],
        ] as [$name, $code, $resolution, $preBreach, $ack, $escalation]) {
            // firstOrCreate: policies are edited under SLA Policies and must survive re-seeding on deploy.
            SlaPolicy::query()->firstOrCreate(['name' => $name], [
                'priority_id' => $priority[$code] ?? null,
                'resolution_minutes' => $resolution,
                'pre_breach_minutes' => $preBreach,
                'ack_timeout_minutes' => $ack,
                'escalation_minutes' => $escalation,
                'escalation_roles' => $escalation ? ['NOC Manager', 'Infrastructure Manager'] : null,
                'notify_roles' => ['NOC Team Leader'],
                'notify_credit' => true,
                'notify_sms' => true,
                'allow_pause' => true,
                'is_active' => true,
            ]);
        }

        // Planned maintenance: 6 hours from the scheduled start until CX has told customers it is done.
        // Change it under Configuration → SLA Policies; re-seeding never overwrites an edited policy.
        $planned = SlaPolicy::query()->firstOrCreate(['name' => 'Planned Maintenance – window'], [
            'outage_type_id' => OutageType::query()->where('code', 'planned')->value('id'),
            'resolution_minutes' => 360,
            'pre_breach_minutes' => 60,
            'ack_timeout_minutes' => 60,
            'notify_credit' => false,
            'notify_sms' => true,
            'allow_pause' => true,
            'is_active' => true,
        ]);

        // Existing installations still on the old untouched 8-hour default move to 6 hours.
        if ((int) $planned->resolution_minutes === 480 && $planned->updated_by === null) {
            $planned->forceFill(['resolution_minutes' => 360])->save();
        }

        $this->mobility();
    }

    /**
     * 4G: its own targets per severity (vertical + priority). Starting values — set the agreed
     * times under Configuration → SLA Policies; a policy can also be narrowed to one outage type
     * (eNodeB / Transmission / Power) or one vendor. Created only when missing.
     */
    public function mobility(): void
    {
        $priority = Priority::query()->pluck('id', 'code');
        $mobility = Vertical::query()->where('code', Vertical::MOBILITY_4G)->value('id');

        // [name, priority, resolution min, pre-breach min, escalate after min]
        foreach ([
            ['4G Critical – 4h', Priority::MOBILITY_CRITICAL, 240, 60, 120],
            ['4G Major – 8h', Priority::MOBILITY_MAJOR, 480, 60, 240],
            ['4G Minor – 24h', Priority::MOBILITY_MINOR, 1440, 180, null],
        ] as [$name, $code, $resolution, $preBreach, $escalation]) {
            if (! $mobility || ! isset($priority[$code])) {
                continue;
            }

            SlaPolicy::query()->firstOrCreate(['name' => $name], [
                'vertical_id' => $mobility,
                'priority_id' => $priority[$code],
                'resolution_minutes' => $resolution,
                'pre_breach_minutes' => $preBreach,
                'escalation_minutes' => $escalation,
                'escalation_roles' => $escalation ? ['4G NOC Manager'] : null,
                'notify_roles' => ['4G NOC Manager'],
                'notify_credit' => false,
                'notify_sms' => false,
                'allow_pause' => true,
                'is_active' => true,
            ]);
        }
    }
}
