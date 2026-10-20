<?php

namespace App\Http\Controllers;

use App\Services\Reports\ExecutiveLiveSummary;
use App\Support\Settings;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * The executive live page for management: FTTH outages, 4G outages, customer tickets & site visits (the "Tickets –
 * Pending / SLA Update") and 4G tickets — opened from a link, no sign-in. The link carries a long secret (Customer
 * Care dashboard → Live link for management); a new one ends the old. Summaries only, never a customer's name or
 * account. Built at most once a minute, whoever is looking; the page reloads itself every minute.
 */
class LiveTicketSummaryController
{
    public const REFRESH_SECONDS = 60;

    public function __invoke(string $token, Settings $settings, ExecutiveLiveSummary $summary): Response
    {
        $secret = (string) $settings->get('live.tickets_token');

        abort_unless($settings->bool('live.tickets_enabled') && $secret !== '' && hash_equals($secret, $token), 404);

        $values = Cache::remember('oms.live.executive', self::REFRESH_SECONDS, fn () => $summary->build());

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
