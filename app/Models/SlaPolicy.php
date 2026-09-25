<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasAuditColumns;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'name', 'major_outage_type_id', 'outage_type_id', 'vertical_id', 'priority_id', 'service_id',
    'resolution_minutes', 'pre_breach_minutes', 'ack_timeout_minutes', 'escalation_minutes',
    'escalation_roles', 'notify_roles', 'notify_user_ids', 'notify_emails',
    'notify_credit', 'notify_sms', 'allow_pause', 'description', 'is_active',
])]
class SlaPolicy extends Model
{
    use Auditable, HasAuditColumns;

    /** Matcher columns; each non-null match adds specificity. */
    public const MATCHERS = ['outage_type_id', 'priority_id', 'service_id', 'vertical_id', 'major_outage_type_id'];

    protected function casts(): array
    {
        return [
            'escalation_roles' => 'array',
            'notify_roles' => 'array',
            'notify_user_ids' => 'array',
            'notify_emails' => 'array',
            'notify_credit' => 'boolean',
            'notify_sms' => 'boolean',
            'allow_pause' => 'boolean',
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

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    public function specificity(): int
    {
        return collect(self::MATCHERS)->filter(fn (string $column) => $this->{$column} !== null)->count();
    }
}
