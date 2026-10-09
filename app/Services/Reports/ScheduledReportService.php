<?php

namespace App\Services\Reports;

use App\Jobs\SendScheduledReportJob;
use App\Mail\ScheduledReportMail;
use App\Models\EmailTemplate;
use App\Models\NotificationLog;
use App\Services\Tickets\TicketReportData;
use App\Support\AuditContext;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Cron\CronExpression;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;

/**
 * Scheduled management emails (spec §13): two-hour update, executive summary, summary report.
 */
class ScheduledReportService
{
    /** Missed slots older than this are skipped rather than sent late in a burst. */
    public const CATCH_UP_MINUTES = 55;

    public function __construct(
        private readonly OperationalSnapshot $snapshot,
        private readonly TemplateRenderer $renderer,
        private readonly ReportRegistry $reports,
        private readonly ReportExporter $exporter,
    ) {}

    /** Claim and queue every template that is due. Returns how many were queued. */
    public function dispatchDue(?CarbonInterface $now = null): int
    {
        $now = CarbonImmutable::instance($now ?? now());
        $queued = 0;

        foreach (EmailTemplate::query()->active()->get() as $template) {
            if (! $this->isDue($template, $now)) {
                continue;
            }

            // Claim atomically so two scheduler nodes can never send the same slot twice.
            $claimed = DB::table('email_templates')
                ->where('id', $template->id)
                ->where(fn ($q) => $template->last_run_at
                    ? $q->where('last_run_at', $template->last_run_at)
                    : $q->whereNull('last_run_at'))
                ->update(['last_run_at' => $now, 'next_run_at' => $this->nextRunAt($template, $now), 'updated_at' => $now]);

            if ($claimed === 1) {
                SendScheduledReportJob::dispatch($template->id, $now->toIso8601String());
                $queued++;
            }
        }

        return $queued;
    }

    public function isDue(EmailTemplate $template, CarbonInterface $now): bool
    {
        $last = $template->last_run_at;

        return match ($template->schedule_type) {
            'interval' => $template->interval_minutes >= 5
                && (! $last || $last->copy()->addMinutes($template->interval_minutes)->subSeconds(30)->lessThanOrEqualTo($now)),
            'daily_times' => ($slot = $this->latestDailySlot($template, $now)) !== null
                && (! $last || $slot->greaterThan($last))
                && $slot->diffInMinutes($now) <= self::CATCH_UP_MINUTES,
            'cron' => $this->cronDue($template, $now, $last),
            default => false,
        };
    }

    public function nextRunAt(EmailTemplate $template, CarbonInterface $from): ?CarbonInterface
    {
        return match ($template->schedule_type) {
            'interval' => $template->interval_minutes >= 5 ? CarbonImmutable::instance($from)->addMinutes($template->interval_minutes) : null,
            'daily_times' => $this->nextDailySlot($template, $from),
            'cron' => $this->validCron($template->cron_expression)
                ? CarbonImmutable::instance((new CronExpression($template->cron_expression))->getNextRunDate($from->toDateTime(), 0, false, config('app.timezone')))
                : null,
            default => null,
        };
    }

    /**
     * Render the email for a template.
     *
     * @return array{subject: string, html: string, data: array}
     */
    public function render(EmailTemplate $template, ?CarbonInterface $now = null): array
    {
        $now ??= now();
        // Ticket e-mails have their own figures (pending / SLA update, daily, weekly, monthly).
        $data = match (true) {
            TicketReportData::handles($template->report_type) => app(TicketReportData::class)->build($template->report_type, $now, max(1, (int) $template->period_hours)),
            isset(ProductivityReport::EMAIL_TYPES[$template->report_type]) => app(ProductivityReport::class)->emailData($template->report_type, $now, max(1, (int) $template->period_hours)),
            default => $this->snapshot->build($this->periodStart($template, $now), $now),
        };

        return [
            'subject' => Str::limit(trim($this->renderer->renderText($template->subject, $data)), 250),
            'html' => $this->renderer->render($template->body, $data),
            'data' => $data,
        ];
    }

    /** Render and send now; logs one delivery row per recipient. */
    public function send(EmailTemplate $template, ?CarbonInterface $now = null, string $trigger = 'system:scheduled-report'): void
    {
        $now ??= now();
        $rendered = $this->render($template, $now);
        $to = array_values(array_filter($template->to ?? [], fn ($e) => filter_var($e, FILTER_VALIDATE_EMAIL)));
        $cc = array_values(array_filter($template->cc ?? [], fn ($e) => filter_var($e, FILTER_VALIDATE_EMAIL)));

        if ($to === []) {
            return;
        }

        $attachment = null;
        if ($template->attach_excel) {
            // Ticket e-mails carry the list of pending tickets; the others the outage summary of the period.
            $attachment = $this->exporter->writeXlsx(match (true) {
                $template->report_type === 'ticket_final_reasons' => $this->reports->run('ticket_final_reasons', ['to' => $now->toDateString()]),
                TicketReportData::handles($template->report_type) => $this->reports->run('ticket_pending'),
                // Productivity e-mails: the full report of the period, per person and module.
                isset(ProductivityReport::EMAIL_TYPES[$template->report_type]) => $this->reports->run(ProductivityReport::KEY, (function () use ($template, $now): array {
                    [$from, $to] = app(ProductivityReport::class)->emailPeriod($template->report_type, CarbonImmutable::instance($now), max(1, (int) $template->period_hours));

                    return ['from' => $from->toDateTimeString(), 'to' => $to->toDateTimeString(), 'group' => 'period'];
                })()),
                default => $this->reports->run('summary_report', [
                    'from' => $this->periodStart($template, $now)->toDateTimeString(),
                    'to' => $now->toDateTimeString(),
                ]),
            });
        }

        $logs = collect(array_merge($to, $cc))->map(fn (string $email) => NotificationLog::query()->create([
            'email_template_id' => $template->id,
            'event' => 'scheduled_report',
            'channel' => 'mail',
            'recipient' => $email,
            'subject' => $rendered['subject'],
            'status' => 'pending',
            'attempts' => 1,
            'trigger' => $trigger,
        ]));

        try {
            Mail::to($to)->cc($cc)->send(new ScheduledReportMail($rendered['subject'], $rendered['html'], $attachment, Str::slug($template->name)));
            NotificationLog::query()->whereIn('id', $logs->pluck('id'))->update(['status' => 'sent', 'sent_at' => now()]);
        } catch (Throwable $exception) {
            NotificationLog::query()->whereIn('id', $logs->pluck('id'))->update(['status' => 'failed', 'failure_reason' => Str::limit($exception->getMessage(), 1000)]);

            throw $exception;
        } finally {
            if ($attachment && is_file($attachment)) {
                @unlink($attachment);
            }
        }
    }

    public function sendById(int $templateId, ?string $at = null): void
    {
        $template = EmailTemplate::query()->find($templateId);

        if ($template) {
            AuditContext::as('system:scheduled-report', fn () => $this->send($template, $at ? CarbonImmutable::parse($at) : null));
        }
    }

    /**
     * Where the period of an outage e-mail begins: the last N hours — or, for the weekly and
     * monthly summaries, the Monday of last week / the 1st of last month (they are sent just after
     * the week or month ends, and run up to the moment they are sent).
     */
    public function periodStart(EmailTemplate $template, CarbonInterface $now): CarbonInterface
    {
        $now = CarbonImmutable::instance($now);

        return match ($template->report_type) {
            'summary_week' => $now->subWeek()->startOfWeek(),
            'summary_month' => $now->subMonthNoOverflow()->startOfMonth(),
            default => $now->subHours(max(1, (int) $template->period_hours)),
        };
    }

    // ------------------------------------------------------------------ schedule helpers

    private function slotsFor(EmailTemplate $template, CarbonInterface $day): array
    {
        return collect($template->daily_times ?? [])
            ->map(fn ($time) => is_array($time) ? ($time['time'] ?? null) : $time)
            ->filter(fn ($time) => is_string($time) && preg_match('/^\d{1,2}:\d{2}$/', $time))
            ->map(function (string $time) use ($day) {
                [$h, $m] = array_map('intval', explode(':', $time));

                return CarbonImmutable::instance($day)->setTime($h, $m);
            })
            ->sort()
            ->values()
            ->all();
    }

    private function latestDailySlot(EmailTemplate $template, CarbonInterface $now): ?CarbonInterface
    {
        $today = collect($this->slotsFor($template, $now))->filter(fn ($slot) => $slot->lessThanOrEqualTo($now))->last();

        return $today ?? collect($this->slotsFor($template, $now->copy()->subDay()))->last();
    }

    private function nextDailySlot(EmailTemplate $template, CarbonInterface $from): ?CarbonInterface
    {
        return collect($this->slotsFor($template, $from))->first(fn ($slot) => $slot->greaterThan($from))
            ?? collect($this->slotsFor($template, $from->copy()->addDay()))->first();
    }

    private function cronDue(EmailTemplate $template, CarbonInterface $now, ?CarbonInterface $last): bool
    {
        if (! $this->validCron($template->cron_expression)) {
            return false;
        }

        $previous = CarbonImmutable::instance((new CronExpression($template->cron_expression))->getPreviousRunDate($now->toDateTime(), 0, true, config('app.timezone')));

        return (! $last || $previous->greaterThan($last)) && $previous->diffInMinutes($now) <= self::CATCH_UP_MINUTES;
    }

    private function validCron(?string $expression): bool
    {
        return $expression !== null && CronExpression::isValidExpression($expression);
    }
}
