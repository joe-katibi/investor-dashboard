<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\MobileDevice;
use App\Services\Mobile\FcmSender;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The phones a person is signed in on. The app registers itself after sign-in; a person can have
 * several. With its Firebase push token (push_provider "fcm") the server can push to it while the app is
 * in the background or closed; without one, notifications only reach it while the app is open (Reverb).
 */
class DeviceController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'device_uuid' => ['required', 'string', 'min:16', 'max:64', 'regex:/^[A-Za-z0-9\-]+$/'],
            'platform' => ['required', Rule::in(MobileDevice::PLATFORMS)],
            'device_name' => ['nullable', 'string', 'max:120'],
            'app_version' => ['nullable', 'string', 'max:30'],
            'push_provider' => ['nullable', Rule::in([FcmSender::PROVIDER]), 'required_with:push_token'],
            'push_token' => ['nullable', 'string', 'max:512'],
        ]);

        $device = MobileDevice::query()->updateOrCreate(
            ['user_id' => $request->user()->id, 'device_uuid' => $data['device_uuid']],
            [
                'token_id' => $request->user()->currentAccessToken()->id,
                'platform' => $data['platform'],
                'device_name' => $data['device_name'] ?? null,
                'app_version' => $data['app_version'] ?? null,
                'push_provider' => $data['push_provider'] ?? null,
                'push_token' => $data['push_token'] ?? null,
                'last_seen_at' => now(),
            ],
        );

        // A push token names one installation of the app. If it is still on someone else's device row
        // (they signed out while offline), take it off: their notifications must not reach this phone.
        if (filled($device->push_token)) {
            MobileDevice::query()->where('push_token', $device->push_token)->whereKeyNot($device->id)
                ->update(['push_token' => null, 'push_provider' => null]);
        }

        return response()->json(['device' => ['id' => $device->id, 'platform' => $device->platform, 'registered_at' => $device->created_at?->toIso8601String()]], $device->wasRecentlyCreated ? 201 : 200);
    }

    public function destroy(Request $request, int $device): JsonResponse
    {
        // Only a device of this person: anyone else's id is "not found".
        MobileDevice::query()->where('user_id', $request->user()->id)->whereKey($device)->firstOrFail()->delete();

        return response()->json(['message' => 'Device removed.']);
    }
}
