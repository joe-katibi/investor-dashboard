<?php

namespace App\Http\Controllers;

use App\Events\DesktopAlert;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * "Anything new for me since …?" — asked by the person's open OMS pages every little while, so
 * desktop notifications also arrive when Reverb (the live channel) is not running, and for
 * notifications that are only written to the bell.
 *
 * Only the signed-in person's own unread notifications, and only what the desktop shows: a
 * title, the ticket number and the link to the ticket.
 */
class DesktopAlertController extends Controller
{
    /** Never further back than this, however long a page was asleep. */
    private const MAX_AGE_MINUTES = 30;

    public function __invoke(Request $request): JsonResponse
    {
        $now = now();
        $oldest = $now->copy()->subMinutes(self::MAX_AGE_MINUTES);

        try {
            // The browser sends its time in UTC; the comparison below is in the application's time zone.
            $after = filled($request->query('after')) ? Carbon::parse((string) $request->query('after'))->setTimezone(config('app.timezone')) : $now;
        } catch (Throwable) {
            $after = $now;
        }

        $after = $after->greaterThan($now) ? $now : ($after->lessThan($oldest) ? $oldest : $after);

        $alerts = $request->user()->unreadNotifications()
            ->where('created_at', '>', $after)
            ->oldest()
            ->limit(10)
            ->get()
            ->map(function (DatabaseNotification $notification): array {
                $data = (array) $notification->data;
                $oms = (array) ($data['viewData']['oms'] ?? []);

                return DesktopAlert::describe(
                    (string) $notification->id,
                    $data['title'] ?? null,
                    $oms['type'] ?? null,
                    isset($oms['ticket_id']) ? (int) $oms['ticket_id'] : null,
                    $oms['ticket_number'] ?? null,
                    $data['body'] ?? null,
                    $notification->created_at,
                );
            })
            ->values();

        return response()->json(['alerts' => $alerts, 'now' => $now->toIso8601String()])
            ->header('Cache-Control', 'no-store, private');
    }
}
