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
            [QueueType::CxFollowUp, 'CX Tier II Follow-up Queue', 'CX', 30],
            [QueueType::SupportTechnician, 'Support Technician Queue', 'DISPATCH', 20],
            [QueueType::ItReview, 'IT Review Queue', 'IT', 15],
            [QueueType::ItConfirmation, 'IT Confirmation Queue', 'IT', 15],
            [QueueType::CxNotify, 'CX Customer Notification Queue', 'CX', 30],
            // 4G NOC: engineers / technicians are assigned by the NOC; verification goes to the next available NOC user.
            [QueueType::MobilityEngineer, '4G Engineer / Technician Queue', 'MOBILITY', 30],
            [QueueType::MobilityNoc, '4G NOC Verification Queue', 'MOBILITY', 15],
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
