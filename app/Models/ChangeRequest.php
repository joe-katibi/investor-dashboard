<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasAuditColumns;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Maintenance & Change Request Form (CRF) for a planned maintenance ticket. Mirrors the paper
 * form: general details, risk tier, technical scope & fallback, operational checklist,
 * post-maintenance outcome and sign-offs.
 */
#[Fillable([
    'outage_id', 'initiator', 'department', 'in_charge', 'scheduled_start', 'scheduled_end', 'escalation_time',
    'rollback_time', 'emergency_contact', 'risk_tier', 'work_description', 'fallback_plan', 'prior_tests',
    'risks_mitigation', 'checklist', 'stakeholder_emails', 'customer_list_path', 'customer_list_name',
])]
class ChangeRequest extends Model
{
    use Auditable, HasAuditColumns;

    public const DRAFT = 'draft';

    public const PENDING_SIGNOFF = 'pending_signoff';

    public const CHANGES_REQUESTED = 'changes_requested';

    public const APPROVED = 'approved';

    public const CANCELLED = 'cancelled';

    public const STATUSES = [
        self::DRAFT => 'Draft — NOC review',
        self::PENDING_SIGNOFF => 'Awaiting sign-off',
        self::CHANGES_REQUESTED => 'Changes requested',
        self::APPROVED => 'Signed & approved',
        self::CANCELLED => 'Cancelled',
    ];

    public const RISK_TIERS = [
        'low' => ['Low Priority', 'Minor impact, redundancy available'],
        'medium' => ['Medium Priority', 'Moderate impact, minimal service disruption'],
        'high' => ['High Priority', 'High impact, single point of failure risk'],
        'emergency' => ['Emergency', 'Immediate intervention required'],
    ];

    /** Section 4 — operational checklist & pre-requisites: [item, target audience]. */
    public const CHECKLIST = [
        'customers_identified' => ['Affected Customers / Services Identified', 'Customer List / Service ID Attached'],
        'account_manager_approval' => ['Customer Service / Account Manager Approval', 'Client Relationship Management'],
        'pre_alerts_broadcast' => ['Pre-Maintenance Alerts Broadcast', 'Clients, NOC & CE Managers'],
        'post_alert_prepared' => ['Post-Maintenance Completion Alert Prepared', 'NOC, CE & CE Managers'],
    ];

    public const FINAL_STATUSES = [
        'successful' => 'Successful',
        'rolled_back' => 'Rolled Back',
        'partial' => 'Partially Successful / Minor Issues',
    ];

    protected function casts(): array
    {
        return [
            'scheduled_start' => 'datetime',
            'scheduled_end' => 'datetime',
            'checklist' => 'array',
            'stakeholder_emails' => 'array',
            'notification_channels' => 'array',
            'sent_for_signoff_at' => 'datetime',
            'approved_at' => 'datetime',
            'started_notified_at' => 'datetime',
            'customers_notified_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function outage(): BelongsTo
    {
        return $this->belongsTo(Outage::class);
    }

    public function signatures(): HasMany
    {
        return $this->hasMany(ChangeRequestSignature::class)->orderBy('sort')->orderBy('id');
    }

    public function reschedules(): HasMany
    {
        return $this->hasMany(ChangeRequestReschedule::class)->latest('id');
    }

    public function customersNotifiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'customers_notified_by');
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }

    public function riskLabel(): string
    {
        return self::RISK_TIERS[$this->risk_tier][0] ?? (string) $this->risk_tier;
    }

    /** e.g. "4 hours", "1 hour 30 minutes". */
    public function expectedDowntime(): string
    {
        $minutes = (int) max(0, $this->scheduled_start?->diffInMinutes($this->scheduled_end) ?? 0);
        $hours = intdiv($minutes, 60);
        $rest = $minutes % 60;

        return trim(($hours ? $hours.' hour'.($hours === 1 ? '' : 's') : '').($rest ? " {$rest} minutes" : '')) ?: '0 minutes';
    }

    public function signedCount(): int
    {
        return $this->signatures->where('status', ChangeRequestSignature::APPROVED)->count();
    }

    public function fullySigned(): bool
    {
        return $this->signatures->isNotEmpty()
            && $this->signatures->every(fn (ChangeRequestSignature $s) => $s->status === ChangeRequestSignature::APPROVED);
    }

    public function pdfFileName(): string
    {
        return 'CRF-'.$this->outage?->ticket_number.'-'.str($this->outage?->title)->slug()->limit(60, '')->upper().'.pdf';
    }
}
