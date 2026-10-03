<?php

namespace App\Services\Mobile;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Sends a push notification to one phone through Firebase Cloud Messaging (HTTP v1): how a
 * notification reaches the mobile app when it is in the background or closed. (While the app is
 * open it gets the same notification at once over Reverb — see App\Events\MobileNotification.)
 *
 * Needs the Firebase project's service-account key (a JSON file downloaded from the Firebase
 * console) on this server, outside git: OMS_FCM_CREDENTIALS=<path>. Without it nothing is pushed
 * and notifications only reach an app that is open.
 *
 * What travels through Google is deliberately little: a title, the ticket number and identifiers.
 * The app fetches the ticket itself, and the API decides whether it may be shown.
 */
class FcmSender
{
    public const PROVIDER = 'fcm';

    /** The Android notification channel the app creates ("Ticket alerts"). */
    public const ANDROID_CHANNEL = 'oms_tickets';

    private const SCOPE = 'https://www.googleapis.com/auth/firebase.messaging';

    private const TOKEN_CACHE = 'oms.fcm.access-token';

    /** The phone's token is no longer valid (app removed, token rotated): forget it. */
    public const INVALID = 'invalid';

    public const SENT = 'sent';

    /** @var array<string, mixed>|null */
    private ?array $credentials = null;

    public function configured(): bool
    {
        return $this->credentials() !== null;
    }

    /**
     * @param  array{title: string, body?: ?string}  $notification  what the phone shows
     * @param  array<string, scalar|null>  $data  identifiers for the app (sent as strings)
     * @return string self::SENT or self::INVALID
     *
     * @throws RuntimeException when Firebase could not be reached or refused the request (the job retries)
     */
    public function send(string $deviceToken, array $notification, array $data, ?string $tag = null): string
    {
        $credentials = $this->credentials() ?? throw new RuntimeException('Firebase is not configured (OMS_FCM_CREDENTIALS).');

        $response = Http::withToken($this->accessToken($credentials))
            ->timeout(15)
            ->acceptJson()
            ->post("https://fcm.googleapis.com/v1/projects/{$credentials['project_id']}/messages:send", [
                'message' => [
                    'token' => $deviceToken,
                    'notification' => array_filter(['title' => $notification['title'], 'body' => $notification['body'] ?? null]),
                    // FCM data values must all be strings.
                    'data' => array_map(fn ($value) => (string) $value, array_filter($data, fn ($value) => $value !== null)),
                    'android' => [
                        'priority' => 'HIGH',
                        'notification' => array_filter(['channel_id' => self::ANDROID_CHANNEL, 'tag' => $tag]),
                    ],
                    'apns' => [
                        'headers' => ['apns-priority' => '10'],
                        'payload' => ['aps' => ['sound' => 'default']],
                    ],
                ],
            ]);

        if ($response->successful()) {
            return self::SENT;
        }

        $status = (string) $response->json('error.status');
        $code = (string) $response->json('error.details.0.errorCode');

        if ($response->status() === 404 || $status === 'NOT_FOUND' || $code === 'UNREGISTERED'
            || ($response->status() === 400 && $status === 'INVALID_ARGUMENT' && str_contains((string) $response->json('error.message'), 'registration token'))) {
            return self::INVALID;
        }

        // Our own access token was refused: get a fresh one on the retry.
        if ($response->status() === 401) {
            Cache::forget(self::TOKEN_CACHE);
        }

        throw new RuntimeException("Firebase refused the push ({$response->status()} {$status}).");
    }

    /**
     * A short-lived Google access token for the service account: a signed JWT exchanged at Google's
     * token endpoint, cached until shortly before it expires.
     *
     * @param  array<string, mixed>  $credentials
     */
    private function accessToken(array $credentials): string
    {
        $cached = Cache::get(self::TOKEN_CACHE);

        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $now = time();
        $encode = fn (array $part) => rtrim(strtr(base64_encode((string) json_encode($part)), '+/', '-_'), '=');
        $unsigned = $encode(['alg' => 'RS256', 'typ' => 'JWT']).'.'.$encode([
            'iss' => $credentials['client_email'],
            'scope' => self::SCOPE,
            'aud' => $credentials['token_uri'],
            'iat' => $now,
            'exp' => $now + 3600,
        ]);

        if (! openssl_sign($unsigned, $signature, $credentials['private_key'], OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('The Firebase service-account key could not be used to sign the request.');
        }

        $response = Http::asForm()->timeout(15)->post($credentials['token_uri'], [
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion' => $unsigned.'.'.rtrim(strtr(base64_encode($signature), '+/', '-_'), '='),
        ]);

        $token = $response->json('access_token');

        if (! $response->successful() || ! is_string($token) || $token === '') {
            throw new RuntimeException("Google did not issue an access token for Firebase ({$response->status()}).");
        }

        Cache::put(self::TOKEN_CACHE, $token, max(60, (int) $response->json('expires_in', 3600) - 120));

        return $token;
    }

    /** @return array<string, mixed>|null the service-account key, or null when Firebase is not set up */
    private function credentials(): ?array
    {
        if ($this->credentials !== null) {
            return $this->credentials;
        }

        $path = trim((string) config('oms.mobile.fcm_credentials'));

        if ($path === '') {
            return null;
        }

        // A relative path is taken from the application's root.
        $file = is_file($path) ? $path : base_path($path);

        if (! is_file($file) || ! is_readable($file)) {
            return null;
        }

        $json = json_decode((string) file_get_contents($file), true);

        if (! is_array($json) || blank($json['project_id'] ?? null) || blank($json['client_email'] ?? null) || blank($json['private_key'] ?? null)) {
            return null;
        }

        return $this->credentials = $json + ['token_uri' => 'https://oauth2.googleapis.com/token'];
    }
}
