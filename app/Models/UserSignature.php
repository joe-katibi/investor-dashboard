<?php

namespace App\Models;

use App\Support\SignatureStyles;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The signature style a person prefers, offered first the next time they sign. One per person:
 * by account, or by e-mail address for a signatory who has no account. It is only ever written
 * from that person's own sign link. What was actually signed is kept on each signature.
 */
#[Fillable(['user_id', 'email', 'style_key', 'display_name_snapshot'])]
class UserSignature extends Model
{
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** The saved style of this signatory, when it is still one of the styles on offer. */
    public static function styleFor(ChangeRequestSignature $signatory): ?string
    {
        $style = self::query()->where(self::identity($signatory))->value('style_key');

        return SignatureStyles::label($style) ? $style : null;
    }

    public static function remember(ChangeRequestSignature $signatory, string $style, string $name): void
    {
        self::query()->updateOrCreate(self::identity($signatory), ['style_key' => $style, 'display_name_snapshot' => mb_substr(trim($name), 0, 150)]);
    }

    /** @return array<string, mixed> */
    private static function identity(ChangeRequestSignature $signatory): array
    {
        return $signatory->user_id ? ['user_id' => $signatory->user_id] : ['email' => mb_strtolower(trim((string) $signatory->email))];
    }
}
