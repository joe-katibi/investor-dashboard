<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Evidence / file on a ticket (or on one vendor update), stored privately on the local disk. */
#[Fillable(['outage_id', 'vendor_update_id', 'path', 'original_name', 'note', 'user_id'])]
class OutageAttachment extends Model
{
    use Auditable;

    public const DIRECTORY = 'outage-attachments';

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

    public function name(): string
    {
        return $this->original_name ?: basename($this->path);
    }

    public function url(): string
    {
        return route('outage-attachments.show', $this);
    }
}
