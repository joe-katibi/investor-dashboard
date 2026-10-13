<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Evidence / file on a ticket (or on one vendor update), stored privately on the local disk. */
#[Fillable(['outage_id', 'vendor_update_id', 'kind', 'path', 'original_name', 'note', 'latitude', 'longitude', 'accuracy_m', 'place', 'taken_at', 'location_source', 'user_id'])]
class OutageAttachment extends Model
{
    use Auditable;

    public const DIRECTORY = 'outage-attachments';

    protected function casts(): array
    {
        return ['latitude' => 'float', 'longitude' => 'float', 'accuracy_m' => 'integer', 'taken_at' => 'datetime'];
    }

    /** The photo was taken at a known position (the geolocation photo of a site visit). */
    public function hasLocation(): bool
    {
        return $this->latitude !== null && $this->longitude !== null;
    }

    /** "0.338276, 32.581402 (± 8 m)" */
    public function coordinates(): ?string
    {
        return $this->hasLocation()
            ? number_format($this->latitude, 6, '.', '').', '.number_format($this->longitude, 6, '.', '').($this->accuracy_m !== null ? " (± {$this->accuracy_m} m)" : '')
            : null;
    }

    /** The position on a map, in a new tab. */
    public function mapUrl(): ?string
    {
        return $this->hasLocation() ? 'https://www.google.com/maps?q='.number_format($this->latitude, 7, '.', '').','.number_format($this->longitude, 7, '.', '') : null;
    }

    public function outage(): BelongsTo
    {
        return $this->belongsTo(Outage::class);
    }

    public function vendorUpdate(): BelongsTo
    {
        return $this->belongsTo(VendorUpdate::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** A picture the browser can show (the rest — PDF, spreadsheet, e-mail — can only be downloaded). */
    public function isImage(): bool
    {
        return (bool) preg_match('/\.(jpe?g|png|webp|gif)$/i', (string) $this->path);
    }

    public function name(): string
    {
        return $this->original_name ?: basename($this->path);
    }

    public function url(): string
    {
        return route('outage-attachments.show', $this);
    }
}
