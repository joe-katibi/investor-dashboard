<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'notification_rule_id', 'outage_id', 'email_template_id', 'event', 'channel', 'recipient_user_id',
    'recipient', 'subject', 'body', 'meta', 'status', 'attempts', 'failure_reason', 'trigger', 'sent_at',
])]
class NotificationLog extends Model
{
    protected function casts(): array
    {
        return ['sent_at' => 'datetime', 'attempts' => 'integer', 'meta' => 'array'];
    }

    public function rule(): BelongsTo
    {
        return $this->belongsTo(NotificationRule::class, 'notification_rule_id');
    }

    public function outage(): BelongsTo
    {
        return $this->belongsTo(Outage::class);
    }

    public function emailTemplate(): BelongsTo
    {
        return $this->belongsTo(EmailTemplate::class);
    }

    public function recipientUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recipient_user_id');
    }
}
