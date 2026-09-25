<?php

namespace Database\Seeders;

use App\Models\OutageType;
use App\Models\Priority;
use App\Models\SlaPolicy;
use Illuminate\Database\Seeder;

/** SLA policies (spec §11). Requires PrioritySeeder and OutageTypeSeeder. */
class SlaPolicySeeder extends Seeder
{
    public function run(): void
    {
        $priority = Priority::query()->pluck('id', 'code');

        // [name, priority, resolution min, pre-breach min, ack timeout min, escalate after min]
        foreach ([
            ['Critical – 4h', 'critical', 240, 60, 10, 120],
            ['High – 8h', 'high', 480, 60, 15, 240],
            ['Medium – 12h', 'medium', 720, 120, 20, null],
            ['Low – 24h', 'low', 1440, 180, 30, null],
        ] as [$name, $code, $resolution, $preBreach, $ack, $escalation]) {
            SlaPolicy::query()->updateOrCreate(['name' => $name], [
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

        SlaPolicy::query()->updateOrCreate(['name' => 'Planned Maintenance – window'], [
            'outage_type_id' => OutageType::query()->where('code', 'planned')->value('id'),
            'resolution_minutes' => 480,
            'pre_breach_minutes' => 60,
            'ack_timeout_minutes' => 60,
            'notify_credit' => false,
            'notify_sms' => true,
            'allow_pause' => true,
            'is_active' => true,
        ]);
    }
}
