<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\DB;
use App\Core\HttpException;
use App\Core\ValidationException;
use App\Core\Validator;
use DateTimeImmutable;

/**
 * Leave requests, approvals and ledger-based balances.
 * Balance = SUM(leave_ledger.days) for the employee, type and leave year.
 */
final class LeaveService
{
    public const ANNUAL_CODE = 'AL';

    /** @return array{0:string,1:string,2:int} [start, end, label year] of the leave year containing $date */
    public static function yearBounds(string $date): array
    {
        $startMonth = (int) Settings::get('leave_year_start_month', 1);
        $d = new DateTimeImmutable($date);
        $year = (int) $d->format('Y');
        if ((int) $d->format('n') < $startMonth) {
            $year--;
        }
        $start = new DateTimeImmutable(sprintf('%04d-%02d-01', $year, $startMonth));
        $end = $start->modify('+1 year')->modify('-1 day');
        return [$start->format('Y-m-d'), $end->format('Y-m-d'), $year];
    }

    public static function boundsForYear(int $year): array
    {
        return self::yearBounds(sprintf('%04d-%02d-01', $year, (int) Settings::get('leave_year_start_month', 1)));
    }

    public static function pattern(array $employee): array
    {
        if (!empty($employee['work_pattern_id'])) {
            $p = DB::one('SELECT * FROM work_patterns WHERE id = ?', [(int) $employee['work_pattern_id']]);
            if ($p) {
                return $p;
            }
        }
        return ['mon' => 1, 'tue' => 1, 'wed' => 1, 'thu' => 1, 'fri' => 1, 'sat' => 0, 'sun' => 0, 'hours_per_day' => 7.5];
    }

    /** @return string[] */
    public static function holidays(string $from, string $to): array
    {
        return DB::column(
            'SELECT holiday_date FROM public_holidays WHERE region = ? AND holiday_date BETWEEN ? AND ?',
            [Settings::get('bank_holiday_region', 'england-and-wales'), $from, $to]
        );
    }

    public static function annualTypeId(): int
    {
        return (int) DB::value('SELECT id FROM leave_types WHERE code = ?', [self::ANNUAL_CODE]);
    }

    public static function employee(int $employeeId): array
    {
        $e = DB::one('SELECT * FROM employees WHERE id = ? AND deleted_at IS NULL', [$employeeId]);
        if (!$e) {
            throw new HttpException(404);
        }
        return $e;
    }

    /**
     * Creates the annual leave entitlement for a leave year if it does not exist yet:
     * an accrual entry, plus one deduction per bank holiday on a working day when the
     * entitlement includes bank holidays. Idempotent.
     */
    public static function ensureEntitlement(int $employeeId, int $year, ?int $userId = null): void
    {
        $typeId = self::annualTypeId();
        if (!$typeId || DB::value('SELECT id FROM leave_entitlements WHERE employee_id = ? AND leave_type_id = ? AND leave_year = ?', [$employeeId, $typeId, $year])) {
            return;
        }
        $e = self::employee($employeeId);
        [$ys, $ye] = self::boundsForYear($year);
        if ($e['start_date'] > $ye || ($e['end_date'] && $e['end_date'] < $ys)) {
            return;
        }
        DB::transaction(function () use ($e, $employeeId, $typeId, $year, $ys, $ye, $userId) {
            $pattern = self::pattern($e);
            $days = LeaveDayCounter::entitlement((float) Settings::get('annual_leave_weeks', 5.6), LeaveDayCounter::workingDaysPerWeek($pattern), $ys, $ye, $e['start_date'], $e['end_date']);
            $proRated = $e['start_date'] > $ys || ($e['end_date'] && $e['end_date'] < $ye);
            DB::insert('leave_entitlements', [
                'employee_id' => $employeeId, 'leave_type_id' => $typeId, 'leave_year' => $year,
                'entitlement_days' => $days, 'is_pro_rated' => $proRated ? 1 : 0, 'created_by' => $userId,
                'notes' => $proRated ? 'Pro-rated for start/end date' : null,
            ]);
            self::writeYearEntries($e, $typeId, $year, $days, $userId);
        });
    }

    /** Recalculates accrual and bank holiday entries for a year (request deductions are untouched). */
    public static function recalculate(int $employeeId, int $year, ?int $userId): void
    {
        $typeId = self::annualTypeId();
        DB::transaction(function () use ($employeeId, $year, $typeId, $userId) {
            DB::query("DELETE FROM leave_ledger WHERE employee_id = ? AND leave_type_id = ? AND leave_year = ? AND entry_type IN ('accrual','bank_holiday')", [$employeeId, $typeId, $year]);
            DB::query('DELETE FROM leave_entitlements WHERE employee_id = ? AND leave_type_id = ? AND leave_year = ?', [$employeeId, $typeId, $year]);
            self::ensureEntitlement($employeeId, $year, $userId);
        });
    }

    private static function writeYearEntries(array $e, int $typeId, int $year, float $days, ?int $userId): void
    {
        DB::insert('leave_ledger', [
            'employee_id' => $e['id'], 'leave_type_id' => $typeId, 'leave_year' => $year, 'entry_type' => 'accrual',
            'days' => $days, 'note' => 'Annual entitlement ' . $year, 'created_by' => $userId, 'created_at' => now_utc(),
        ]);
        if (!Settings::get('leave_includes_bank_holidays', true)) {
            return;
        }
        [$ys, $ye] = self::boundsForYear($year);
        $from = max($ys, $e['start_date']);
        $to = $e['end_date'] ? min($ye, $e['end_date']) : $ye;
        $pattern = self::pattern($e);
        foreach (DB::all('SELECT holiday_date, name FROM public_holidays WHERE region = ? AND holiday_date BETWEEN ? AND ? ORDER BY holiday_date', [Settings::get('bank_holiday_region', 'england-and-wales'), $from, $to]) as $h) {
            if (LeaveDayCounter::isWorkingDay(new DateTimeImmutable($h['holiday_date']), $pattern)) {
                DB::insert('leave_ledger', [
                    'employee_id' => $e['id'], 'leave_type_id' => $typeId, 'leave_year' => $year, 'entry_type' => 'bank_holiday',
                    'days' => -1, 'note' => 'Bank holiday: ' . $h['name'] . ' (' . uk_date($h['holiday_date']) . ')', 'created_by' => $userId, 'created_at' => now_utc(),
                ]);
            }
        }
    }

    public static function balance(int $employeeId, int $typeId, int $year): float
    {
        return (float) DB::value('SELECT COALESCE(SUM(days), 0) FROM leave_ledger WHERE employee_id = ? AND leave_type_id = ? AND leave_year = ?', [$employeeId, $typeId, $year]);
    }

    public static function pendingDays(int $employeeId, int $typeId, string $yearStart, string $yearEnd, ?int $excludeId = null): float
    {
        return (float) DB::value(
            "SELECT COALESCE(SUM(days), 0) FROM leave_requests
              WHERE employee_id = ? AND leave_type_id = ? AND status = 'pending' AND start_date BETWEEN ? AND ? AND id <> ?",
            [$employeeId, $typeId, $yearStart, $yearEnd, $excludeId ?? 0]
        );
    }

    /** Summary used on dashboards: entitlement, taken, booked, remaining for annual leave. */
    public static function annualSummary(int $employeeId, ?string $date = null): array
    {
        [$ys, $ye, $year] = self::yearBounds($date ?? uk_today());
        self::ensureEntitlement($employeeId, $year);
        $typeId = self::annualTypeId();
        $entitlement = (float) DB::value('SELECT entitlement_days FROM leave_entitlements WHERE employee_id = ? AND leave_type_id = ? AND leave_year = ?', [$employeeId, $typeId, $year]);
        $balance = self::balance($employeeId, $typeId, $year);
        $pending = self::pendingDays($employeeId, $typeId, $ys, $ye);
        $bankHolidays = -(float) DB::value("SELECT COALESCE(SUM(days),0) FROM leave_ledger WHERE employee_id = ? AND leave_type_id = ? AND leave_year = ? AND entry_type = 'bank_holiday'", [$employeeId, $typeId, $year]);
        return ['year' => $year, 'year_start' => $ys, 'year_end' => $ye, 'entitlement' => $entitlement, 'remaining' => $balance,
            'pending' => $pending, 'available' => $balance - $pending, 'bank_holidays' => $bankHolidays];
    }

    public static function countDays(array $employee, string $start, string $end, bool $startHalf, bool $endHalf): float
    {
        return LeaveDayCounter::count($start, $end, self::pattern($employee), self::holidays($start, $end), $startHalf, $endHalf);
    }

    /**
     * Creates a pending request after validation.
     * @param bool $byAdmin admins may book types employees cannot request and skip the balance check with a note
     */
    public static function request(int $employeeId, array $input, int $requestedBy, bool $byAdmin = false): int
    {
        $v = Validator::make($input, [
            'leave_type_id' => 'required|integer',
            'start_date'    => 'required|date',
            'end_date'      => 'required|date|after_or_equal:start_date',
            'reason'        => 'nullable|maxlen:500',
            'requested_on'  => 'nullable|date',
        ], ['leave_type_id' => 'Leave type', 'start_date' => 'Start date', 'end_date' => 'End date', 'requested_on' => 'Requested date']);
        if (!$v->fails() && !empty($input['requested_on']) && $input['requested_on'] > uk_today()) {
            throw new ValidationException('The requested date cannot be in the future.', ['requested_on' => 'The requested date cannot be in the future.']);
        }
        if ($v->fails()) {
            throw new ValidationException('Please correct the highlighted fields.', $v->errors());
        }
        $type = DB::one('SELECT * FROM leave_types WHERE id = ? AND is_active = 1', [(int) $input['leave_type_id']]);
        if (!$type || (!$byAdmin && !$type['employee_can_request'])) {
            throw new ValidationException('Please choose a valid leave type.', ['leave_type_id' => 'Please choose a valid leave type.']);
        }
        $e = self::employee($employeeId);
        $start = $input['start_date'];
        $end = $input['end_date'];
        $startHalf = !empty($input['start_half']);
        $endHalf = !empty($input['end_half']);
        [$ys, $ye, $year] = self::yearBounds($start);
        if ($end > $ye) {
            throw new ValidationException('A request cannot cross the end of the leave year (' . uk_date($ye) . '). Please split it into two requests.', ['end_date' => 'Ends after the leave year.']);
        }
        if ($start < $e['start_date'] || ($e['end_date'] && $end > $e['end_date'])) {
            throw new ValidationException('The dates fall outside the employment period.', ['start_date' => 'Outside the employment period.']);
        }
        $overlap = DB::value(
            "SELECT COUNT(*) FROM leave_requests WHERE employee_id = ? AND status IN ('pending','approved') AND start_date <= ? AND end_date >= ?",
            [$employeeId, $end, $start]
        );
        if ($overlap) {
            throw new ValidationException('These dates overlap another pending or approved leave request.', ['start_date' => 'Overlaps another request.']);
        }
        $days = self::countDays($e, $start, $end, $startHalf, $endHalf);
        if ($days <= 0) {
            throw new ValidationException('The selected dates contain no working days (weekends and bank holidays are not counted).', ['start_date' => 'No working days selected.']);
        }
        if ($type['deducts_balance']) {
            self::ensureEntitlement($employeeId, $year);
            $available = self::balance($employeeId, (int) $type['id'], $year) - self::pendingDays($employeeId, (int) $type['id'], $ys, $ye);
            if ($days > $available + 0.0001) {
                throw new ValidationException(sprintf('Not enough leave left: you asked for %s days but %s are available (after pending requests).', days_fmt($days), days_fmt($available)));
            }
        }
        $id = DB::insert('leave_requests', [
            'employee_id' => $employeeId, 'leave_type_id' => (int) $type['id'], 'start_date' => $start, 'end_date' => $end,
            'start_half' => $startHalf ? 1 : 0, 'end_half' => $endHalf ? 1 : 0, 'days' => $days,
            'reason' => ($input['reason'] ?? '') !== '' ? mb_substr((string) $input['reason'], 0, 500) : null, 'status' => 'pending', 'requested_by' => $requestedBy,
            'requested_on' => !empty($input['requested_on']) ? $input['requested_on'] : uk_today(),
            'shift_clashes' => count(self::shiftClashes($employeeId, $start, $end)),
        ]);
        AuditLogger::log('leave.requested', 'leave_request', $id, sprintf('%s leave requested for %s, %s to %s (%s days)', $type['name'], $e['employee_code'], uk_date($start), uk_date($end), days_fmt($days)));
        Notifier::toAdmins('leave', 'Leave request from ' . employee_name($e), sprintf('%s · %s–%s · %s days', $type['name'], uk_date($start), uk_date($end), days_fmt($days)), '/leave/' . $id, $requestedBy);
        return $id;
    }

    /** Published or draft shifts that start on a date within the range. */
    public static function shiftClashes(int $employeeId, string $start, string $end): array
    {
        [$from, $to] = WeekBoundary::utcRangeForDates($start, $end);
        return DB::all("SELECT * FROM shifts WHERE employee_id = ? AND status <> 'cancelled' AND start_at >= ? AND start_at < ? ORDER BY start_at", [$employeeId, $from, $to]);
    }

    private static function loadForDecision(int $id, array $approver): array
    {
        $r = DB::one('SELECT lr.*, lt.name AS type_name, lt.deducts_balance, e.employee_code, e.first_name, e.last_name
                        FROM leave_requests lr JOIN leave_types lt ON lt.id = lr.leave_type_id JOIN employees e ON e.id = lr.employee_id
                       WHERE lr.id = ? FOR UPDATE', [$id]);
        if (!$r) {
            throw new HttpException(404);
        }
        if ($approver['employee_id'] !== null && (int) $approver['employee_id'] === (int) $r['employee_id']) {
            throw new HttpException(403, 'You cannot approve or reject your own leave request.');
        }
        if ($r['status'] !== 'pending') {
            throw new ValidationException('This request has already been ' . $r['status'] . '.');
        }
        return $r;
    }

    /** @return int number of rostered shifts that clash with the approved leave */
    /** @param ?string $approvedOn Y-m-d approval date (default today); not before the requested date, not in the future */
    public static function approve(int $id, array $approver, ?string $note, ?string $approvedOn = null): int
    {
        return DB::transaction(function () use ($id, $approver, $note, $approvedOn) {
            $r = self::loadForDecision($id, $approver);
            $approvedOn = $approvedOn ?: uk_today();
            if (!\App\Core\Validator::isDate($approvedOn) || $approvedOn > uk_today()) {
                throw new ValidationException('Enter a valid approved date that is not in the future.', ['approved_on' => 'Approved date cannot be in the future.']);
            }
            if ($r['requested_on'] && $approvedOn < $r['requested_on']) {
                throw new ValidationException('The approved date cannot be before the requested date (' . uk_date($r['requested_on']) . ').', ['approved_on' => 'Approved date is before the requested date.']);
            }
            $clashes = count(self::shiftClashes((int) $r['employee_id'], $r['start_date'], $r['end_date']));
            DB::update('leave_requests', ['status' => 'approved', 'approved_on' => $approvedOn, 'decided_by' => (int) $approver['id'], 'decided_at' => now_utc(),
                'decision_note' => $note ?: null, 'shift_clashes' => $clashes], 'id = ?', [$id]);
            if ($r['deducts_balance']) {
                [, , $year] = self::yearBounds($r['start_date']);
                self::ensureEntitlement((int) $r['employee_id'], $year);
                DB::insert('leave_ledger', [
                    'employee_id' => (int) $r['employee_id'], 'leave_type_id' => (int) $r['leave_type_id'], 'leave_year' => $year,
                    'entry_type' => 'deduction', 'days' => -(float) $r['days'], 'leave_request_id' => $id,
                    'note' => sprintf('%s %s–%s', $r['type_name'], uk_date($r['start_date']), uk_date($r['end_date'])),
                    'created_by' => (int) $approver['id'], 'created_at' => now_utc(),
                ]);
            }
            AuditLogger::log('leave.approved', 'leave_request', $id, sprintf('Approved %s leave for %s (%s days)', $r['type_name'], $r['employee_code'], days_fmt($r['days'])), ['clashing_shifts' => $clashes]);
            Notifier::toEmployee((int) $r['employee_id'], 'leave', 'Leave approved', sprintf('%s %s–%s', $r['type_name'], uk_date($r['start_date']), uk_date($r['end_date'])), '/my/leave');
            return $clashes;
        });
    }

    public static function reject(int $id, array $approver, ?string $note): void
    {
        DB::transaction(function () use ($id, $approver, $note) {
            $r = self::loadForDecision($id, $approver);
            if (!$note) {
                throw new ValidationException('Please give a reason for rejecting the request.');
            }
            DB::update('leave_requests', ['status' => 'rejected', 'decided_by' => (int) $approver['id'], 'decided_at' => now_utc(), 'decision_note' => $note], 'id = ?', [$id]);
            AuditLogger::log('leave.rejected', 'leave_request', $id, sprintf('Rejected %s leave for %s', $r['type_name'], $r['employee_code']));
            Notifier::toEmployee((int) $r['employee_id'], 'leave', 'Leave request not approved', $note, '/my/leave');
        });
    }

    /**
     * Employees may cancel their own pending requests. Admins may also cancel approved ones,
     * which writes a reversal entry so the balance is restored and traceable.
     */
    public static function cancel(int $id, array $user, bool $asAdmin): void
    {
        DB::transaction(function () use ($id, $user, $asAdmin) {
            $r = DB::one('SELECT lr.*, lt.name AS type_name, lt.deducts_balance FROM leave_requests lr JOIN leave_types lt ON lt.id = lr.leave_type_id WHERE lr.id = ? FOR UPDATE', [$id]);
            if (!$r || (!$asAdmin && (int) $r['employee_id'] !== (int) $user['employee_id'])) {
                throw new HttpException(404);
            }
            $allowed = $asAdmin ? ['pending', 'approved'] : ['pending'];
            if (!in_array($r['status'], $allowed, true)) {
                throw new ValidationException('Only ' . implode(' or ', $allowed) . ' requests can be cancelled.');
            }
            DB::update('leave_requests', ['status' => 'cancelled', 'decided_by' => (int) $user['id'], 'decided_at' => now_utc()], 'id = ?', [$id]);
            if ($r['status'] === 'approved' && $r['deducts_balance']) {
                [, , $year] = self::yearBounds($r['start_date']);
                DB::insert('leave_ledger', [
                    'employee_id' => (int) $r['employee_id'], 'leave_type_id' => (int) $r['leave_type_id'], 'leave_year' => $year,
                    'entry_type' => 'reversal', 'days' => (float) $r['days'], 'leave_request_id' => $id,
                    'note' => 'Cancelled approved leave ' . uk_date($r['start_date']) . '–' . uk_date($r['end_date']), 'created_by' => (int) $user['id'], 'created_at' => now_utc(),
                ]);
                Notifier::toEmployee((int) $r['employee_id'], 'leave', 'Approved leave cancelled', $r['type_name'] . ' ' . uk_date($r['start_date']) . '–' . uk_date($r['end_date']), '/my/leave');
            }
            AuditLogger::log('leave.cancelled', 'leave_request', $id, sprintf('Cancelled %s leave request (%s)', $r['type_name'], $r['status']));
        });
    }

    /** Approved leave covering a UK date, or null. */
    public static function approvedLeaveOn(int $employeeId, string $date): ?array
    {
        return DB::one("SELECT lr.*, lt.name AS type_name FROM leave_requests lr JOIN leave_types lt ON lt.id = lr.leave_type_id
                         WHERE lr.employee_id = ? AND lr.status = 'approved' AND ? BETWEEN lr.start_date AND lr.end_date LIMIT 1", [$employeeId, $date]);
    }

    public static function manualAdjustment(int $employeeId, int $typeId, int $year, float $days, string $note, int $userId): void
    {
        if ($note === '' || $days == 0.0) {
            throw new ValidationException('Enter a non-zero number of days and a note.');
        }
        DB::insert('leave_ledger', ['employee_id' => $employeeId, 'leave_type_id' => $typeId, 'leave_year' => $year, 'entry_type' => 'adjustment',
            'days' => $days, 'note' => mb_substr($note, 0, 255), 'created_by' => $userId, 'created_at' => now_utc()]);
        AuditLogger::log('leave.adjusted', 'employee', $employeeId, sprintf('Leave balance adjusted by %s days for %d: %s', days_fmt($days), $year, $note));
    }
}
