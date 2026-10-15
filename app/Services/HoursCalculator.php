<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\DB;
use App\Core\ValidationException;
use DateTimeImmutable;
use DateTimeZone;

final class HoursCalculator
{
    /**
     * Net minutes = (end - start) - unpaid break, measured on the UTC timeline,
     * so a night shift across a clock change counts its real length.
     */
    public static function netMinutes(string $startUtc, string $endUtc, int $breakMinutes): int
    {
        $utc = new DateTimeZone('UTC');
        $start = new DateTimeImmutable($startUtc, $utc);
        $end = new DateTimeImmutable($endUtc, $utc);
        $gross = intdiv($end->getTimestamp() - $start->getTimestamp(), 60);
        if ($gross <= 0) {
            throw new ValidationException('The end time must be after the start time.');
        }
        if ($breakMinutes < 0) {
            throw new ValidationException('Break minutes cannot be negative.');
        }
        if ($breakMinutes >= $gross) {
            throw new ValidationException('The break must be shorter than the shift.');
        }
        return $gross - $breakMinutes;
    }

    /**
     * Builds UTC start/end from a UK date and HH:MM times. An end time at or before the
     * start time means the shift finishes the next day (overnight).
     * @return array{0:string,1:string,2:bool} [startUtc, endUtc, overnight]
     */
    public static function utcSpan(string $ukDate, string $startTime, string $endTime): array
    {
        $uk = new DateTimeZone('Europe/London');
        $start = new DateTimeImmutable("$ukDate $startTime", $uk);
        $endDate = $ukDate;
        $overnight = false;
        if ($endTime <= $startTime) {
            $endDate = $start->modify('+1 day')->format('Y-m-d');
            $overnight = true;
        }
        $end = new DateTimeImmutable("$endDate $endTime", $uk);
        $utc = new DateTimeZone('UTC');
        return [$start->setTimezone($utc)->format('Y-m-d H:i:s'), $end->setTimezone($utc)->format('Y-m-d H:i:s'), $overnight];
    }

    /** Weekly limit in minutes: employee override, then contract hours, then the global setting. */
    public static function limitMinutes(array $employee): int
    {
        $hours = $employee['weekly_hours_limit'] ?? null;
        if ($hours === null || $hours === '') {
            $hours = $employee['contract_hours'] ?? null;
        }
        if ($hours === null || $hours === '') {
            $hours = Settings::get('weekly_hours_limit', 37.5);
        }
        return (int) round((float) $hours * 60);
    }

    /** Scheduled (draft + published) minutes for an employee in a UK week. */
    public static function scheduledMinutes(int $employeeId, string $weekStart, ?int $excludeShiftId = null): int
    {
        return (int) DB::value(
            "SELECT COALESCE(SUM(net_minutes), 0) FROM shifts
              WHERE employee_id = ? AND week_start = ? AND status <> 'cancelled' AND id <> ?",
            [$employeeId, $weekStart, $excludeShiftId ?? 0]
        );
    }

    /**
     * Employees whose scheduled minutes exceed their limit in the given week.
     * @return array<int, array> rows with employee fields, scheduled, limit, excess, exception
     */
    public static function overLimitForWeek(string $weekStart, ?int $departmentId = null): array
    {
        $params = [$weekStart];
        $deptSql = '';
        if ($departmentId) {
            $deptSql = ' AND e.department_id = ?';
            $params[] = $departmentId;
        }
        $rows = DB::all(
            "SELECT e.id, e.employee_code, e.first_name, e.last_name, e.weekly_hours_limit, e.contract_hours,
                    d.name AS department, SUM(s.net_minutes) AS scheduled
               FROM shifts s
               JOIN employees e ON e.id = s.employee_id
               LEFT JOIN departments d ON d.id = e.department_id
              WHERE s.week_start = ? AND s.status <> 'cancelled' AND e.deleted_at IS NULL $deptSql
              GROUP BY e.id",
            $params
        );
        $out = [];
        foreach ($rows as $r) {
            $limit = self::limitMinutes($r);
            $scheduled = (int) $r['scheduled'];
            if ($scheduled > $limit) {
                $exception = DB::one('SELECT * FROM hours_exceptions WHERE employee_id = ? AND week_start = ?', [$r['id'], $weekStart]);
                $r['scheduled'] = $scheduled;
                $r['limit'] = $limit;
                $r['excess'] = $scheduled - $limit;
                $r['exception'] = ($exception && (int) $exception['approved_minutes'] >= $scheduled) ? $exception : null;
                $r['week_start'] = $weekStart;
                $out[] = $r;
            }
        }
        usort($out, fn ($a, $b) => $b['excess'] <=> $a['excess']);
        return $out;
    }
}
