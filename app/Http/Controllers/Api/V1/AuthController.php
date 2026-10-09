<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\MobileDevice;
use App\Models\User;
use App\Services\Mobile\MobileProfile;
use App\Support\AuditContext;
use Illuminate\Auth\Events\Login;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use PragmaRX\Google2FAQRCode\Google2FA;

/**
 * Sign-in for the mobile app. The same accounts and passwords as the web panel — nothing separate
 * to manage. A sign-in gives the phone a personal access token (Sanctum) that it keeps in the
 * phone's secure storage and sends with every request.
 */
class AuthController extends Controller
{
    /** Time steps either side of "now" an authenticator code is accepted for (as on the web sign-in). */
    private const CODE_WINDOW = 8;

    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'login' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'max:255'],
            'code' => ['nullable', 'string', 'max:20'],
            'device_name' => ['nullable', 'string', 'max:120'],
        ]);

        $login = trim($data['login']);
        $user = User::query()->where('email', $login)->orWhere('username', $login)->first();

        // One answer for "no such account", "wrong password" and "inactive": nothing to learn from it.
        if (! $user || ! Hash::check($data['password'], $user->password) || ! $user->is_active) {
            // An attempt on a real account is on its audit trail (never the password that was tried).
            AuditContext::as(AuditContext::MOBILE, fn () => $user?->audit('mobile_login_failed', $this->device($request, $data) + [
                'reason' => $user->is_active ? 'Wrong password' : 'Account is inactive',
            ], [], false));

            throw ValidationException::withMessages(['login' => 'These credentials do not match our records.']);
        }

        if (MobileProfile::role($user) === null) {
            return response()->json(['message' => 'Your account is not authorized to use the mobile application.', 'code' => 'not_mobile_user'], 403);
        }

        $this->checkSecondStep($user, $data['code'] ?? null);

        $days = max(1, (int) config('oms.mobile.token_days'));
        $token = $user->createToken(
            str($data['device_name'] ?? 'Mobile app')->limit(120, '')->toString(),
            ['mobile'],
            now()->addDays($days),
        );

        event(new Login('sanctum', $user, false));
        AuditContext::as(AuditContext::MOBILE, fn () => $user->audit('mobile_login', $this->device($request, $data), [], $user->id));

        return response()->json([
            'token' => $token->plainTextToken,
            'expires_at' => $token->accessToken->expires_at?->toIso8601String(),
            'user' => MobileProfile::describe($user),
        ]);
    }

    /** Ends this phone's session only: its token and its device row. Other phones stay signed in. */
    public function logout(Request $request): JsonResponse
    {
        $token = $request->user()->currentAccessToken();

        $request->user()->audit('mobile_logout', ['device' => $token->name, 'app_version' => $request->header('X-App-Version')]);
        MobileDevice::query()->where('user_id', $request->user()->id)->where('token_id', $token->id)->delete();
        $token->delete();

        return response()->json(['message' => 'Signed out.']);
    }

    /** @return array{device: string, app_version: ?string} which phone, and which version of the app */
    private function device(Request $request, array $data): array
    {
        return ['device' => str($data['device_name'] ?? 'Mobile app')->limit(120, '')->toString(), 'app_version' => $request->header('X-App-Version')];
    }

    /**
     * Accounts with an authenticator app (two-step sign-in) need the 6-digit code on the phone too;
     * when the organisation requires it for everyone, an account without one cannot use the app yet.
     */
    private function checkSecondStep(User $user, ?string $code): void
    {
        $secret = $user->getAppAuthenticationSecret();

        if (blank($secret)) {
            if (config('oms.security.require_mfa')) {
                abort(response()->json([
                    'message' => 'Two-step sign-in is required. Set up your authenticator app on the OMS website first, then sign in here.',
                    'code' => 'mfa_setup_required',
                ], 403));
            }

            return;
        }

        if (blank($code)) {
            abort(response()->json(['message' => 'Enter the 6-digit code from your authenticator app.', 'code' => 'mfa_required'], 422));
        }

        if (! app(Google2FA::class)->verifyKey($secret, preg_replace('/\s+/', '', $code), self::CODE_WINDOW)) {
            throw ValidationException::withMessages(['code' => 'That code is not valid. Check your authenticator app and try again.']);
        }
    }
}
