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

        $this->mobility();
        $this->ticketing();
    }

    /**
     * One queue per customer ticket / site visit desk. No department: who works a desk is decided
     * by the desk permission (and the regions a person is locked to); set a department or a team
     * on the queue to narrow it. Created only when missing.
     */
    public function ticketing(): void
    {
        foreach (QueueType::ticketing() as $desk) {
            QueueDefinition::query()->firstOrCreate(['queue_type' => $desk->value, 'name' => $desk->getLabel().' Queue'], [
                'required_permission' => $desk->defaultPermission(),
                'assignment_mode' => 'auto',
                // Technicians are given visits ahead of time (often for another day): no acceptance deadline,
                // the dispatcher re-assigns. The desks take a ticket back when it is not accepted in time.
                'ack_timeout_minutes' => match (true) {
                    $desk->isFieldDesk() => 0,
                    $desk === QueueType::TicketFollowUp => 60,
                    default => 30,
                },
                'escalate_after_attempts' => 3,
                'sort_order' => 20,
                'is_active' => true,
            ]);
        }
    }

    /**
     * 4G NOC queues: engineers / technicians are assigned by the NOC; verification goes to the next
     * available NOC user. Created only when missing, so edited queues survive re-seeding.
     */
    public function mobility(): void
    {
        $department = Department::query()->where('code', QueueType::MobilityNoc->defaultDepartmentCode())->value('id');

        foreach ([
            [QueueType::MobilityEngineer, '4G Engineer / Technician Queue', 30],
            [QueueType::MobilityNoc, '4G NOC Verification Queue', 15],
        ] as [$type, $name, $ack]) {
            QueueDefinition::query()->firstOrCreate(['queue_type' => $type->value, 'name' => $name], [
                'department_id' => $department,
                'required_permission' => $type->defaultPermission(),
                'assignment_mode' => 'auto',
                'ack_timeout_minutes' => $ack,
                'escalate_after_attempts' => 3,
                'is_active' => true,
            ]);
        }
    }
}
