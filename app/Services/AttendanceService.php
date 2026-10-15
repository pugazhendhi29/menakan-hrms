<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\DB;
use App\Core\ValidationException;

final class AttendanceService
{
    /** Marks open sessions older than the configured limit as Incomplete. */
    public static function flagIncomplete(?int $employeeId = null): int
    {
        $hours = (int) Settings::get('incomplete_after_hours', 16);
        $cutoff = gmdate('Y-m-d H:i:s', time() - $hours * 3600);
        $sql = "UPDATE attendance_records SET status = 'incomplete' WHERE check_out_at IS NULL AND status <> 'incomplete' AND check_in_at < ?";
        $params = [$cutoff];
        if ($employeeId) {
            $sql .= ' AND employee_id = ?';
            $params[] = $employeeId;
        }
        return DB::query($sql, $params)->rowCount();
    }

    /** The published shift an attendance at $utc most likely belongs to (starts within 4h either side, or in progress). */
    public static function matchShift(int $employeeId, string $utc): ?array
    {
        return DB::one(
            "SELECT * FROM shifts WHERE employee_id = ? AND status = 'published'
                AND start_at <= DATE_ADD(?, INTERVAL 4 HOUR) AND end_at >= DATE_SUB(?, INTERVAL 1 HOUR)
              ORDER BY ABS(TIMESTAMPDIFF(MINUTE, start_at, ?)) LIMIT 1",
            [$employeeId, $utc, $utc, $utc]
        );
    }

    public static function statusFor(?array $shift, string $checkInUtc): string
    {
        if (!$shift) {
            return 'present';
        }
        $grace = (int) Settings::get('late_grace_minutes', 5);
        return strtotime($checkInUtc . ' UTC') > strtotime($shift['start_at'] . ' UTC') + $grace * 60 ? 'late' : 'present';
    }

    /** Unpaid break applied at check-out: the shift's break, else the default for sessions over 6 hours. */
    public static function breakFor(?array $shift, int $grossMinutes): int
    {
        $break = $shift ? (int) $shift['break_minutes'] : ($grossMinutes > 360 ? (int) Settings::get('default_break_minutes', 30) : 0);
        return $break < $grossMinutes ? $break : 0;
    }

    /** Server-timed check-in. The employee ID comes from the session, never from the form. */
    public static function checkIn(int $employeeId, int $userId): int
    {
        self::flagIncomplete($employeeId);
        if (DB::value("SELECT id FROM attendance_records WHERE employee_id = ? AND check_out_at IS NULL AND status <> 'incomplete'", [$employeeId])) {
            throw new ValidationException('You are already checked in. Please check out first.');
        }
        $now = now_utc();
        $shift = self::matchShift($employeeId, $now);
        try {
            $id = DB::insert('attendance_records', [
                'employee_id' => $employeeId, 'work_date' => uk_today(), 'check_in_at' => $now, 'status' => self::statusFor($shift, $now),
                'source' => 'web', 'shift_id' => $shift['id'] ?? null, 'created_by' => $userId,
            ]);
        } catch (\PDOException $e) {
            if ($e->getCode() === '23000') {
                throw new ValidationException('You are already checked in.');
            }
            throw $e;
        }
        AuditLogger::log('attendance.check_in', 'attendance', $id, 'Checked in');
        return $id;
    }

    public static function checkOut(int $employeeId, int $userId): array
    {
        $rec = DB::one("SELECT * FROM attendance_records WHERE employee_id = ? AND check_out_at IS NULL AND status <> 'incomplete' ORDER BY check_in_at DESC LIMIT 1", [$employeeId]);
        if (!$rec) {
            throw new ValidationException('You are not checked in.');
        }
        $now = now_utc();
        $gross = max(1, intdiv(strtotime($now . ' UTC') - strtotime($rec['check_in_at'] . ' UTC'), 60));
        $shift = $rec['shift_id'] ? DB::one('SELECT * FROM shifts WHERE id = ?', [(int) $rec['shift_id']]) : null;
        $break = self::breakFor($shift, $gross);
        DB::update('attendance_records', ['check_out_at' => $now, 'break_minutes' => $break, 'net_minutes' => $gross - $break], 'id = ?', [(int) $rec['id']]);
        AuditLogger::log('attendance.check_out', 'attendance', (int) $rec['id'], 'Checked out');
        return ['id' => (int) $rec['id'], 'net_minutes' => $gross - $break];
    }

    /**
     * Admin create/edit. Every changed field is written to attendance_corrections with the reason.
     * $input: employee_id (create only), work_date, check_in (HH:MM), check_out (HH:MM, may be next day), break_minutes, notes, reason
     */
    public static function save(?int $id, array $input, int $userId): int
    {
        $errors = [];
        $reason = trim((string) ($input['reason'] ?? ''));
        if ($reason === '') {
            $errors['reason'] = 'A reason is required for manual changes.';
        }
        $date = (string) ($input['work_date'] ?? '');
        if (!\App\Core\Validator::isDate($date)) {
            $errors['work_date'] = 'Enter a valid date.';
        }
        $in = (string) ($input['check_in'] ?? '');
        $out = (string) ($input['check_out'] ?? '');
        if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $in)) {
            $errors['check_in'] = 'Enter a check-in time (HH:MM).';
        }
        if ($out !== '' && !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $out)) {
            $errors['check_out'] = 'Enter a check-out time (HH:MM) or leave it blank.';
        }
        $break = (string) ($input['break_minutes'] ?? '0');
        if (!preg_match('/^\d{1,3}$/', $break === '' ? '0' : $break)) {
            $errors['break_minutes'] = 'Break must be whole minutes.';
        }
        $employeeId = $id ? (int) DB::value('SELECT employee_id FROM attendance_records WHERE id = ?', [$id]) : (int) ($input['employee_id'] ?? 0);
        if (!$employeeId || !DB::value('SELECT id FROM employees WHERE id = ? AND deleted_at IS NULL', [$employeeId])) {
            $errors['employee_id'] = 'Choose an employee.';
        }
        if ($errors) {
            throw new ValidationException('Please correct the highlighted fields.', $errors);
        }
        if ($date > uk_today()) {
            throw new ValidationException('Attendance cannot be recorded for a future date.', ['work_date' => 'Date is in the future.']);
        }
        $break = (int) $break;
        if ($out !== '') {
            [$inUtc, $outUtc] = HoursCalculator::utcSpan($date, $in, $out);
            $net = HoursCalculator::netMinutes($inUtc, $outUtc, $break);
        } else {
            $inUtc = to_utc("$date $in");
            $outUtc = null;
            $net = null;
        }
        $overlap = DB::value(
            'SELECT id FROM attendance_records WHERE employee_id = ? AND id <> ? AND check_in_at < ? AND COALESCE(check_out_at, DATE_ADD(check_in_at, INTERVAL 16 HOUR)) > ?',
            [$employeeId, $id ?? 0, $outUtc ?? gmdate('Y-m-d H:i:s', strtotime($inUtc . ' UTC') + 60), $inUtc]
        );
        if ($overlap) {
            throw new ValidationException('These times overlap another attendance record for this employee.');
        }
        $shift = self::matchShift($employeeId, $inUtc);
        $status = $outUtc === null && strtotime($inUtc . ' UTC') < time() - (int) Settings::get('incomplete_after_hours', 16) * 3600 ? 'incomplete' : self::statusFor($shift, $inUtc);
        $data = ['work_date' => $date, 'check_in_at' => $inUtc, 'check_out_at' => $outUtc, 'break_minutes' => $break, 'net_minutes' => $net,
            'status' => $status, 'shift_id' => $shift['id'] ?? null, 'notes' => ($input['notes'] ?? '') !== '' ? mb_substr((string) $input['notes'], 0, 255) : null];

        return DB::transaction(function () use ($id, $data, $employeeId, $userId, $reason) {
            try {
                if ($id === null) {
                    $newId = DB::insert('attendance_records', $data + ['employee_id' => $employeeId, 'source' => 'manual', 'created_by' => $userId]);
                    DB::insert('attendance_corrections', ['attendance_id' => $newId, 'field' => 'record', 'old_value' => null, 'new_value' => 'created manually',
                        'reason' => mb_substr($reason, 0, 255), 'changed_by' => $userId, 'changed_at' => now_utc()]);
                    AuditLogger::log('attendance.created', 'attendance', $newId, 'Created attendance record manually', ['employee_id' => $employeeId, 'reason' => $reason]);
                    return $newId;
                }
                $before = DB::one('SELECT * FROM attendance_records WHERE id = ? FOR UPDATE', [$id]);
                $changes = [];
                foreach (['work_date', 'check_in_at', 'check_out_at', 'break_minutes', 'notes'] as $f) {
                    if ((string) $before[$f] !== (string) $data[$f]) {
                        $changes[$f] = [$before[$f], $data[$f]];
                        DB::insert('attendance_corrections', ['attendance_id' => $id, 'field' => $f, 'old_value' => $before[$f], 'new_value' => $data[$f],
                            'reason' => mb_substr($reason, 0, 255), 'changed_by' => $userId, 'changed_at' => now_utc()]);
                    }
                }
                if (!$changes) {
                    throw new ValidationException('Nothing was changed.');
                }
                DB::update('attendance_records', $data, 'id = ?', [$id]);
                AuditLogger::log('attendance.corrected', 'attendance', $id, 'Corrected attendance record', $changes + ['reason' => $reason]);
                return $id;
            } catch (\PDOException $e) {
                if ($e->getCode() === '23000') {
                    throw new ValidationException('A record with the same employee, date and check-in time already exists, or the employee already has an open session.');
                }
                throw $e;
            }
        });
    }

    public static function approve(array $ids, int $userId): int
    {
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if (!$ids) {
            return 0;
        }
        $n = DB::query('UPDATE attendance_records SET is_approved = 1, approved_by = ?, approved_at = ? WHERE is_approved = 0 AND check_out_at IS NOT NULL AND id IN (' . DB::placeholders($ids) . ')',
            array_merge([$userId, now_utc()], $ids))->rowCount();
        AuditLogger::log('attendance.approved', 'attendance', null, "Approved $n attendance records", ['ids' => $ids]);
        return $n;
    }

    /**
     * Absences: published shifts that have started, with no attendance on that UK date and no approved leave.
     * Returns one row per shift.
     */
    public static function absences(string $fromDate, string $toDate, ?int $employeeId = null, ?int $departmentId = null): array
    {
        [$from, $to] = WeekBoundary::utcRangeForDates($fromDate, $toDate);
        $grace = (int) Settings::get('late_grace_minutes', 5);
        $params = [$from, $to, gmdate('Y-m-d H:i:s', time() - $grace * 60)];
        $extra = '';
        if ($employeeId) {
            $extra .= ' AND s.employee_id = ?';
            $params[] = $employeeId;
        }
        if ($departmentId) {
            $extra .= ' AND e.department_id = ?';
            $params[] = $departmentId;
        }
        $rows = DB::all(
            "SELECT s.*, e.employee_code, e.first_name, e.last_name, d.name AS department
               FROM shifts s JOIN employees e ON e.id = s.employee_id LEFT JOIN departments d ON d.id = e.department_id
              WHERE s.status = 'published' AND s.start_at >= ? AND s.start_at < ? AND s.start_at < ? AND e.deleted_at IS NULL $extra
              ORDER BY s.start_at",
            $params
        );
        $out = [];
        foreach ($rows as $s) {
            $ukDate = WeekBoundary::ukDateOfUtc($s['start_at']);
            $hasAttendance = DB::value(
                'SELECT id FROM attendance_records WHERE employee_id = ? AND (shift_id = ? OR work_date = ?) LIMIT 1',
                [(int) $s['employee_id'], (int) $s['id'], $ukDate]
            );
            if ($hasAttendance) {
                continue;
            }
            $s['uk_date'] = $ukDate;
            $s['leave'] = LeaveService::approvedLeaveOn((int) $s['employee_id'], $ukDate);
            $s['day_status'] = $s['leave'] ? 'on_leave' : 'absent';
            $out[] = $s;
        }
        return $out;
    }
}
