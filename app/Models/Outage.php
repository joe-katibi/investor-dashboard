<?php

namespace App\Models;

use App\Enums\QueueEntryStatus;
use App\Enums\QueueType;
use App\Enums\SlaState;
use App\Enums\TeamType;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasAuditColumns;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Lifecycle fields (status, timestamps, SLA, assignment) are written by the workflow and
 * FIFO services only; they are deliberately excluded from mass assignment.
 */
#[Fillable([
    'title', 'major_outage_type_id', 'outage_type_id', 'vertical_id', 'priority_id', 'priority_is_manual',
    'description', 'location', 'customers_affected', 'sites_down', 'cells_down', 'power_faults',
    'start_time', 'team_type', 'team_id', 'sub_team_id', 'technician_id',
])]
class Outage extends Model
{
    use Auditable, HasAuditColumns;

    protected function casts(): array
    {
        return [
            'priority_is_manual' => 'boolean',
            'is_closed' => 'boolean',
            'customers_affected' => 'integer',
            'sites_down' => 'integer',
            'cells_down' => 'integer',
            'power_faults' => 'integer',
            'team_type' => TeamType::class,
            'current_queue' => QueueType::class,
            'sla_state' => SlaState::class,
            'credit_shared' => 'boolean',
            'sms_sent' => 'boolean',
            'start_time' => 'datetime',
            'end_time' => 'datetime',
            'sla_due_at' => 'datetime',
            'sla_pre_breach_at' => 'datetime',
            'sla_paused_at' => 'datetime',
            'sla_pre_breach_notified_at' => 'datetime',
            'sla_breach_notified_at' => 'datetime',
            'sla_escalated_at' => 'datetime',
            'credit_updated_at' => 'datetime',
            'sms_updated_at' => 'datetime',
            'noc_escalated_at' => 'datetime',
            'noc_confirmed_at' => 'datetime',
            'noc_rejected_at' => 'datetime',
            'infra_escalated_at' => 'datetime',
            'dispatched_at' => 'datetime',
            'restored_at' => 'datetime',
            'restore_confirmed_at' => 'datetime',
            'resolved_at' => 'datetime',
            'cx_followed_up_at' => 'datetime',
            'it_escalated_to_noc_at' => 'datetime',
            'noc_returned_to_it_at' => 'datetime',
            'it_confirmed_at' => 'datetime',
        ];
    }

    // ---------------------------------------------------------------- relations

    public function majorOutageType(): BelongsTo
    {
        return $this->belongsTo(MajorOutageType::class);
    }

    public function outageType(): BelongsTo
    {
        return $this->belongsTo(OutageType::class);
    }

    public function vertical(): BelongsTo
    {
        return $this->belongsTo(Vertical::class);
    }

    public function priority(): BelongsTo
    {
        return $this->belongsTo(Priority::class);
    }

    public function status(): BelongsTo
    {
        return $this->belongsTo(OutageStatus::class, 'status_id');
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function subTeam(): BelongsTo
    {
        return $this->belongsTo(Team::class, 'sub_team_id');
    }

    public function technician(): BelongsTo
    {
        return $this->belongsTo(User::class, 'technician_id');
    }

    public function currentAssignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'current_assignee_id');
    }

    public function slaPolicy(): BelongsTo
    {
        return $this->belongsTo(SlaPolicy::class);
    }

    public function creditUpdatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'credit_updated_by');
    }

    public function smsUpdatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sms_updated_by');
    }

    public function nocConfirmedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'noc_confirmed_by');
    }

    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    public function olts(): BelongsToMany
    {
        return $this->belongsToMany(Olt::class, 'outage_olt');
    }

    public function fdts(): BelongsToMany
    {
        return $this->belongsToMany(Fdt::class, 'outage_fdt');
    }

    public function slots(): BelongsToMany
    {
        return $this->belongsToMany(Slot::class, 'outage_slot');
    }

    public function ports(): BelongsToMany
    {
        return $this->belongsToMany(Port::class, 'outage_port');
    }

    public function services(): BelongsToMany
    {
        return $this->belongsToMany(Service::class, 'outage_service');
    }

    public function statusHistories(): HasMany
    {
        return $this->hasMany(OutageStatusHistory::class)->latest('id');
    }

    public function updates(): HasMany
    {
        return $this->hasMany(OutageUpdate::class)->latest('id');
    }

    public function flagEvents(): HasMany
    {
        return $this->hasMany(OutageFlagEvent::class)->latest('id');
    }

    public function slaEvents(): HasMany
    {
        return $this->hasMany(SlaEvent::class)->latest('id');
    }

    public function queueEntries(): HasMany
    {
        return $this->hasMany(OutageQueueEntry::class);
    }

    public function openQueueEntries(): HasMany
    {
        return $this->queueEntries()->whereIn('status', QueueEntryStatus::openValues());
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(OutageAssignment::class)->latest('id');
    }

    public function activeAssignments(): HasMany
    {
        return $this->hasMany(OutageAssignment::class)->whereIn('status', ['assigned', 'accepted']);
    }

    public function currentAssignment(): HasOne
    {
        return $this->hasOne(OutageAssignment::class)->ofMany(['id' => 'max'], function (Builder $query): void {
            $query->whereIn('status', ['assigned', 'accepted']);
        });
    }

    public function assignmentHistories(): HasMany
    {
        return $this->hasMany(OutageAssignmentHistory::class)->latest('id');
    }

    public function notificationLogs(): HasMany
    {
        return $this->hasMany(NotificationLog::class)->latest('id');
    }

    public function followUps(): HasMany
    {
        return $this->hasMany(OutageFollowUp::class)->latest('id');
    }

    public function supportRequests(): HasMany
    {
        return $this->hasMany(SupportRequest::class)->latest('id');
    }

    public function openSupportRequest(): HasOne
    {
        return $this->hasOne(SupportRequest::class)->ofMany(['id' => 'max'], function (Builder $query): void {
            $query->where('status', SupportRequest::OPEN);
        });
    }

    public function itEscalatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'it_escalated_by');
    }

    public function itConfirmedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'it_confirmed_by');
    }

    // ---------------------------------------------------------------- scopes

    public function scopeActive(Builder $query): void
    {
        $query->where('is_closed', false);
    }

    public function scopeOfMajorType(Builder $query, string $code): void
    {
        $query->whereHas('majorOutageType', fn (Builder $q) => $q->where('code', $code));
    }

    public function scopeOfVertical(Builder $query, string $code): void
    {
        $query->whereHas('vertical', fn (Builder $q) => $q->where('code', $code));
    }

    public function scopeCritical(Builder $query): void
    {
        $query->whereHas('priority', fn (Builder $q) => $q->where('code', Priority::CRITICAL));
    }

    // ---------------------------------------------------------------- helpers

    public function isFtth(): bool
    {
        return $this->vertical?->code === Vertical::FTTH;
    }

    public function is4g(): bool
    {
        return $this->vertical?->code === Vertical::MOBILITY_4G;
    }

    /** Human-readable topology path, e.g. "Salaama › FDT 6 › Slot 3 › Port 15". */
    public function topologyPath(): string
    {
        $parts = [];

        foreach (['olts', 'fdts', 'slots', 'ports'] as $relation) {
            $names = $this->{$relation}->pluck('name')->implode(', ');
            if ($names !== '') {
                $parts[] = $names;
            }
        }

        return implode(' › ', $parts);
    }

    /** Minutes the outage has been open (or took, when closed). */
    /**
     * SLA state as of now for display: open, running clocks are evaluated against the due times so
     * screens never show a stale "Compliant" if the scheduler is delayed. Closed / paused outages
     * keep the stored state.
     */
    public function currentSlaState(): ?SlaState
    {
        if ($this->is_closed || $this->sla_paused_at || ! $this->sla_due_at) {
            return $this->sla_state;
        }

        return match (true) {
            now()->greaterThanOrEqualTo($this->sla_due_at) => SlaState::Breached,
            $this->sla_pre_breach_at && now()->greaterThanOrEqualTo($this->sla_pre_breach_at) => SlaState::AtRisk,
            default => SlaState::Compliant,
        };
    }

    public function elapsedMinutes(): int
    {
        $end = $this->end_time ?? $this->resolved_at ?? now();

        return (int) max(0, $this->start_time?->diffInMinutes($end) ?? 0);
    }

    public function elapsedForHumans(): string
    {
        $minutes = $this->elapsedMinutes();

        return $minutes >= 60 ? intdiv($minutes, 60).'h '.($minutes % 60).'m' : $minutes.'m';
    }
}
