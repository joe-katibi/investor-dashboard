<?php

namespace App\Models;

use App\Support\Settings;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/**
 * One CRF sign-off. Signatories sign through a personal link (no login needed): the link is a
 * Laravel signed URL that also carries a random token whose hash is stored here, so re-sending
 * the request invalidates older links.
 */
#[Fillable(['change_request_id', 'sort', 'role_label', 'user_id', 'name', 'email'])]
#[Hidden(['token_hash', 'signature_image'])]
class ChangeRequestSignature extends Model
{
    public const PENDING = 'pending';

    public const APPROVED = 'approved';

    public const CHANGES_REQUESTED = 'changes_requested';

    protected function casts(): array
    {
        return [
            'requested_at' => 'datetime',
            'signed_at' => 'datetime',
        ];
    }

    public function changeRequest(): BelongsTo
    {
        return $this->belongsTo(ChangeRequest::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function displayName(): string
    {
        return $this->name ?: ($this->user?->name ?? $this->email);
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            self::APPROVED => 'Approved',
            self::CHANGES_REQUESTED => 'Changes requested',
            default => 'Awaiting signature',
        };
    }

    /** Issue a fresh personal sign link (older links stop working). */
    public function issueSignUrl(): string
    {
        $token = Str::random(48);
        $this->forceFill(['token_hash' => hash('sha256', $token), 'requested_at' => now()])->save();

        return URL::temporarySignedRoute(
            'crf.sign',
            now()->addDays(max(1, (int) app(Settings::class)->int('maintenance.sign_link_days'))),
            ['signatory' => $this->id, 'token' => $token],
        );
    }

    public function tokenMatches(?string $token): bool
    {
        return $token !== null && $this->token_hash !== null && hash_equals($this->token_hash, hash('sha256', $token));
    }
}
