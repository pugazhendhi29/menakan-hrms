<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\DB;
use App\Core\Validator;

/**
 * Reporting periods, deadlines and who is expected to report.
 *
 * Daily = one UK date; Weekly = Monday to Sunday; Monthly = a calendar month. Deadlines come from Settings:
 * daily reports are due at report_daily_due_time on the day, weekly report_weekly_due_days after the Sunday,
 * monthly report_monthly_due_days after the month end (both at the same time of day).
 * Reports follow the rota, from the employee's start date (never before EARLIEST): a daily report is expected on every day with a
 * published shift, a weekly report for every week with one, and a monthly report for every month with one -
 * days on approved leave do not count.
 */
final class ReportPeriod
{
    /** No report - daily, weekly or monthly - is expected or can be started for a period before this date. */
    public const EARLIEST = '2026-06-01';

    /** The first day an employee reports for: their start date, or EARLIEST if later. */
    public static function firstDay(?string $startDate): string
    {
        return max((string) $startDate, self::EARLIEST);
    }

    /** @return array{0:string,1:string} [start, end] (Y-m-d) of the period containing $date */
    public static function bounds(string $period, string $date): array
    {
        $date = Validator::isDate($date) ? $date : uk_today();
        return match ($period) {
            'daily' => [$date, $date],
            'weekly' => [$s = WeekBoundary::weekStartForDate($date), date('Y-m-d', strtotime("$s +6 days"))],
            'monthly' => [substr($date, 0, 8) . '01', date('Y-m-t', strtotime($date))],
            default => throw new \InvalidArgumentException("Unknown period $period"),
        };
    }

    public static function label(string $period, string $start): string
    {
        $d = strtotime($start);
        return match ($period) {
            'daily' => date('D j M Y', $d),
            'weekly' => 'Week commencing ' . date('j M Y', $d),
            'monthly' => date('F Y', $d),
        };
    }

    public static function shortLabel(string $period, string $start): string
    {
        $d = strtotime($start);
        return match ($period) {
            'daily' => date('D d/m', $d),
            'weekly' => 'w/c ' . date('d/m', $d),
            'monthly' => date('M Y', $d),
        };
    }

    /** Start of the previous / next period. */
    public static function shift(string $period, string $start, int $by): string
    {
        return match ($period) {
            'daily' => date('Y-m-d', strtotime($start . sprintf(' %+d days', $by))),
            'weekly' => date('Y-m-d', strtotime($start . sprintf(' %+d days', $by * 7))),
            'monthly' => date('Y-m-01', strtotime(substr($start, 0, 8) . '01' . sprintf(' %+d months', $by))),
        };
    }

    public static function dueTime(): string
    {
        $t = (string) setting('report_daily_due_time', '18:00');
        return preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $t) ? $t : '18:00';
    }

    /** Deadline (UTC) of a period that ends on $end. */
    public static function dueAt(string $period, string $end): string
    {
        $days = match ($period) {
            'daily' => 0,
            'weekly' => max(0, (int) setting('report_weekly_due_days', 1)),
            'monthly' => max(0, (int) setting('report_monthly_due_days', 3)),
        };
        return to_utc(date('Y-m-d', strtotime("$end +$days days")) . ' ' . self::dueTime());
    }

    public static function startDate(): string
    {
        $d = (string) setting('report_start_date', '2026-11-02');
        return Validator::isDate($d) ? $d : '2026-11-02';
    }

    /** @return int[] ISO weekdays daily reports are written on */
    public static function dailyDays(): array
    {
        $days = array_values(array_filter(array_map('intval', explode(',', (string) setting('report_daily_days', '1,2,3,4,5'))), fn ($d) => $d >= 1 && $d <= 7));
        return $days ?: [1, 2, 3, 4, 5];
    }

    /** Bank holidays between two dates (Y-m-d => true). */
    public static function holidays(string $from, string $to): array
    {
        return array_fill_keys(DB::column('SELECT holiday_date FROM public_holidays WHERE holiday_date BETWEEN ? AND ? AND region = ?',
            [$from, $to, (string) setting('bank_holiday_region', 'england-and-wales')]), true);
    }

    /** Approved leave: employee id => [Y-m-d => true] for the dates in range. */
    public static function leaveDays(array $employeeIds, string $from, string $to): array
    {
        if (!$employeeIds) {
            return [];
        }
        $out = [];
        $rows = DB::all('SELECT employee_id, start_date, end_date FROM leave_requests WHERE status = \'approved\' AND start_date <= ? AND end_date >= ? AND employee_id IN ('
            . DB::placeholders($employeeIds) . ')', [$to, $from, ...$employeeIds]);
        foreach ($rows as $r) {
            for ($d = max($r['start_date'], $from); $d <= min($r['end_date'], $to); $d = date('Y-m-d', strtotime("$d +1 day"))) {
                $out[(int) $r['employee_id']][$d] = true;
            }
        }
        return $out;
    }

    /** Days with a published shift: employee id => [Y-m-d (UK) => true] for the dates in range. */
    public static function shiftDays(array $employeeIds, string $from, string $to): array
    {
        if (!$employeeIds) {
            return [];
        }
        $out = [];
        $rows = DB::all("SELECT employee_id, start_at FROM shifts WHERE status = 'published' AND start_at >= ? AND start_at < ? AND employee_id IN ("
            . DB::placeholders($employeeIds) . ')', [to_utc("$from 00:00"), to_utc(date('Y-m-d', strtotime("$to +1 day")) . ' 00:00'), ...$employeeIds]);
        foreach ($rows as $r) {
            $out[(int) $r['employee_id']][WeekBoundary::ukDateOfUtc($r['start_at'])] = true;
        }
        return $out;
    }

    /** Statuses that count as working (employees on a career break, suspended etc. are not asked to report). */
    public static function workingStatuses(): array
    {
        static $codes = null;
        return $codes ??= DB::column('SELECT code FROM employee_statuses WHERE counts_as_working = 1');
    }

    /**
     * Who is expected to submit a report for each period start in the range.
     * @param int[] $employeeIds
     * @return array<int, string[]> employee id => period starts (Y-m-d)
     */
    public static function expected(array $employeeIds, string $period, string $from, string $to): array
    {
        $people = ReportAccess::people();
        $working = self::workingStatuses();
        $today = uk_today();
        $first = self::bounds($period, $from)[0];
        $last = self::bounds($period, $to)[1];
        $shifts = self::shiftDays($employeeIds, $first, $last);
        $leave = self::leaveDays($employeeIds, $first, $last);
        $out = [];
        foreach ($employeeIds as $id) {
            $p = $people[$id] ?? null;
            if (!$p || ReportAccess::roleOf($id) === null || !in_array($p['status'], $working, true)) {
                continue;
            }
            $out[$id] = [];
            $worked = [];   // period start => true when the employee had a shift (not on leave) in it
            $firstDay = self::firstDay($p['start_date']);
            foreach (array_keys($shifts[$id] ?? []) as $d) {
                if ($d >= $firstDay && !isset($leave[$id][$d])) {
                    $worked[self::bounds($period, $d)[0]] = true;
                }
            }
            for ($s = self::bounds($period, $from)[0]; $s <= $to; $s = self::shift($period, $s, 1)) {
                $end = self::bounds($period, $s)[1];
                if ($s > $today || $end < $firstDay || !isset($worked[$s])) {
                    continue;
                }
                $out[$id][] = $s;
            }
        }
        return $out;
    }
}
