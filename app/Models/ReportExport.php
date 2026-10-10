<?php

namespace App\Models;

use App\Services\Reports\ReportRegistry;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * One report export asked for on the Reports page. It is written by a queue worker
 * (GenerateReportExportJob), downloaded from Reporting → Report Downloads, and removed — file and
 * row — six hours after it was prepared.
 */
#[Fillable(['user_id', 'report_key', 'title', 'format', 'filters', 'date_from', 'date_to', 'status', 'path', 'file_name', 'size_bytes', 'error', 'started_at', 'finished_at', 'expires_at'])]
class ReportExport extends Model
{
    public const QUEUED = 'queued';

    public const RUNNING = 'running';

    public const READY = 'ready';

    public const FAILED = 'failed';

    /** How long a prepared file can be downloaded. */
    public const KEEP_HOURS = 6;

    /** A run that has not finished after this long is taken to have died with its worker. */
    public const STUCK_MINUTES = 45;

    public const DISK = 'local';

    public const DIRECTORY = 'report-exports';

    public const FORMATS = ['csv' => 'CSV', 'xlsx' => 'Excel', 'pdf' => 'PDF'];

    protected $attributes = ['status' => self::QUEUED];

    protected function casts(): array
    {
        return [
            'filters' => 'array',
            'date_from' => 'date',
            'date_to' => 'date',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Everyone sees their own exports; whoever reads the audit trail sees who pulled what. */
    public function scopeVisibleTo(Builder $query, User $user): void
    {
        if (! $user->can('audit.view')) {
            $query->where('user_id', $user->id);
        }
    }

    public function isPending(): bool
    {
        return in_array($this->status, [self::QUEUED, self::RUNNING], true);
    }

    public function isReady(): bool
    {
        return $this->status === self::READY && filled($this->path) && $this->expires_at->isFuture();
    }

    /** The file of someone else is only for a person who could have run that report themselves. */
    public function downloadableBy(User $user): bool
    {
        return $this->isReady()
            && $user->can('reports.export')
            && ($this->user_id === $user->id || ($user->can('audit.view') && isset(ReportRegistry::availableTo($user)[$this->report_key])));
    }

    public function periodLabel(): string
    {
        return match (true) {
            $this->date_from && $this->date_to => $this->date_from->format('d M Y').' – '.$this->date_to->format('d M Y'),
            (bool) $this->date_from => 'From '.$this->date_from->format('d M Y'),
            (bool) $this->date_to => 'Up to '.$this->date_to->format('d M Y'),
            default => 'All dates',
        };
    }

    public function statusLabel(): string
    {
        $format = self::FORMATS[$this->format] ?? strtoupper((string) $this->format);

        return $format.' · '.match ($this->status) {
            self::QUEUED => 'Waiting to be prepared',
            self::RUNNING => 'Being prepared…',
            self::READY => $this->isReady() ? 'Ready — kept until '.$this->expires_at->format('H:i') : 'Removed',
            default => 'Could not be prepared'.($this->error ? ': '.$this->error : ''),
        };
    }

    /** Remove the file with the row. */
    public function purge(): void
    {
        if (filled($this->path)) {
            Storage::disk(self::DISK)->delete($this->path);
        }

        $this->delete();
    }
}
