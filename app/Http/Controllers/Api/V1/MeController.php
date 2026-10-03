<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\AvailabilityStatus;
use App\Http\Controllers\Controller;
use App\Services\Fifo\FifoAssignmentEngine;
use App\Services\Mobile\FcmSender;
use App\Services\Mobile\MobileProfile;
use App\Services\Mobile\TicketScopes;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Validation\Rule;

/** Who is signed in, their availability, the numbers on their dashboard and the app's settings. */
class MeController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        return response()->json(['user' => MobileProfile::describe($request->user())]);
    }

    /** Available / Busy / On break / Offline: FIFO only offers new tickets to people who are Available. */
    public function availability(Request $request, FifoAssignmentEngine $fifo): JsonResponse
    {
        $data = $request->validate(['status' => ['required', Rule::enum(AvailabilityStatus::class)]]);
        $status = AvailabilityStatus::from($data['status']);

        $fifo->setAvailability($request->user(), $status, $request->user(), 'Mobile app');

        return response()->json(['message' => 'You are now '.$status->getLabel(), 'user' => MobileProfile::describe($request->user()->refresh())]);
    }

    /**
     * The dashboard tiles. Each one opens the ticket list with the same scope, so the number on
     * the tile is the number of rows behind it.
     */
    public function dashboard(Request $request): JsonResponse
    {
        $user = $request->user();
        $role = MobileProfile::role($user);
        $tiles = [];

        foreach (TicketScopes::labels($role) as $scope => $label) {
            $tiles[] = [
                'scope' => $scope,
                'label' => $label,
                'count' => TicketScopes::count($user, $scope),
                'tone' => match ($scope) {
                    'new', 'awaiting' => 'warning',
                    'critical', 'breached' => 'danger',
                    'done' => 'success',
                    default => 'primary',
                },
            ];
        }

        return response()->json([
            'role' => $role,
            'availability' => [
                'status' => $user->availability_status?->value,
                'label' => $user->availability_status?->getLabel(),
                'options' => collect(AvailabilityStatus::cases())
                    ->map(fn (AvailabilityStatus $status) => ['value' => $status->value, 'label' => $status->getLabel()])->all(),
            ],
            'tiles' => $tiles,
            'unread_notifications' => $user->unreadNotifications()->count(),
        ]);
    }

    /**
     * Reverb: the phone asks to listen to a private channel. routes/channels.php decides — a person
     * can only ever join their own channel — and the answer is the signature Reverb checks.
     */
    public function broadcastAuth(Request $request): mixed
    {
        $request->validate(['socket_id' => ['required', 'string', 'max:60'], 'channel_name' => ['required', 'string', 'max:120']]);

        return Broadcast::auth($request);
    }

    /**
     * What the app needs to run: the real-time (Reverb) connection details — public values, the
     * same ones the web panel's browser gets — whether push notifications (Firebase) are on, and
     * the filters of the ticket list.
     */
    public function config(Request $request): JsonResponse
    {
        $echo = (array) config('filament.broadcasting.echo');
        $role = MobileProfile::role($request->user());

        return response()->json([
            // While the app is open: notifications arrive at once over Reverb.
            'realtime' => [
                'enabled' => config('broadcasting.default') === 'reverb' && filled($echo['key'] ?? null),
                'key' => $echo['key'] ?? null,
                'host' => $echo['wsHost'] ?? null,
                'port' => (int) ($echo['wsPort'] ?? 443),
                'tls' => (bool) ($echo['forceTLS'] ?? true),
                'auth_endpoint' => url('/api/v1/broadcasting/auth'),
                'user_channel' => 'private-App.Models.User.'.$request->user()->id,
                'event' => 'mobile.notification',
            ],
            'filters' => [
                'scopes' => collect(TicketScopes::labels($role))->map(fn (string $label, string $scope) => ['value' => $scope, 'label' => $label])->values()->all(),
                'modules' => [
                    ['value' => 'outage', 'label' => 'Outages'],
                    ['value' => 'mobility', 'label' => '4G outages'],
                    ['value' => 'site_visit', 'label' => 'Site visits'],
                    ['value' => 'customer_ticket', 'label' => 'Customer tickets'],
                ],
                'priorities' => [
                    ['value' => 'critical', 'label' => 'Critical only'],
                    ['value' => 'major', 'label' => 'Critical and high / major'],
                ],
            ],
            // In the background or closed: push notifications through Firebase, when it is set up on this server.
            // The app registers its push token either way.
            'push' => ['provider' => FcmSender::PROVIDER, 'enabled' => app(FcmSender::class)->configured()],
            'uploads' => ['max_kb' => (int) config('oms.mobile.max_upload_kb')],
        ]);
    }
}
