<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasAuditColumns;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Editable planned-maintenance e-mail. The trigger decides when it is sent; recipients are the
 * configured stakeholders and/or the CRF signatories plus any fixed TO / CC addresses.
 */
#[Fillable([
    'name', 'code', 'trigger', 'offset_minutes', 'send_to_stakeholders', 'send_to_signatories', 'to', 'cc',
    'attach_crf', 'subject', 'body', 'description', 'is_active',
])]
class MaintenanceTemplate extends Model
{
    use Auditable, HasAuditColumns;

    public const TRIGGERS = [
        'crf_issued' => 'CRF issued for review (NOC sends it for sign-off)',
        'sign_request' => 'Sign-off request — one e-mail per signatory with a “Sign CRF” button',
        'fully_signed' => 'CRF fully signed',
        'changes_requested' => 'A signatory requested changes',
        'reminder' => 'Reminders before the start (times set in Maintenance Settings)',
        'before_start' => 'Once, a set number of minutes before the start',
        'started' => 'Maintenance window starts',
        'rescheduled' => 'Maintenance rescheduled',
        'completed' => 'Completed — CX has notified customers',
        'cancelled' => 'Maintenance cancelled',
    ];

    /** Placeholders available in maintenance templates. */
    public const PLACEHOLDERS = [
        'ticket', 'title', 'scheduled_date', 'start_time', 'end_time', 'scheduled_window', 'expected_downtime',
        'time_left', 'location', 'olt_fdt', 'customers', 'services', 'initiator', 'department', 'in_charge',
        'emergency_contact', 'risk', 'work_description', 'fallback_plan', 'prior_tests', 'risks_mitigation',
        'crf_status', 'signed_count', 'signatory_count', 'pending_signatories', 'signatory_name', 'signatory_role',
        'reason', 'previous_window', 'final_status', 'outcome_details', 'team', 'technician', 'sign_button', 'ticket_button',
    ];

    protected function casts(): array
    {
        return [
            'to' => 'array',
            'cc' => 'array',
            'offset_minutes' => 'integer',
            'send_to_stakeholders' => 'boolean',
            'send_to_signatories' => 'boolean',
            'attach_crf' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    public function scopeForTrigger(Builder $query, string $trigger): void
    {
        $query->where('trigger', $trigger);
    }
}
