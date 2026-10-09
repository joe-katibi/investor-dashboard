<?php

namespace App\Support;

/**
 * Carries the "trigger" of the current unit of work so every audit row and history entry
 * can identify the system event that caused it (spec §21: automated actions must identify
 * their triggering event). Defaults to "user" for interactive requests.
 */
final class AuditContext
{
    /** Done by a person in the mobile app (every API request runs under it). */
    public const MOBILE = 'mobile';

    private static ?string $trigger = null;

    /** "Website", "Mobile app", "System (sla-check)" — for screens and reports. */
    public static function label(?string $trigger): string
    {
        return match (true) {
            $trigger === self::MOBILE => 'Mobile app',
            $trigger === 'user' => 'Website',
            $trigger === null, $trigger === '' => '—',
            str_starts_with($trigger, 'system:') => 'System ('.substr($trigger, 7).')',
            default => ucfirst($trigger),
        };
    }

    public static function trigger(): string
    {
        return self::$trigger ?? (app()->runningInConsole() ? 'system' : 'user');
    }

    /**
     * Run a callback under a named trigger, restoring the previous trigger afterwards.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function as(string $trigger, callable $callback): mixed
    {
        $previous = self::$trigger;
        self::$trigger = $trigger;

        try {
            return $callback();
        } finally {
            self::$trigger = $previous;
        }
    }
}
