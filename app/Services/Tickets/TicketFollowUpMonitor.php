<?php

namespace App\Services\Tickets;

use App\Enums\NotificationEvent;
use App\Mail\ScheduledReportMail;
use App\Models\NotificationLog;
use App\Models\Outage;
use App\Models\User;
use App\Services\Outages\OutageWorkflow;
use App\Services\Reports\ReportExporter;
use App\Services\Reports\ReportResult;
use App\Services\Reports\TicketReports;
use App\Support\AuditContext;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Filament\Notifications\Events\DatabaseNotificationsSent;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;

/**
 * Tickets pending past the follow-up time (9 SLA hours by default):
 *
 *  1. each one enters the "Pending Ticket Follow-up" FIFO queue, so the CX follow-up team calls
 *     the customer while the work goes on, and is marked ready for credit;
 *  2. the people who handle credits get one notification and one e-mail listing the tickets that
 *     became ready for credit since the last one, with the list attached as a spreadsheet.
 *
 * Run every few minutes by the scheduler (oms:ticket-tick).
 */
class TicketFollowUpMonitor
{
    public function __construct(
        private readonly Settings $settings,
        private readonly BusinessClock $clock,
        private readonly OutageWorkflow $workflow,
        private readonly TicketReports $reports,
        private readonly ReportExporter $exporter,
    ) {}

    /** @return array{flagged: int, notified: int} */
    public function tick(?CarbonInterface $now = null): array
    {
        $now = CarbonImmutable::instance($now ?? now());
        $minutes = $this->settings->int('ticketing.followup_minutes');

        if (! $this->settings->bool('ticketing.followup_enabled') || $minutes <= 0) {
            return ['flagged' => 0, 'notified' => 0];
        }

        $flagged = 0;

        // SLA time never runs faster than the wall clock, so only tickets at least that old can be due.
        Outage::query()
            ->tickets()
            ->active()
            ->where('start_time', '<=', $now->subMinutes($minutes))
            ->whereHas('ticket', fn ($q) => $q->whereNull('follow_up_due_at'))
            ->orderBy('start_time')
            ->limit(500)
            ->get(['id', 'start_time'])
            ->each(function (Outage $outage) use ($now, $minutes, &$flagged): void {
                if ($this->clock->minutesBetween($outage->start_time, $now) >= $minutes && $this->workflow->flagTicketFollowUp($outage->id)) {
                    $flagged++;
                }
            });

        return ['flagged' => $flagged, 'notified' => $this->settings->bool('ticketing.credit_notify') ? $this->notifyCredit($now) : 0];
    }

    /** One digest for everything that became ready for credit since the last one. */
    public function notifyCredit(CarbonInterface $now): int
    {
        $ids = DB::table('tickets')->whereNotNull('credit_ready_at')->whereNull('credit_notified_at')->orderBy('credit_ready_at')->limit(1000)->pluck('id');

        if ($ids->isEmpty()) {
            return 0;
        }

        // Claimed first: a second scheduler node (or a retry after a mail failure) never repeats the list.
        $claimed = DB::table('tickets')->whereIn('id', $ids)->whereNull('credit_notified_at')->update(['credit_notified_at' => $now, 'updated_at' => $now]);

        if ($claimed === 0) {
            return 0;
        }

        $rows = $this->reports->creditRows($this->reports->query()->whereIn('t.id', $ids)->orderBy('t.credit_ready_at')->get());
        $count = $rows->count();
        $hours = round($this->settings->int('ticketing.followup_minutes') / 60, 1);
        $subject = "{$count} ticket".($count === 1 ? '' : 's')." ready for credit – {$now->format('d M Y H:i')}";

        $users = User::query()->active()->withAnyRole((array) $this->settings->get('ticketing.credit_roles'))->get();
        $emails = $users->pluck('email')
            ->merge((array) $this->settings->get('ticketing.credit_emails'))
            ->map(fn ($email) => Str::lower(trim((string) $email)))
            ->filter(fn (string $email) => (bool) filter_var($email, FILTER_VALIDATE_EMAIL))
            ->unique()->values()->all();

        AuditContext::as('system:ticket-follow-up', function () use ($users, $rows, $subject, $hours, $count): void {
            $body = "Pending more than {$hours} SLA hours: ".$rows->take(8)->pluck('ticket')->implode(', ').($count > 8 ? ' and '.($count - 8).' more' : '').'. The full list is in the e-mail and under Reports → Tickets: Ready for Credit.';

            foreach ($users as $user) {
                $user->notifyNow(Notification::make()->title($subject)->body($body)->icon('heroicon-o-banknotes')->iconColor('warning')->toDatabase());
                DatabaseNotificationsSent::dispatch($user);
            }
        });

        if ($emails !== []) {
            $this->mail($emails, $subject, $rows->all(), $hours);
        }

        return $count;
    }

    /** @param  list<string>  $emails */
    private function mail(array $emails, string $subject, array $rows, float $hours): void
    {
        $columns = TicketReports::CREDIT_COLUMNS;
        $html = '<p>The tickets below have been pending for more than <strong>'.e((string) $hours).' SLA hours</strong> and are ready for credit. '
            .'They are in the customer follow-up queue. The list is attached.</p>'
            .TicketTables::table(array_values($columns), array_map(fn (array $row) => array_map(fn (string $key) => $row[$key] ?? '', array_keys($columns)), array_slice($rows, 0, 200)), ['left' => 8]);

        $logs = collect($emails)->map(fn (string $email) => NotificationLog::query()->create([
            'event' => NotificationEvent::TicketCreditReady->value, 'channel' => 'mail', 'recipient' => $email, 'subject' => $subject,
            'status' => 'pending', 'attempts' => 1, 'trigger' => 'system:ticket-follow-up',
        ]));

        $attachment = null;

        try {
            $attachment = $this->exporter->writeXlsx(new ReportResult('Tickets ready for credit', $columns, fn (?int $limit) => $limit ? array_slice($rows, 0, $limit) : $rows));
            Mail::to($emails)->send(new ScheduledReportMail($subject, $html, $attachment, 'tickets-ready-for-credit'));
            NotificationLog::query()->whereIn('id', $logs->pluck('id'))->update(['status' => 'sent', 'sent_at' => now()]);
        } catch (Throwable $exception) {
            // The in-app notice and the report still carry the list; the failure is on the notification log.
            NotificationLog::query()->whereIn('id', $logs->pluck('id'))->update(['status' => 'failed', 'failure_reason' => Str::limit($exception->getMessage(), 1000)]);
            report($exception);
        } finally {
            if ($attachment && is_file($attachment)) {
                @unlink($attachment);
            }
        }
    }
}
