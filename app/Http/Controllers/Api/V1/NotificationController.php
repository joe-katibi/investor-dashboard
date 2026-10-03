<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;

/**
 * The in-app notification list: the same notifications as the bell of the web panel, so reading
 * one on the phone marks it read on the web too.
 */
class NotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'per_page' => ['nullable', 'integer', 'min:5', 'max:50'],
            'unread' => ['nullable', 'boolean'],
        ]);

        $page = $request->user()->notifications()
            ->when($request->boolean('unread'), fn ($query) => $query->whereNull('read_at'))
            ->paginate((int) ($data['per_page'] ?? 20));

        return response()->json([
            'data' => collect($page->items())->map(fn (DatabaseNotification $notification) => self::present($notification))->all(),
            'meta' => [
                'page' => $page->currentPage(),
                'total' => $page->total(),
                'has_more' => $page->hasMorePages(),
                'unread' => $request->user()->unreadNotifications()->count(),
            ],
        ]);
    }

    public function read(Request $request, string $notification): JsonResponse
    {
        // Looked up among this person's own notifications: someone else's id is simply "not found".
        $row = $request->user()->notifications()->whereKey($notification)->firstOrFail();
        $row->markAsRead();

        return response()->json(['notification' => self::present($row), 'unread' => $request->user()->unreadNotifications()->count()]);
    }

    public function readAll(Request $request): JsonResponse
    {
        $request->user()->unreadNotifications()->update(['read_at' => now()]);

        return response()->json(['unread' => 0]);
    }

    /** @return array<string, mixed> */
    public static function present(DatabaseNotification $notification): array
    {
        $data = (array) $notification->data;
        $view = (array) ($data['viewData']['oms'] ?? []);
        $ticketId = $view['ticket_id'] ?? null;

        // Notifications stored before the app existed carry the ticket only in their "Open ticket" link.
        if (! $ticketId && preg_match('~/(\d+)(?:[/?#].*)?$~', (string) ($data['actions'][0]['url'] ?? ''), $match)) {
            $ticketId = (int) $match[1];
        }

        return [
            'id' => $notification->id,
            'type' => $view['type'] ?? null,
            'title' => (string) ($data['title'] ?? 'Notification'),
            // Stored as HTML for the web bell (line breaks as <br>): plain text, one line per line, for the app.
            'body' => trim(html_entity_decode(strip_tags((string) preg_replace('~<br\s*/?>\R?~i', "\n", (string) ($data['body'] ?? ''))), ENT_QUOTES | ENT_HTML5)),
            'ticket_id' => $ticketId ? (int) $ticketId : null,
            'ticket_number' => $view['ticket_number'] ?? null,
            'read' => $notification->read_at !== null,
            'at' => $notification->created_at?->toIso8601String(),
        ];
    }
}
