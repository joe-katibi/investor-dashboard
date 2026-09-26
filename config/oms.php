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
        // Local numbers like 0991234567 become +265991234567 using this calling code (no "+").
        'default_country_code' => env('SMS_DEFAULT_COUNTRY_CODE', '265'),
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

    // Rows rendered on-screen in the reports page; exports always include every row.
    'report_screen_limit' => (int) env('OMS_REPORT_SCREEN_LIMIT', 500),
];
