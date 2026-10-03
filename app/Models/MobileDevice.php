<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A phone signed in to the mobile app (one row per person and device). */
#[Fillable(['user_id', 'token_id', 'device_uuid', 'platform', 'device_name', 'app_version', 'push_provider', 'push_token', 'last_seen_at'])]
#[Hidden(['push_token'])]
class MobileDevice extends Model
{
    public const PLATFORMS = ['android', 'ios'];

    protected function casts(): array
    {
        return ['last_seen_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
