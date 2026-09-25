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
    | driver: "log" (writes to the log) or "http" (generic JSON POST provider).
    */
    'sms' => [
        'driver' => env('SMS_DRIVER', 'log'),
        'url' => env('SMS_URL', ''),
        'token' => env('SMS_TOKEN'),
        'sender' => env('SMS_SENDER', 'OMS'),
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
