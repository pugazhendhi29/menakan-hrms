<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\DB;
use DateTimeImmutable;

/**
 * Working Time Regulations checks, separate from the company's 37.5h rule:
 * a 48-hour average over a 17-week reference period, and 11 hours' daily rest.
 */
final class WorkingTimeChecker
{
    /**
     * Pure check of rest gaps between consecutive shifts.
     * @param array<int, array{start_at:string,end_at:string,id?:int}> $shifts sorted or unsorted
     * @return array<int, array{after:array,before:array,rest_minutes:int}>
     */
    public static function restBreaches(array $shifts, int $minRestHours): array
    {
        usort($shifts, fn ($a, $b) => strcmp($a['start_at'], $b['start_at']));
        $out = [];
        for ($i = 1; $i < count($shifts); $i++) {
            $gap = intdiv(strtotime($shifts[$i]['start_at'] . ' UTC') - strtotime($shifts[$i - 1]['end_at'] . ' UTC'), 60);
            if ($gap < $minRestHours * 60) {
                $out[] = ['after' => $shifts[$i - 1], 'before' => $shifts[$i], 'rest_minutes' => $gap];
            }
        }
        return $out;
    }

    /** Average weekly minutes over $weeks weeks ending with (and including) $weekStart. */
    public static function averageWeeklyMinutes(int $employeeId, string $weekStart, int $weeks): float
    {
        $from = (new DateTimeImmutable($weekStart))->modify('-' . ($weeks - 1) . ' weeks')->format('Y-m-d');
        $total = (int) DB::value(
            "SELECT COALESCE(SUM(net_minutes), 0) FROM shifts
              WHERE employee_id = ? AND status <> 'cancelled' AND week_start BETWEEN ? AND ?",
            [$employeeId, $from, $weekStart]
        );
        return $total / $weeks;
    }

    /**
     * Warnings for a proposed shift (not yet saved): rest breaches with neighbouring shifts
     * and the 17-week average including the new shift.
     * @return string[]
     */
    public static function warningsForProposed(int $employeeId, string $startUtc, string $endUtc, int $netMinutes, ?int $excludeShiftId = null): array
    {
        $restHours = (int) Settings::get('daily_rest_hours', 11);
        $neighbours = DB::all(
            "SELECT id, start_at, end_at FROM shifts
              WHERE employee_id = ? AND status <> 'cancelled' AND id <> ?
                AND start_at < DATE_ADD(?, INTERVAL 1 DAY) AND end_at > DATE_SUB(?, INTERVAL 1 DAY)",
            [$employeeId, $excludeShiftId ?? 0, $endUtc, $startUtc]
        );
        $neighbours[] = ['id' => 0, 'start_at' => $startUtc, 'end_at' => $endUtc];
        $warnings = [];
        foreach (self::restBreaches($neighbours, $restHours) as $b) {
            if ((int) ($b['after']['id'] ?? -1) === 0 || (int) ($b['before']['id'] ?? -1) === 0) {
                $warnings[] = sprintf('Only %s hours of rest between shifts (minimum %d hours).', number_format(max(0, $b['rest_minutes']) / 60, 1), $restHours);
            }
        }
        $weeks = (int) Settings::get('wtd_reference_weeks', 17);
        $limit = (int) Settings::get('wtd_average_limit_hours', 48) * 60;
        $week = WeekBoundary::weekStartForUtc($startUtc);
        $avg = self::averageWeeklyMinutes($employeeId, $week, $weeks) + $netMinutes / $weeks;
        if ($avg > $limit) {
            $warnings[] = sprintf('Average weekly hours over %d weeks would be %.1f (limit %d).', $weeks, $avg / 60, $limit / 60);
        }
        return $warnings;
    }
}
