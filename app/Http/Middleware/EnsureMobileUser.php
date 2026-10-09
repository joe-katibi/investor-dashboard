<?php

namespace App\Http\Middleware;

use App\Services\Mobile\MobileProfile;
use App\Support\AuditContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Every API request: the account must still be active and still be a dispatcher, technician or
 * engineer. Deactivating someone — or changing their role — takes effect on their next request,
 * and the token they held is revoked.
 */
class EnsureMobileUser
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || ! $user->is_active) {
            $user?->currentAccessToken()?->delete();

            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        // Only tokens issued by the app's own sign-in are accepted here.
        if (! $user->tokenCan('mobile')) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        if (MobileProfile::role($user) === null) {
            return response()->json(['message' => 'Your account is not authorized to use the mobile application.', 'code' => 'not_mobile_user'], 403);
        }

        $minimum = (string) config('oms.mobile.min_version');
        $version = (string) $request->header('X-App-Version');

        if ($minimum !== '' && $version !== '' && version_compare($version, $minimum, '<')) {
            return response()->json(['message' => 'This version of the app is too old. Please update it.', 'code' => 'update_required'], 426);
        }

        // Everything this request changes is recorded as done from the mobile app (audit trail, status
        // and assignment history).
        return AuditContext::as(AuditContext::MOBILE, fn () => $next($request));
    }
}
