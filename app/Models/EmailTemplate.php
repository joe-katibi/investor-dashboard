<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasAuditColumns;
use App\Services\Reports\ScheduledReportService;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'name', 'code', 'report_type', 'schedule_type', 'interval_minutes', 'daily_times', 'cron_expression',
    'period_hours', 'to', 'cc', 'subject', 'body', 'attach_excel', 'description', 'is_active',
])]
class EmailTemplate extends Model
{
    use Auditable, HasAuditColumns;

    public const REPORT_TYPES = [
        'two_hour' => 'Two-Hour Operational Update',
        'executive' => 'Executive Summary',
        'summary' => 'Summary Report',
        'mobility_hourly' => '4G Hourly Outage Update',
        'custom' => 'Custom',
    ];

    public const SCHEDULE_TYPES = [
        'interval' => 'Every N minutes',
        'daily_times' => 'At fixed times each day',
        'cron' => 'Cron expression',
    ];

    protected function casts(): array
    {
        return [
            'daily_times' => 'array',
            'to' => 'array',
            'cc' => 'array',
            'attach_excel' => 'boolean',
            'is_active' => 'boolean',
            'last_run_at' => 'datetime',
            'next_run_at' => 'datetime',
            'interval_minutes' => 'integer',
            'period_hours' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (EmailTemplate $template): void {
            if ($template->isDirty(['schedule_type', 'interval_minutes', 'daily_times', 'cron_expression', 'is_active'])) {
                $template->next_run_at = $template->is_active
                    ? app(ScheduledReportService::class)->nextRunAt($template, $template->last_run_at ?? now())
                    : null;
            }
        });
    }

    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }
}
