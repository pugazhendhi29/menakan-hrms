<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\DB;
use App\Core\ValidationException;
use App\Core\Validator;

final class ShiftService
{
    /**
     * Creates or updates a shift. Blocks overlaps; requires an over-limit reason when the
     * week would exceed the employee's limit (recorded as an hours exception).
     * @return array{id:int, warnings:string[]}
     */
    public static function save(?int $id, array $input, int $userId): array
    {
        $v = Validator::make($input, [
            'employee_id'   => 'required|integer',
            'date'          => 'required|date',
            'start_time'    => 'required|time',
            'end_time'      => 'required|time',
            'break_minutes' => 'required|integer|min:0|max:600',
            'shift_type_id' => 'nullable|integer',
            'location_id'   => 'nullable|integer',
            'status'        => 'required|in:draft,published',
            'notes'         => 'nullable|maxlen:255',
            'override_reason' => 'nullable|maxlen:255',
        ], ['employee_id' => 'Employee', 'start_time' => 'Start time', 'end_time' => 'End time', 'break_minutes' => 'Break minutes']);
        if ($v->fails()) {
            throw new ValidationException('Please correct the highlighted fields.', $v->errors());
        }
        $d = $v->validated();
        $employee = DB::one("SELECT * FROM employees WHERE id = ? AND deleted_at IS NULL AND status <> 'left'", [(int) $d['employee_id']]);
        if (!$employee) {
            throw new ValidationException('Please choose an active employee.', ['employee_id' => 'Please choose an active employee.']);
        }
        $existing = null;
        if ($id !== null) {
            $existing = DB::one('SELECT * FROM shifts WHERE id = ?', [$id]);
            if (!$existing || $existing['status'] === 'cancelled') {
                throw new ValidationException('Cancelled shifts cannot be edited.');
            }
        }
        [$startUtc, $endUtc] = HoursCalculator::utcSpan($d['date'], $d['start_time'], $d['end_time']);
        $net = HoursCalculator::netMinutes($startUtc, $endUtc, (int) $d['break_minutes']);
        if ($net > 16 * 60) {
            throw new ValidationException('A shift cannot be longer than 16 hours.');
        }

        $overlap = DB::one(
            "SELECT id, start_at, end_at FROM shifts WHERE employee_id = ? AND status <> 'cancelled' AND id <> ? AND start_at < ? AND end_at > ? LIMIT 1",
            [(int) $employee['id'], $id ?? 0, $endUtc, $startUtc]
        );
        if ($overlap) {
            throw new ValidationException(sprintf('This overlaps another shift for this employee (%s–%s).', uk_datetime($overlap['start_at']), uk_time($overlap['end_at'])));
        }

        $week = WeekBoundary::weekStartForUtc($startUtc);
        $limit = HoursCalculator::limitMinutes($employee);
        $total = HoursCalculator::scheduledMinutes((int) $employee['id'], $week, $id) + $net;
        $warnings = [];
        $approve = null;
        $isNew = $id === null;
        $exception = DB::one('SELECT * FROM hours_exceptions WHERE employee_id = ? AND week_start = ?', [(int) $employee['id'], $week]);
        if ($total > $limit && (!$exception || (int) $exception['approved_minutes'] < $total)) {
            $reason = trim((string) ($d['override_reason'] ?? ''));
            if ($reason === '') {
                throw new ValidationException(sprintf(
                    'This takes %s to %s hours in the week of %s, over their %s-hour limit. To continue, tick "Approve hours over the limit" and give a reason.',
                    employee_name($employee), hours($total, 1), uk_date($week), hours($limit, 1)
                ), ['override_reason' => 'A reason is required to approve hours over the limit.']);
            }
            $approve = [$limit, $total, $reason];
            $warnings[] = sprintf('Approved %s hours against a %s-hour limit for the week of %s.', hours($total, 1), hours($limit, 1), uk_date($week));
        }
        $leave = DB::one("SELECT lr.start_date, lr.end_date FROM leave_requests lr WHERE lr.employee_id = ? AND lr.status = 'approved' AND ? BETWEEN lr.start_date AND lr.end_date", [(int) $employee['id'], $d['date']]);
        if ($leave) {
            $warnings[] = 'The employee has approved leave on this date (' . uk_date($leave['start_date']) . '–' . uk_date($leave['end_date']) . ').';
        }
        $warnings = array_merge($warnings, WorkingTimeChecker::warningsForProposed((int) $employee['id'], $startUtc, $endUtc, $net, $id));

        $data = [
            'employee_id' => (int) $employee['id'], 'shift_type_id' => $d['shift_type_id'] ? (int) $d['shift_type_id'] : null,
            'location_id' => $d['location_id'] ? (int) $d['location_id'] : null, 'start_at' => $startUtc, 'end_at' => $endUtc,
            'break_minutes' => (int) $d['break_minutes'], 'net_minutes' => $net, 'week_start' => $week, 'status' => $d['status'],
            'notes' => $d['notes'] ?: null,
        ];
        $id = DB::transaction(function () use ($id, $data, $userId, $approve, $employee, $week, $d, $existing) {
            if ($approve) {
                self::approveException((int) $employee['id'], $week, $approve[0], $approve[1], $approve[2], $userId);
            }
            if ($id === null) {
                $id = DB::insert('shifts', $data + ['created_by' => $userId]);
                AuditLogger::log('shift.created', 'shift', $id, sprintf('Created %s shift for %s on %s', $d['status'], $employee['employee_code'], uk_date($d['date'])), $data);
            } else {
                DB::update('shifts', $data, 'id = ?', [$id]);
                AuditLogger::log('shift.updated', 'shift', $id, sprintf('Updated shift for %s on %s', $employee['employee_code'], uk_date($d['date'])), AuditLogger::diff($existing, $data));
            }
            return $id;
        });
        // Attendance follows the rota: record, update or remove it for this employee (and the previous one, if moved).
        AttendanceService::fillFromRota((int) $employee['id'], $userId);
        if ($existing && (int) $existing['employee_id'] !== (int) $employee['id']) {
            AttendanceService::fillFromRota((int) $existing['employee_id'], $userId);
        }
        // New shifts are not notified (employees see them in My Shifts); changes to a shift are.
        if ($d['status'] === 'published' && !$isNew) {
            Notifier::toEmployee((int) $employee['id'], 'shift', 'Shift updated: ' . uk_date($d['date']), $d['start_time'] . '–' . $d['end_time'], '/my/shifts');
        }
        return ['id' => $id, 'warnings' => $warnings];
    }

    public static function approveException(int $employeeId, string $week, int $limit, int $total, string $reason, int $userId): void
    {
        DB::query(
            'INSERT INTO hours_exceptions (employee_id, week_start, limit_minutes, approved_minutes, reason, approved_by, approved_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE limit_minutes = VALUES(limit_minutes), approved_minutes = VALUES(approved_minutes), reason = VALUES(reason),
                                     approved_by = VALUES(approved_by), approved_at = VALUES(approved_at)',
            [$employeeId, $week, $limit, $total, mb_substr($reason, 0, 255), $userId, now_utc()]
        );
        AuditLogger::log('hours_exception.approved', 'employee', $employeeId, sprintf('Approved %s hours (limit %s) for week of %s', hours($total, 1), hours($limit, 1), uk_date($week)), ['reason' => $reason]);
    }

    public static function cancel(int $id, int $userId): void
    {
        $s = DB::one('SELECT s.*, e.employee_code FROM shifts s JOIN employees e ON e.id = s.employee_id WHERE s.id = ?', [$id]);
        if (!$s || $s['status'] === 'cancelled') {
            throw new ValidationException('Shift not found or already cancelled.');
        }
        DB::update('shifts', ['status' => 'cancelled', 'cancelled_at' => now_utc()], 'id = ?', [$id]);
        AuditLogger::log('shift.cancelled', 'shift', $id, 'Cancelled shift for ' . $s['employee_code'] . ' on ' . uk_datetime($s['start_at']));
        AttendanceService::fillFromRota((int) $s['employee_id'], $userId);
        if ($s['status'] === 'published') {
            Notifier::toEmployee((int) $s['employee_id'], 'shift', 'Shift cancelled: ' . uk_datetime($s['start_at']), null, '/my/shifts');
        }
    }

    /** Publishes draft shifts in a UTC range (optionally for one department/employee). */
    public static function publishRange(string $fromUtc, string $toUtc, ?int $departmentId, ?int $employeeId, int $userId): int
    {
        $params = [$fromUtc, $toUtc];
        $extra = '';
        if ($departmentId) {
            $extra .= ' AND e.department_id = ?';
            $params[] = $departmentId;
        }
        if ($employeeId) {
            $extra .= ' AND s.employee_id = ?';
            $params[] = $employeeId;
        }
        $ids = DB::column("SELECT s.id FROM shifts s JOIN employees e ON e.id = s.employee_id WHERE s.status = 'draft' AND s.start_at >= ? AND s.start_at < ? $extra", $params);
        if (!$ids) {
            return 0;
        }
        DB::query("UPDATE shifts SET status = 'published' WHERE id IN (" . DB::placeholders($ids) . ')', $ids);   // new shifts are not notified
        AuditLogger::log('shift.published', 'shift', null, 'Published ' . count($ids) . ' draft shifts', ['ids' => $ids]);
        AttendanceService::fillFromRota($employeeId, $userId);
        return count($ids);
    }

    /** Shifts overlapping a UTC range, with type/location/employee names. */
    public static function inRange(string $fromUtc, string $toUtc, array $filters = []): array
    {
        $where = 's.start_at < ? AND s.end_at > ? AND e.deleted_at IS NULL';
        $params = [$toUtc, $fromUtc];
        if (!empty($filters['employee_id'])) {
            $where .= ' AND s.employee_id = ?';
            $params[] = (int) $filters['employee_id'];
        }
        if (!empty($filters['department_id'])) {
            $where .= ' AND e.department_id = ?';
            $params[] = (int) $filters['department_id'];
        }
        if (!empty($filters['location_id'])) {
            $where .= ' AND s.location_id = ?';
            $params[] = (int) $filters['location_id'];
        }
        if (!empty($filters['published_only'])) {
            $where .= " AND s.status = 'published'";
        } elseif (empty($filters['include_cancelled'])) {
            $where .= " AND s.status <> 'cancelled'";
        }
        return DB::all(
            "SELECT s.*, st.name AS type_name, st.code AS type_code, st.is_night, st.colour, l.name AS location_name,
                    e.employee_code, e.first_name, e.last_name, d.name AS department
               FROM shifts s JOIN employees e ON e.id = s.employee_id
               LEFT JOIN shift_types st ON st.id = s.shift_type_id LEFT JOIN locations l ON l.id = s.location_id
               LEFT JOIN departments d ON d.id = e.department_id
              WHERE $where ORDER BY s.start_at, e.last_name",
            $params
        );
    }
}
