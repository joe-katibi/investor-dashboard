<?php

namespace App\Services\Tickets;

use App\Support\Settings;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * The SLA clock of customer tickets and site visits. In "business" mode only the working day
 * counts (07:00–18:00 by default, on the configured days): a ticket raised at 19:30 starts
 * counting at 07:00 the next working day, and one raised at 17:00 with a 3-hour target is due at
 * 09:00 the next working day. In "wall" mode every hour counts.
 */
class BusinessClock
{
    public function __construct(private readonly Settings $settings) {}

    public function isBusiness(): bool
    {
        return $this->settings->get('ticketing.sla_clock') === 'business' && $this->dayLength() > 0;
    }

    /** The moment the clock starts for something raised at $moment. */
    public function start(CarbonInterface $moment): CarbonImmutable
    {
        $moment = CarbonImmutable::instance($moment);

        return $this->isBusiness() ? $this->align($moment) : $moment;
    }

    /** The moment $minutes of SLA time have passed since $from. */
    public function add(CarbonInterface $from, int $minutes): CarbonImmutable
    {
        $cursor = CarbonImmutable::instance($from);
        $minutes = max(0, $minutes);

        if (! $this->isBusiness()) {
            return $cursor->addMinutes($minutes);
        }

        $cursor = $this->align($cursor);
        $remaining = $minutes * 60;

        // Bounded: a target never spans more than a few thousand working days.
        for ($guard = 0; $remaining > 0 && $guard < 5000; $guard++) {
            $end = $this->at($cursor, $this->minuteOfDay('ticketing.day_end', 18 * 60));
            $available = max(0, $end->getTimestamp() - $cursor->getTimestamp());

            if ($remaining <= $available) {
                return $cursor->addSeconds($remaining);
            }

            $remaining -= $available;
            $cursor = $this->align($end->addSecond());
        }

        return $cursor;
    }

    /** SLA minutes that passed between two moments (0 when $to is not after the clock start). */
    public function minutesBetween(CarbonInterface $from, CarbonInterface $to): int
    {
        $from = CarbonImmutable::instance($from);
        $to = CarbonImmutable::instance($to);

        if (! $this->isBusiness()) {
            return max(0, intdiv($to->getTimestamp() - $from->getTimestamp(), 60));
        }

        $cursor = $this->align($from);
        $seconds = 0;

        for ($guard = 0; $cursor->lessThan($to) && $guard < 5000; $guard++) {
            $end = $this->at($cursor, $this->minuteOfDay('ticketing.day_end', 18 * 60));
            $stop = $end->lessThan($to) ? $end : $to;
            $seconds += max(0, $stop->getTimestamp() - $cursor->getTimestamp());

            if ($to->lessThanOrEqualTo($end)) {
                break;
            }

            $cursor = $this->align($end->addSecond());
        }

        return intdiv($seconds, 60);
    }

    public function hoursBetween(CarbonInterface $from, CarbonInterface $to): float
    {
        return round($this->minutesBetween($from, $to) / 60, 2);
    }

    /** "07:00–18:00, every day" */
    public function describe(): string
    {
        if (! $this->isBusiness()) {
            return 'Every hour counts (24 / 7)';
        }

        $names = [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'];
        $days = $this->workingDays();

        return $this->settings->get('ticketing.day_start').'–'.$this->settings->get('ticketing.day_end').', '
            .(count($days) === 7 ? 'every day' : implode(' ', array_map(fn (int $day) => $names[$day], $days)));
    }

    /** Move a moment into working time: inside the day it stays, otherwise the next working day's start. */
    private function align(CarbonImmutable $moment): CarbonImmutable
    {
        $start = $this->minuteOfDay('ticketing.day_start', 7 * 60);
        $end = $this->minuteOfDay('ticketing.day_end', 18 * 60);
        $days = $this->workingDays();

        for ($guard = 0; $guard < 14; $guard++) {
            $working = in_array($moment->isoWeekday(), $days, true);

            if ($working && $moment->lessThan($this->at($moment, $start))) {
                return $this->at($moment, $start);
            }

            if ($working && $moment->lessThan($this->at($moment, $end))) {
                return $moment;
            }

            $moment = $this->at($moment->addDay(), $start);

            if (in_array($moment->isoWeekday(), $days, true)) {
                return $moment;
            }
        }

        return $moment;
    }

    private function at(CarbonImmutable $day, int $minuteOfDay): CarbonImmutable
    {
        return $day->setTime(intdiv($minuteOfDay, 60), $minuteOfDay % 60, 0);
    }

    private function dayLength(): int
    {
        return $this->minuteOfDay('ticketing.day_end', 18 * 60) - $this->minuteOfDay('ticketing.day_start', 7 * 60);
    }

    /** "07:30" → 450. Accepts a bare hour ("7") too. */
    private function minuteOfDay(string $key, int $default): int
    {
        $value = trim((string) $this->settings->get($key));

        if (preg_match('/^(\d{1,2}):(\d{2})$/', $value, $m) && (int) $m[1] <= 24 && (int) $m[2] < 60) {
            return min(24 * 60, (int) $m[1] * 60 + (int) $m[2]);
        }

        return ctype_digit($value) && (int) $value <= 24 ? (int) $value * 60 : $default;
    }

    /** @return list<int> ISO week days that count (all seven when none are configured) */
    private function workingDays(): array
    {
        $days = array_values(array_unique(array_filter(array_map('intval', (array) $this->settings->get('ticketing.working_days')), fn (int $day) => $day >= 1 && $day <= 7)));
        sort($days);

        return $days ?: [1, 2, 3, 4, 5, 6, 7];
    }
}
