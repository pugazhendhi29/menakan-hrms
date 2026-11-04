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

    /**
     * Creates shifts from a pattern: for each chosen employee, every chosen weekday from start to end date gets a
     * shift with the same times. Bank holidays, days on approved leave, days outside the employee's employment and
     * days that already have a shift are skipped; each shift goes through save() (limits, overlaps, attendance).
     * @return array{created:int, skipped:array<string,int>, failed:string[], warnings:string[]}
     */
    public static function createPattern(array $input, int $userId): array
    {
        $v = Validator::make($input, [
            'from' => 'required|date', 'to' => 'required|date', 'start_time' => 'required|time', 'end_time' => 'required|time',
            'break_minutes' => 'required|integer|min:0|max:600', 'status' => 'required|in:draft,published',
        ], ['from' => 'Start date', 'to' => 'End date', 'start_time' => 'Start time', 'end_time' => 'End time', 'break_minutes' => 'Break minutes']);
        $errors = $v->fails() ? $v->errors() : [];
        $employees = array_values(array_unique(array_filter(array_map('intval', (array) ($input['employee_ids'] ?? [])))));
        $days = array_values(array_intersect(array_map('intval', (array) ($input['weekdays'] ?? [])), range(1, 7)));
        if (!$employees) {
            $errors['employee_ids'] = 'Choose at least one employee.';
        }
        if (!$days) {
            $errors['weekdays'] = 'Choose at least one day of the week.';
        }
        if (!$errors && $input['to'] < $input['from']) {
            $errors['to'] = 'The end date must be on or after the start date.';
        }
        if (!$errors && (strtotime($input['to']) - strtotime($input['from'])) / 86400 > 366) {
            $errors['to'] = 'Create up to one year of shifts at a time.';
        }
        if ($errors) {
            throw new ValidationException('Please correct the highlighted fields.', $errors);
        }
        $holidays = array_column(DB::all('SELECT holiday_date, name FROM public_holidays WHERE region = ? AND holiday_date BETWEEN ? AND ?',
            [(string) Settings::get('bank_holiday_region', 'england-and-wales'), $input['from'], $input['to']]), 'name', 'holiday_date');

        $created = 0;
        $skipped = ['bank holiday' => 0, 'on leave' => 0, 'already has a shift' => 0, 'outside employment' => 0];
        $failed = $warnings = [];
        foreach ($employees as $eid) {
            $e = DB::one("SELECT * FROM employees WHERE id = ? AND deleted_at IS NULL AND status <> 'left'", [$eid]);
            if (!$e) {
                $failed[] = "Employee #$eid is not an active employee.";
                continue;
            }
            for ($d = $input['from']; $d <= $input['to']; $d = date('Y-m-d', strtotime("$d +1 day"))) {
                if (!in_array((int) date('N', strtotime($d)), $days, true)) {
                    continue;
                }
                if (isset($holidays[$d])) {
                    $skipped['bank holiday']++;
                    continue;
                }
                if ($d < $e['start_date'] || ($e['end_date'] && $d > $e['end_date'])) {
                    $skipped['outside employment']++;
                    continue;
                }
                if (DB::value("SELECT id FROM leave_requests WHERE employee_id = ? AND status = 'approved' AND ? BETWEEN start_date AND end_date LIMIT 1", [$eid, $d])) {
                    $skipped['on leave']++;
                    continue;
                }
                [$startUtc, $endUtc] = HoursCalculator::utcSpan($d, $input['start_time'], $input['end_time']);
                if (DB::value("SELECT id FROM shifts WHERE employee_id = ? AND status <> 'cancelled' AND start_at < ? AND end_at > ? LIMIT 1", [$eid, $endUtc, $startUtc])) {
                    $skipped['already has a shift']++;
                    continue;
                }
                try {
                    $r = self::save(null, ['employee_id' => $eid, 'date' => $d, 'start_time' => $input['start_time'], 'end_time' => $input['end_time'],
                        'break_minutes' => $input['break_minutes'], 'shift_type_id' => $input['shift_type_id'] ?? '', 'location_id' => $input['location_id'] ?? '',
                        'status' => $input['status'], 'notes' => $input['notes'] ?? '', 'override_reason' => $input['override_reason'] ?? ''], $userId);
                    $created++;
                    $warnings = array_merge($warnings, $r['warnings']);
                } catch (ValidationException $ex) {
                    $failed[] = $e['employee_code'] . ' ' . uk_date($d) . ': ' . $ex->getMessage();
                }
            }
        }
        AuditLogger::log('shift.pattern', 'shift', null, sprintf('Created %d %s shift(s) from a pattern, %s to %s', $created, $input['status'], uk_date($input['from']), uk_date($input['to'])),
            ['employees' => $employees, 'weekdays' => $days, 'start' => $input['start_time'], 'end' => $input['end_time'], 'skipped' => $skipped, 'failed' => count($failed)], $userId);
        return ['created' => $created, 'skipped' => array_filter($skipped), 'failed' => $failed, 'warnings' => array_values(array_unique($warnings))];
    }

    /**
     * Deletes the chosen employees' shifts (not cancelled ones) that start between two UK dates, optionally only
     * drafts or only published ones. Their rota attendance goes too (approved records are kept) and the days
     * become days off. No notifications; every deleted shift is in the audit log.
     * @return int shifts deleted
     */
    public static function deleteRange(array $input, int $userId): int
    {
        $v = Validator::make($input, ['from' => 'required|date', 'to' => 'required|date', 'status' => 'required|in:all,draft,published'],
            ['from' => 'Start date', 'to' => 'End date']);
        $errors = $v->fails() ? $v->errors() : [];
        $employees = array_values(array_unique(array_filter(array_map('intval', (array) ($input['employee_ids'] ?? [])))));
        if (!$employees) {
            $errors['employee_ids'] = 'Choose at least one employee.';
        }
        if (!$errors && $input['to'] < $input['from']) {
            $errors['to'] = 'The end date must be on or after the start date.';
        }
        if ($errors) {
            throw new ValidationException('Please correct the highlighted fields.', $errors);
        }
        $statuses = $input['status'] === 'all' ? ['draft', 'published'] : [$input['status']];
        $shifts = DB::all('SELECT s.*, e.employee_code FROM shifts s JOIN employees e ON e.id = s.employee_id WHERE s.employee_id IN (' . DB::placeholders($employees) . ')
                            AND s.status IN (' . DB::placeholders($statuses) . ') AND s.start_at >= ? AND s.start_at < ? ORDER BY s.start_at',
            [...$employees, ...$statuses, to_utc($input['from'] . ' 00:00'), to_utc(date('Y-m-d', strtotime($input['to'] . ' +1 day')) . ' 00:00')]);
        if (!$shifts) {
            return 0;
        }
        $ids = array_map(fn ($s) => (int) $s['id'], $shifts);
        DB::transaction(function () use ($ids, $shifts, $input, $employees, $userId) {
            foreach (array_chunk($ids, 500) as $chunk) {
                DB::query("DELETE FROM attendance_records WHERE source = 'rota' AND is_approved = 0 AND shift_id IN (" . DB::placeholders($chunk) . ')', $chunk);
                DB::query('DELETE FROM shifts WHERE id IN (' . DB::placeholders($chunk) . ')', $chunk);
            }
            AuditLogger::log('shift.deleted', 'shift', null, sprintf('Deleted %d %s shift(s), %s to %s', count($ids), $input['status'] === 'all' ? 'draft and published' : $input['status'],
                uk_date($input['from']), uk_date($input['to'])), ['employees' => $employees,
                'shifts' => array_map(fn ($s) => ['id' => (int) $s['id'], 'employee' => $s['employee_code'], 'start_at' => $s['start_at'], 'end_at' => $s['end_at'], 'status' => $s['status']], $shifts)], $userId);
        });
        foreach (array_unique(array_map(fn ($s) => (int) $s['employee_id'], $shifts)) as $eid) {
            AttendanceService::fillFromRota($eid, $userId);   // the freed days become days off
        }
        return count($ids);
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
