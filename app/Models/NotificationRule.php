<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasAuditColumns;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'name', 'event', 'major_outage_type_id', 'outage_type_id', 'vertical_id', 'priority_id', 'status_id',
    'channels', 'recipient_roles', 'recipient_user_ids', 'recipient_emails', 'recipient_phones',
    'notify_assignee', 'notify_team_leaders', 'description', 'is_active',
])]
class NotificationRule extends Model
{
    use Auditable, HasAuditColumns;

    protected function casts(): array
    {
        return [
            'channels' => 'array',
            'recipient_roles' => 'array',
            'recipient_user_ids' => 'array',
            'recipient_emails' => 'array',
            'recipient_phones' => 'array',
            'notify_assignee' => 'boolean',
            'notify_team_leaders' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

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

    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    public function matches(Outage $outage): bool
    {
        // 4G NOC tickets have their own audience: only rules set to that vertical apply, so the
        // FTTH rules ("any vertical") never page FTTH teams about a 4G site.
        if ($outage->workflow !== null && $this->vertical_id === null) {
            return false;
        }

        foreach (['major_outage_type_id', 'outage_type_id', 'vertical_id', 'priority_id', 'status_id'] as $column) {
            if ($this->{$column} !== null && (int) $this->{$column} !== (int) $outage->{$column}) {
                return false;
            }
        }

        return true;
    }
}
