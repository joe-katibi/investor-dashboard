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
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Lifecycle fields (status, timestamps, SLA, assignment) are written by the workflow and
 * FIFO services only; they are deliberately excluded from mass assignment.
 */
#[Fillable([
    'title', 'major_outage_type_id', 'outage_type_id', 'vertical_id', 'priority_id', 'priority_is_manual',
    'description', 'location', 'customers_affected', 'sites_down', 'cells_down', 'power_faults',
    'start_time', 'team_type', 'team_id', 'sub_team_id', 'technician_id',
    'detected_at', 'transmission_link_id', 'transmission_type', 'service_impact',
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
            'downtime_start' => 'datetime',
            'downtime_end' => 'datetime',
            'pm_approved_at' => 'datetime',
            'impact_counts' => 'array',
            'detected_at' => 'datetime',
            'acknowledged_at' => 'datetime',
            'work_started_at' => 'datetime',
            'vendor_notified_at' => 'datetime',
            'vendor_acknowledged_at' => 'datetime',
            'vendor_last_update_at' => 'datetime',
            'vendor_next_update_at' => 'datetime',
            'vendor_eta_at' => 'datetime',
            'vendor_resolved_at' => 'datetime',
            'vendor_escalated_at' => 'datetime',
            'vendor_escalation_level' => 'integer',
            'escalated_at' => 'datetime',
            'reopened_at' => 'datetime',
            'reopen_count' => 'integer',
            'cancelled_at' => 'datetime',
        ];
    }

    public const TRANSMISSION_TYPES = ['microwave' => 'Microwave', 'fibre' => 'Fibre', 'other' => 'Other'];

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

    /** Maintenance & Change Request Form (planned maintenance tickets only). */
    public function changeRequest(): HasOne
    {
        return $this->hasOne(ChangeRequest::class);
    }

    public function crfSignatures(): HasManyThrough
    {
        return $this->hasManyThrough(ChangeRequestSignature::class, ChangeRequest::class)->orderBy('change_request_signatures.sort');
    }

    public function photos(): HasMany
    {
        return $this->hasMany(OutagePhoto::class)->orderBy('id');
    }

    public function maintenanceApprovals(): HasMany
    {
        return $this->hasMany(MaintenanceApproval::class)->orderBy('id');
    }

    /** "Preventive Maintenance" / "Corrective Maintenance" / null. */
    public function maintenanceKindLabel(): ?string
    {
        return $this->maintenance_kind ? ucfirst($this->maintenance_kind).' Maintenance' : null;
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

    // ---------------------------------------------------------------- 4G NOC

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function enodebs(): BelongsToMany
    {
        return $this->belongsToMany(Enodeb::class, 'outage_enodeb');
    }

    public function transmissionNodes(): BelongsToMany
    {
        return $this->belongsToMany(TransmissionNode::class, 'outage_transmission_node');
    }

    public function vendorUpdates(): HasMany
    {
        return $this->hasMany(VendorUpdate::class)->latest('occurred_at')->latest('id');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(OutageAttachment::class)->latest('id');
    }

    public function reopenings(): HasMany
    {
        return $this->hasMany(OutageReopening::class)->latest('id');
    }

    public function escalatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'escalated_by');
    }

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    public function restoreConfirmedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'restore_confirmed_by');
    }

    /** Runs the 4G NOC workflow (its own statuses, screens and notification settings). */
    public function isMobility(): bool
    {
        return $this->workflow !== null;
    }

    /** Worked by an external vendor; the NOC records the vendor's updates. */
    public function isVendorTicket(): bool
    {
        return $this->workflow === OutageType::WORKFLOW_MOBILITY_VENDOR;
    }

    /** Who has a 4G ticket right now: the vendor, the person FIFO assigned, the queue it waits in, or the NOC. */
    public function mobilityOwner(): string
    {
        if ($this->isVendorTicket() && ! $this->current_queue) {
            return 'Vendor '.($this->vendor?->name ?? '—');
        }

        return $this->currentAssignee?->name
            ?? ($this->current_queue ? $this->current_queue->getLabel().' queue (FIFO)' : ($this->technician?->name ?? '4G NOC'));
    }

    public function domainLabel(): ?string
    {
        return OutageType::FAULT_DOMAINS[$this->fault_domain] ?? null;
    }

    /** "4624 Nsambya Kevina, 4495 Tandem Hotel" */
    public function sitesLabel(int $limit = 6): string
    {
        $sites = $this->enodebs->map(fn (Enodeb $site) => trim($site->site_id.' '.$site->name));

        return $sites->take($limit)->implode(', ').($sites->count() > $limit ? ' +'.($sites->count() - $limit).' more' : '');
    }

    public function nodesLabel(int $limit = 6): string
    {
        $nodes = $this->transmissionNodes->map(fn (TransmissionNode $node) => $node->label());

        return $nodes->take($limit)->implode(', ').($nodes->count() > $limit ? ' +'.($nodes->count() - $limit).' more' : '');
    }

    /** "5 eNodeBs · 2 SSGs · 1 CSG" */
    public function impactLabel(): string
    {
        $counts = (array) $this->impact_counts;
        $names = ['enodebs' => 'eNodeB', 'cells' => 'cell', 'ssg' => 'SSG', 'csg' => 'CSG', 'asg' => 'ASG', 'rsg' => 'RSG'];

        return collect($names)
            ->filter(fn (string $name, string $key) => ($counts[$key] ?? 0) > 0)
            ->map(fn (string $name, string $key) => $counts[$key].' '.$name.($counts[$key] === 1 ? '' : 's'))
            ->implode(' · ');
    }

    public function scopeMobility(Builder $query): void
    {
        $query->whereNotNull($query->qualifyColumn('workflow'));
    }

    /** Everything that is not a 4G NOC ticket (the FTTH / core / system flows). */
    public function scopeStandard(Builder $query): void
    {
        $query->whereNull($query->qualifyColumn('workflow'));
    }

    public function scopeOfDomain(Builder $query, string $domain): void
    {
        $query->where($query->qualifyColumn('fault_domain'), $domain);
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
        $query->whereHas('priority', fn (Builder $q) => $q->whereIn('code', Priority::CRITICAL_CODES));
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
