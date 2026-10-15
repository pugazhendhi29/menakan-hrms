<?php

declare(strict_types=1);

namespace App\Services;

use DateTimeImmutable;

/**
 * Counts leave days: working days in the employee's pattern, excluding bank holidays.
 * Half days: $startHalf = the first day starts at midday; $endHalf = the last day ends at midday.
 */
final class LeaveDayCounter
{
    public const DAY_KEYS = [1 => 'mon', 2 => 'tue', 3 => 'wed', 4 => 'thu', 5 => 'fri', 6 => 'sat', 7 => 'sun'];

    /**
     * @param array<string,int|bool> $pattern keys mon..sun, truthy = working day
     * @param string[] $holidays Y-m-d dates
     */
    public static function count(string $start, string $end, array $pattern, array $holidays, bool $startHalf = false, bool $endHalf = false): float
    {
        if ($end < $start) {
            return 0.0;
        }
        $holidaySet = array_flip($holidays);
        $days = 0.0;
        $d = new DateTimeImmutable($start);
        $last = new DateTimeImmutable($end);
        while ($d <= $last) {
            $ymd = $d->format('Y-m-d');
            if (self::isWorkingDay($d, $pattern) && !isset($holidaySet[$ymd])) {
                $value = 1.0;
                if ($ymd === $start && $startHalf) {
                    $value -= 0.5;
                }
                if ($ymd === $end && $endHalf) {
                    $value -= 0.5;
                }
                // A single half day (start and end the same day with one half flag) counts 0.5.
                $days += max(0.5, $value);
            }
            $d = $d->modify('+1 day');
        }
        return $days;
    }

    public static function isWorkingDay(DateTimeImmutable $d, array $pattern): bool
    {
        return !empty($pattern[self::DAY_KEYS[(int) $d->format('N')]]);
    }

    public static function workingDaysPerWeek(array $pattern): int
    {
        $n = 0;
        foreach (self::DAY_KEYS as $k) {
            $n += empty($pattern[$k]) ? 0 : 1;
        }
        return $n;
    }

    /**
     * Annual entitlement in days: weeks x working days per week (capped at 28 for 5.6 weeks,
     * the statutory maximum), pro-rated for starters/leavers and rounded up to the nearest half day.
     */
    public static function entitlement(float $weeks, int $daysPerWeek, string $yearStart, string $yearEnd, ?string $empStart, ?string $empEnd): float
    {
        $full = min($weeks * $daysPerWeek, $weeks * 5);
        $from = max($yearStart, (string) $empStart);
        $to = $empEnd ? min($yearEnd, $empEnd) : $yearEnd;
        if ($to < $from) {
            return 0.0;
        }
        $yearDays = (new DateTimeImmutable($yearStart))->diff(new DateTimeImmutable($yearEnd))->days + 1;
        $employed = (new DateTimeImmutable($from))->diff(new DateTimeImmutable($to))->days + 1;
        $value = $full * $employed / $yearDays;
        return ceil($value * 2) / 2;
    }
}
