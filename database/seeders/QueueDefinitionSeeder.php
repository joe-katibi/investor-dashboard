<?php

namespace Database\Seeders;

use App\Enums\QueueType;
use App\Models\Department;
use App\Models\QueueDefinition;
use Illuminate\Database\Seeder;

/** FIFO queues (spec §27). Requires DepartmentSeeder. */
class QueueDefinitionSeeder extends Seeder
{
    public function run(): void
    {
        $department = Department::query()->pluck('id', 'code');

        // [queue type, name, department code, acknowledgement timeout min]
        foreach ([
            [QueueType::NocReview, 'NOC Review Queue', 'NOC', 10],
            [QueueType::Infrastructure, 'Infrastructure Dispatch Queue', 'INFRA', 15],
            [QueueType::Technician, 'Technician Queue', 'INFRA', 20],
            [QueueType::NocRestore, 'NOC Restore Confirmation Queue', 'NOC', 15],
        ] as [$type, $name, $departmentCode, $ack]) {
            QueueDefinition::query()->updateOrCreate(['queue_type' => $type->value, 'name' => $name], [
                'department_id' => $department[$departmentCode] ?? null,
                'required_permission' => $type->defaultPermission(),
                'assignment_mode' => 'auto',
                'ack_timeout_minutes' => $ack,
                'escalate_after_attempts' => 3,
                'is_active' => true,
            ]);
        }
    }
}
