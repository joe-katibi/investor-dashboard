<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Photo taken on site, stored privately on the local disk.
 *
 *   Field maintenance: "before" and "after", each outside the FAT / FDT (label visible) and inside.
 *   Emergency / Regular outage: the three site photos — findings on the ground, after restoration
 *   and the power readings.
 */
#[Fillable(['outage_id', 'stage', 'position', 'path', 'original_name', 'taken_at', 'source', 'user_id'])]
class OutagePhoto extends Model
{
    public const BEFORE = 'before';

    public const AFTER = 'after';

    /** Stage of the three photos an outage is reported restored with. */
    public const SITE = 'site';

    public const POSITIONS = [
        'outside' => 'Outside the FAT / FDT (label visible)',
        'inside' => 'Inside the FAT / FDT',
    ];

    /** position => [label, what the photo must show] — in the order they are taken. */
    public const SITE_PHOTOS = [
        'findings' => ['Findings on ground', 'The fault as you found it, before any work.'],
        'restored' => ['After restoration', 'The finished work, once the service is back.'],
        'power' => ['Power readings', 'The power meter showing the reading after restoration.'],
    ];

    /** Taken with the phone's camera in the app (time stamped on the picture). */
    public const SOURCE_CAMERA = 'camera';

    /** Chosen from a computer on the website. */
    public const SOURCE_UPLOAD = 'upload';

    protected function casts(): array
    {
        return ['taken_at' => 'datetime'];
    }

    public function outage(): BelongsTo
    {
        return $this->belongsTo(Outage::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function label(): string
    {
        if ($this->stage === self::SITE) {
            return self::SITE_PHOTOS[$this->position][0] ?? $this->position;
        }

        return ($this->stage === self::AFTER ? 'After — ' : 'Before — ').(self::POSITIONS[$this->position] ?? $this->position);
    }

    /** When the picture was taken (the phone's camera), or when it was uploaded. */
    public function takenAt(): ?\DateTimeInterface
    {
        return $this->taken_at ?? $this->created_at;
    }

    /** "Taken 05 Oct 2026 14:32 · camera" / "Uploaded 05 Oct 2026 14:40". */
    public function caption(): string
    {
        return ($this->source === self::SOURCE_CAMERA ? 'Taken ' : 'Uploaded ').$this->takenAt()?->format('d M Y H:i')
            .($this->user ? ' · '.$this->user->name : '');
    }

    public function url(): string
    {
        return route('outage-photos.show', $this);
    }
}
