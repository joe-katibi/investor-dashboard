<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** One ONU export dump read by oms:sync-onu-export: the file, what it changed and what it could not place. */
#[Fillable([
    'source', 'file', 'dumped_at', 'status', 'rows', 'customers_matched', 'customers_created', 'customers_updated', 'customers_unchanged',
    'changes', 'summary', 'error', 'started_at', 'finished_at',
])]
class OnuSyncRun extends Model
{
    public const RUNNING = 'running';

    public const DONE = 'done';

    public const FAILED = 'failed';

    protected function casts(): array
    {
        return ['dumped_at' => 'datetime', 'started_at' => 'datetime', 'finished_at' => 'datetime', 'summary' => 'array'];
    }

    public function changes(): HasMany
    {
        return $this->hasMany(CustomerOnuChange::class);
    }
}
