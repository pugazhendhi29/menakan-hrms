<?php

declare(strict_types=1);

namespace App\Services;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Weeks run Monday 00:00 to Sunday 23:59 UK time. A shift belongs to the week of its UK start date.
 */
final class WeekBoundary
{
    private static function uk(): DateTimeZone
    {
        return new DateTimeZone('Europe/London');
    }

    /** Monday (Y-m-d) of the week containing a UK-local date (Y-m-d). */
    public static function weekStartForDate(string $ukDate): string
    {
        $d = new DateTimeImmutable($ukDate, self::uk());
        $dow = (int) $d->format('N'); // 1 = Monday
        return $d->modify('-' . ($dow - 1) . ' days')->format('Y-m-d');
    }

    /** Monday (Y-m-d) of the UK week in which a UTC datetime falls. */
    public static function weekStartForUtc(string $utc): string
    {
        $local = (new DateTimeImmutable($utc, new DateTimeZone('UTC')))->setTimezone(self::uk());
        return self::weekStartForDate($local->format('Y-m-d'));
    }

    /** UK-local date (Y-m-d) of a UTC datetime. */
    public static function ukDateOfUtc(string $utc): string
    {
        return (new DateTimeImmutable($utc, new DateTimeZone('UTC')))->setTimezone(self::uk())->format('Y-m-d');
    }

    /**
     * UTC bounds [start, end) of the UK week starting on $weekStart.
     * During a clock change the week is 167 or 169 real hours long.
     * @return array{0:string,1:string}
     */
    public static function utcRange(string $weekStart): array
    {
        $start = new DateTimeImmutable($weekStart . ' 00:00:00', self::uk());
        $end = $start->modify('+7 days');
        $utc = new DateTimeZone('UTC');
        return [$start->setTimezone($utc)->format('Y-m-d H:i:s'), $end->setTimezone($utc)->format('Y-m-d H:i:s')];
    }

    /** UTC bounds [start, end) of a range of UK dates (inclusive end date). */
    public static function utcRangeForDates(string $fromDate, string $toDate): array
    {
        $utc = new DateTimeZone('UTC');
        $start = (new DateTimeImmutable($fromDate . ' 00:00:00', self::uk()))->setTimezone($utc);
        $end = (new DateTimeImmutable($toDate . ' 00:00:00', self::uk()))->modify('+1 day')->setTimezone($utc);
        return [$start->format('Y-m-d H:i:s'), $end->format('Y-m-d H:i:s')];
    }

    /**
     * Mondays of every week that touches the given month, so the month calendar
     * includes days from neighbouring months and its week totals match the week view.
     * @return string[]
     */
    public static function monthGridWeeks(int $year, int $month): array
    {
        $first = sprintf('%04d-%02d-01', $year, $month);
        $last = (new DateTimeImmutable($first))->modify('last day of this month')->format('Y-m-d');
        $weeks = [];
        $monday = self::weekStartForDate($first);
        while ($monday <= $last) {
            $weeks[] = $monday;
            $monday = (new DateTimeImmutable($monday))->modify('+7 days')->format('Y-m-d');
        }
        return $weeks;
    }

    /** @return string[] the 7 dates Mon..Sun of a week */
    public static function daysOfWeek(string $weekStart): array
    {
        $d = new DateTimeImmutable($weekStart);
        return array_map(fn ($i) => $d->modify("+$i days")->format('Y-m-d'), range(0, 6));
    }
}
