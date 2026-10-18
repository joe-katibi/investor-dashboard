<?php

namespace App\Support;

use Illuminate\Mail\Mailables\Address;

/**
 * Which address (alias) each kind of e-mail comes from: outages, customer tickets, site visits, 4G
 * outages, 4G tickets, the executive summary and the alerts & updates. Set in .env (config oms.mail_from);
 * an Email Template may pick another one. An alias left empty sends from MAIL_FROM_ADDRESS.
 */
final class MailSenders
{
    public const OUTAGES = 'outages';

    public const CUSTOMER_TICKETS = 'customer_tickets';

    public const SITE_VISITS = 'site_visits';

    public const MOBILITY_OUTAGES = 'mobility_outages';

    public const MOBILITY_TICKETS = 'mobility_tickets';

    public const EXECUTIVE = 'executive';

    public const ALERTS = 'alerts';

    public const LABELS = [
        self::OUTAGES => 'Outages (FTTH & 4G summaries)',
        self::CUSTOMER_TICKETS => 'Customer tickets',
        self::SITE_VISITS => 'Site visit tickets',
        self::MOBILITY_OUTAGES => '4G outages',
        self::MOBILITY_TICKETS => '4G tickets',
        self::EXECUTIVE => 'Executive summary',
        self::ALERTS => 'Alerts & updates',
    ];

    /** The alias a report type goes out from unless its template says otherwise; null = the default address. */
    public static function forReport(?string $reportType): ?string
    {
        return match (true) {
            in_array($reportType, ['two_hour', 'summary', 'summary_week', 'summary_month'], true) => self::OUTAGES,
            $reportType === 'executive' => self::EXECUTIVE,
            $reportType === 'mobility_hourly' => self::MOBILITY_OUTAGES,
            str_starts_with((string) $reportType, 'ticket_4g_') => self::MOBILITY_TICKETS,
            str_starts_with((string) $reportType, 'ticket_') => self::CUSTOMER_TICKETS,
            default => null,
        };
    }

    /** The address of an alias, or null when it is not set (the mail then comes from MAIL_FROM_ADDRESS). */
    public static function email(?string $key): ?string
    {
        $email = $key ? trim((string) config("oms.mail_from.{$key}")) : '';

        return $email !== '' ? $email : null;
    }

    /** The sender of a mail: the alias with the usual sender name, or null for the default sender. */
    public static function address(?string $key): ?Address
    {
        $email = self::email($key);

        return $email ? new Address($email, (string) config('mail.from.name')) : null;
    }

    /** @return array<string, string> key => "Customer tickets — customertickets@…", for the template screen */
    public static function options(): array
    {
        return collect(self::LABELS)->mapWithKeys(fn (string $label, string $key) => [
            $key => $label.' — '.(self::email($key) ?? 'not set, the default address'),
        ])->all();
    }

    /** "customertickets@… (Customer tickets)" or the default address, for lists. */
    public static function describe(?string $key): string
    {
        $email = self::email($key);

        return $email ? $email.' ('.(self::LABELS[$key] ?? $key).')' : (string) config('mail.from.address').' (default)';
    }
}
