<?php

namespace App\Services\Notifications\Sms;

/**
 * Normalises phone numbers to E.164 (+265991234567) for SMS gateways.
 * Local numbers with a leading 0 get the configured default country code.
 */
final class PhoneNumber
{
    public static function normalise(?string $number): ?string
    {
        $raw = trim((string) $number);

        if ($raw === '') {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $raw);

        if (str_starts_with($raw, '+')) {
            $e164 = '+'.$digits;
        } elseif (str_starts_with($digits, '00')) {
            $e164 = '+'.substr($digits, 2);
        } elseif (str_starts_with($digits, '0')) {
            $e164 = '+'.preg_replace('/\D+/', '', (string) config('oms.sms.default_country_code')).substr($digits, 1);
        } else {
            $e164 = '+'.$digits;
        }

        // E.164: country code + subscriber number, 8–15 digits in total.
        return preg_match('/^\+[1-9]\d{7,14}$/', $e164) ? $e164 : null;
    }

    public static function isValid(?string $number): bool
    {
        return self::normalise($number) !== null;
    }
}
