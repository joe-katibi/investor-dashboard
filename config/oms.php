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
    | Sending addresses (e-mail aliases)
    |--------------------------------------------------------------------------
    | Who each kind of e-mail comes from (App\Support\MailSenders). Empty: the
    | default MAIL_FROM_ADDRESS. The mail account (MAIL_USERNAME) must be allowed
    | to send as each of them ("Send As" on Microsoft 365 / Exchange).
    */
    'mail_from' => [
        'outages' => env('OMS_MAIL_FROM_OUTAGES'),
        'customer_tickets' => env('OMS_MAIL_FROM_CUSTOMER_TICKETS'),
        'site_visits' => env('OMS_MAIL_FROM_SITE_VISITS'),
        'mobility_outages' => env('OMS_MAIL_FROM_4G_OUTAGES'),
        'mobility_tickets' => env('OMS_MAIL_FROM_4G_TICKETS'),
        'executive' => env('OMS_MAIL_FROM_EXECUTIVE'),
        'alerts' => env('OMS_MAIL_FROM_ALERTS'),
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

    /*
    |--------------------------------------------------------------------------
    | Geolocation photos
    |--------------------------------------------------------------------------
    | The place name and the small map stamped on a geolocation photo come from
    | OpenStreetMap, asked for by this server (never by the phones) with nothing
    | but a latitude and a longitude. Switch it off under Customer Care → Ticket
    | Settings; point the addresses at your own Nominatim / tile server here.
    */
    'geo' => [
        'nominatim_url' => env('OMS_GEO_NOMINATIM_URL', 'https://nominatim.openstreetmap.org'),
        'tile_url' => env('OMS_GEO_TILE_URL', 'https://tile.openstreetmap.org/{z}/{x}/{y}.png'),
        // OpenStreetMap asks every application to identify itself, and refuses the ones that do not
        // (403 "Access denied"): name the application and where it lives.
        'user_agent' => env('OMS_GEO_USER_AGENT', 'SavannaFibre-OMS/1.0 (+https://oms.savannafibre.co.ug)'),
    ],

    // Rows rendered on-screen in the reports page; exports always include every row.
    'report_screen_limit' => (int) env('OMS_REPORT_SCREEN_LIMIT', 500),
];
