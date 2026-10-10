<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Queues
    |--------------------------------------------------------------------------
    | Dedicated queue names let you scale notification / report workers
    | independently in production. Both default to "default" so a single
    | `php artisan queue:work` processes everything out of the box.
    */
    'queues' => [
        'notifications' => env('OMS_NOTIFICATIONS_QUEUE', 'default'),
        'reports' => env('OMS_REPORTS_QUEUE', 'default'),
        // Report exports asked for on the Reports page. Give them their own queue and list it first on the
        // workers (--queue=exports,default,…) and an export never waits behind a backlog of notifications.
        'exports' => env('OMS_EXPORTS_QUEUE', 'default'),
    ],

    /*
    |--------------------------------------------------------------------------
    | SMS gateway
    |--------------------------------------------------------------------------
    | driver: "log" (writes to the log, nothing is sent), "africastalking",
    | "twilio" or "http" (generic JSON POST provider).
    */
    'sms' => [
        'driver' => env('SMS_DRIVER', 'log'),
        // Sender ID / short code / from-number shown to recipients (provider-specific rules apply).
        'sender' => env('SMS_SENDER', 'OMS'),
        // Local numbers like 0772123456 become +256772123456 using this calling code (no "+").
        'default_country_code' => env('SMS_DEFAULT_COUNTRY_CODE', '256'),
        // Hard cap per message; longer texts are trimmed (keeps multi-part SMS costs predictable).
        'max_length' => (int) env('SMS_MAX_LENGTH', 306),

        'http' => [
            'url' => env('SMS_URL', ''),
            'token' => env('SMS_TOKEN'),
        ],

        'africastalking' => [
            'username' => env('AFRICASTALKING_USERNAME', 'sandbox'),
            'api_key' => env('AFRICASTALKING_API_KEY'),
            'sandbox' => (bool) env('AFRICASTALKING_SANDBOX', true),
        ],

        'twilio' => [
            'sid' => env('TWILIO_SID'),
            'token' => env('TWILIO_TOKEN'),
            'from' => env('TWILIO_FROM'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Security
    |--------------------------------------------------------------------------
    */
    // Comma-separated load balancer / proxy IPs or CIDRs, or "*" to trust the immediate hop.
    'trusted_proxies' => env('TRUSTED_PROXIES', ''),

    'security' => [
        // Require every user to enrol an authenticator app (TOTP) before using the panel.
        'require_mfa' => (bool) env('OMS_REQUIRE_MFA', false),
        // Send Strict-Transport-Security (enable only when served exclusively over HTTPS).
        'hsts' => (bool) env('OMS_HSTS', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Mobile app (dispatchers, technicians, engineers) — /api/v1
    |--------------------------------------------------------------------------
    */
    'mobile' => [
        // Days a sign-in on a phone stays valid; after that the app asks for the password again.
        'token_days' => (int) env('OMS_MOBILE_TOKEN_DAYS', 30),
        // Oldest app version the API still serves (empty = any). Older apps are told to update.
        'min_version' => env('OMS_MOBILE_MIN_VERSION', ''),
        // Uploaded photos: largest file in kilobytes.
        'max_upload_kb' => (int) env('OMS_MOBILE_MAX_UPLOAD_KB', 12288),
        // Notifications reach an open app over Reverb; in the background or closed they are pushed through
        // Firebase Cloud Messaging. Path to the Firebase service-account key (JSON) — keep it outside git,
        // e.g. storage/app/private/firebase/service-account.json. Empty = no pushes (open app only).
        'fcm_credentials' => env('OMS_FCM_CREDENTIALS', ''),
    ],

    // Rows rendered on-screen in the reports page; exports always include every row.
    'report_screen_limit' => (int) env('OMS_REPORT_SCREEN_LIMIT', 500),
];
