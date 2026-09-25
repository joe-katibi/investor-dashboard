<?php

namespace Database\Seeders;

use App\Enums\StatusCode;
use App\Models\OutageStatus;
use Illuminate\Database\Seeder;

/** Status catalogue (spec §10). Workflow statuses carry a stable code the engine relies on. */
class OutageStatusSeeder extends Seeder
{
    public function run(): void
    {
        // [code, name, colour, prompt final reason, closed, resolved, pauses SLA, manually selectable, sort]
        foreach ([
            [StatusCode::SupportUnconfirmed, 'Support Unconfirmed Outage', 'gray', false, false, false, false, false, 1],
            [StatusCode::NocConfirmed, 'NOC Confirmed Outage', 'info', false, false, false, false, false, 2],
            [StatusCode::NocReject, 'NOC Reject', 'danger', true, true, false, false, false, 3],
            [StatusCode::AssignedToInfrastructure, 'Assigned to Infrastructure', 'warning', false, false, false, false, false, 4],
            [StatusCode::InfrastructureConfirmed, 'Infrastructure Confirmed Outage', 'warning', false, false, false, false, true, 5],
            [StatusCode::TeamDispatched, 'Team Dispatched', 'primary', false, false, false, false, false, 6],
            [StatusCode::AwaitingFieldAccess, 'Awaiting Field Access', 'warning', true, false, false, false, true, 7],
            [StatusCode::VendorEscalated, 'Vendor Escalated', 'warning', false, false, false, false, true, 8],
            [StatusCode::PartialRestore, 'Partial Restore', 'info', false, false, false, false, true, 9],
            [StatusCode::RestoreReported, 'Restore Reported – Awaiting NOC', 'info', false, false, false, false, false, 10],
            [StatusCode::NocRestoreConfirmed, 'NOC Restore Confirmed', 'success', false, false, false, false, false, 11],
            [StatusCode::AwaitingCustomerConfirmation, 'Awaiting Customer Confirmation', 'info', true, false, false, false, true, 12],
            [StatusCode::Resolved, 'Outage Resolved', 'success', true, true, true, false, false, 13],
            [StatusCode::ScheduledMaintenance, 'Scheduled Maintenance', 'gray', false, false, false, true, true, 14],
        ] as [$code, $name, $color, $prompt, $closed, $resolved, $pauses, $manual, $sort]) {
            OutageStatus::query()->updateOrCreate(['code' => $code->value], [
                'name' => $name, 'color' => $color, 'prompt_final_reason' => $prompt, 'is_closed' => $closed,
                'is_resolved' => $resolved, 'pauses_sla' => $pauses, 'is_manual_selectable' => $manual,
                'is_system' => true, 'sort_order' => $sort, 'is_active' => true,
            ]);
        }
    }
}
