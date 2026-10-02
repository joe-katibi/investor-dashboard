<?php

namespace App\Services\Outages;

use Illuminate\Support\Facades\DB;

/**
 * Gap-tolerant, collision-free ticket numbers (OUT-00000000001). A row-level lock on the
 * per-prefix sequence row serialises concurrent creators across every app node, on both
 * MySQL (InnoDB) and PostgreSQL.
 */
class TicketNumberGenerator
{
    public const DIGITS = 11;

    public function next(string $prefix, int $digits = self::DIGITS): string
    {
        $prefix = strtoupper(trim($prefix));

        return DB::transaction(function () use ($prefix, $digits): string {
            DB::table('ticket_sequences')->insertOrIgnore([
                'prefix' => $prefix,
                'last_number' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $current = (int) DB::table('ticket_sequences')
                ->where('prefix', $prefix)
                ->lockForUpdate()
                ->value('last_number');

            $next = $current + 1;

            DB::table('ticket_sequences')
                ->where('prefix', $prefix)
                ->update(['last_number' => $next, 'updated_at' => now()]);

            return $prefix.'-'.str_pad((string) $next, $digits, '0', STR_PAD_LEFT);
        });
    }
}
