<?php

namespace App\Http\Controllers;

use App\Models\EmailTemplate;
use App\Services\Tickets\TicketReportData;
use App\Support\Settings;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * "Tickets – Pending / SLA Update" as a live page for management: opened from a link, no sign-in.
 * The link carries a long secret (Customer Care dashboard → Live link for management); a new one ends
 * the old. Only the summary is shown — figures by desk, OLT and region — never a customer's name or
 * account. Built at most once a minute, whoever is looking; the page reloads itself every minute.
 */
class LiveTicketSummaryController
{
    public const REFRESH_SECONDS = 60;

    public function __invoke(string $token, Settings $settings, TicketReportData $data): Response
    {
        $secret = (string) $settings->get('live.tickets_token');

        abort_unless($settings->bool('live.tickets_enabled') && $secret !== '' && hash_equals($secret, $token), 404);

        // "Closed in the last N hours": the period of the e-mail of the same name.
        $hours = max(1, (int) (EmailTemplate::query()->where('report_type', 'ticket_pending')->orderByDesc('is_active')->value('period_hours') ?: 3));

        $values = Cache::remember('oms.live.tickets.'.$hours, self::REFRESH_SECONDS, fn () => array_intersect_key($data->build('ticket_pending', now(), $hours), array_flip([
            'report_time', 'report_date', 'tk_sla_clock', 'tk_period_hours', 'tk_closed', 'tk_closed_sla_pct', 'tk_headline_table',
            'tk_sla_per_department_table', 'tk_sla_per_olt_table', 'tk_issue_per_olt_table', 'tk_sla_per_region_table',
        ])) + ['built_at' => now()->format('H:i:s')]);

        return response()->view('live.tickets', $values + ['refresh' => self::REFRESH_SECONDS])
            ->header('X-Robots-Tag', 'noindex, nofollow')
            ->header('Cache-Control', 'no-store')
            ->header('Referrer-Policy', 'no-referrer');
    }

    /** A new secret for the link (the old link stops working). */
    public static function newToken(): string
    {
        return Str::random(48);
    }
}
