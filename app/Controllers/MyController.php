<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\DB;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Services\AttendanceService;
use App\Services\DocumentService;
use App\Services\HoursCalculator;
use App\Services\LeaveService;
use App\Services\ShiftService;
use App\Services\WeekBoundary;

/**
 * Employee self-service. Every query is scoped by Auth::employeeId() from the session;
 * no route here accepts an employee ID, and there are no edit/upload/approve actions
 * apart from the employee's own check-in/out and leave requests.
 */
final class MyController extends Controller
{
    private function me(): array
    {
        return DB::one('SELECT e.*, d.name AS department, j.title AS job_title, COALESCE(m.first_name, e.reporting_manager_name) AS m_first_name, m.last_name AS m_last_name, wp.name AS pattern_name, loc.name AS location_name, dst.name AS default_shift_name
                          FROM employees e LEFT JOIN departments d ON d.id = e.department_id LEFT JOIN job_titles j ON j.id = e.job_title_id
                          LEFT JOIN employees m ON m.id = e.line_manager_id LEFT JOIN work_patterns wp ON wp.id = e.work_pattern_id LEFT JOIN locations loc ON loc.id = e.location_id LEFT JOIN shift_types dst ON dst.id = e.default_shift_type_id WHERE e.id = ?', [Auth::employeeId()]);
    }

    public function dashboard(Request $request): Response
    {
        $me = $this->me();
        $id = (int) $me['id'];
        AttendanceService::flagIncomplete($id);
        $today = uk_today();
        [$f, $t] = WeekBoundary::utcRangeForDates($today, date('Y-m-d', strtotime("$today +1 day")));
        $todayShift = DB::one("SELECT s.*, l.name AS location_name, st.name AS type_name FROM shifts s LEFT JOIN locations l ON l.id = s.location_id LEFT JOIN shift_types st ON st.id = s.shift_type_id
                                WHERE s.employee_id = ? AND s.status = 'published' AND s.end_at > ? AND s.start_at < ? ORDER BY s.start_at LIMIT 1", [$id, now_utc(), $t]);
        $open = DB::one("SELECT * FROM attendance_records WHERE employee_id = ? AND check_out_at IS NULL AND status <> 'incomplete'", [$id]);
        $week = WeekBoundary::weekStartForDate($today);
        $weekMinutes = (int) DB::value("SELECT COALESCE(SUM(net_minutes),0) FROM shifts WHERE employee_id = ? AND week_start = ? AND status = 'published'", [$id, $week]);
        $limit = HoursCalculator::limitMinutes($me);
        $exception = DB::value('SELECT id FROM hours_exceptions WHERE employee_id = ? AND week_start = ?', [$id, $week]);
        [$nf, $nt] = WeekBoundary::utcRangeForDates($today, date('Y-m-d', strtotime("$today +13 days")));
        $upcoming = ShiftService::inRange($nf, $nt, ['employee_id' => $id, 'published_only' => true]);
        return view('my/dashboard', [
            'title' => 'My Dashboard', 'crumb' => employee_name($me) . ' · ' . $me['employee_code'] . ' · ' . $me['job_title'], 'me' => $me,
            'todayShift' => $todayShift, 'open' => $open, 'weekMinutes' => $weekMinutes, 'limit' => $limit, 'exception' => $exception,
            'leave' => LeaveService::annualSummary($id), 'upcoming' => array_slice($upcoming, 0, 8),
            'payslips' => DB::all("SELECT * FROM payslips WHERE employee_id = ? AND status = 'published' AND is_current = 1 ORDER BY period_year DESC, period_month DESC LIMIT 3", [$id]),
            'requests' => DB::all('SELECT lr.*, lt.name AS type_name FROM leave_requests lr JOIN leave_types lt ON lt.id = lr.leave_type_id WHERE lr.employee_id = ? ORDER BY lr.start_date DESC LIMIT 4', [$id]),
            'compliance' => DocumentService::compliance($me),
        ]);
    }

    public function profile(Request $request): Response
    {
        $me = $this->me();
        return view('my/profile', ['title' => 'My Profile', 'me' => $me, 'experience' => \App\Services\EmployeeService::experience($me),
            'experienceRows' => DB::all('SELECT * FROM employee_work_experience WHERE employee_id = ? ORDER BY is_current DESC, from_date DESC', [$me['id']]),
            'educationRows' => DB::all('SELECT * FROM employee_education WHERE employee_id = ? ORDER BY completion_date DESC', [$me['id']])]);
    }

    public function documents(Request $request): Response
    {
        $me = $this->me();
        $docs = array_values(array_filter(DocumentService::currentDocuments((int) $me['id']), fn ($d) => (bool) $d['visible_to_employee']));
        return view('my/documents', ['title' => 'My Documents', 'docs' => $docs, 'compliance' => DocumentService::compliance($me)]);
    }

    public function attendance(Request $request): Response
    {
        $id = (int) Auth::employeeId();
        AttendanceService::flagIncomplete($id);
        $from = Validator::isDate($request->str('from')) ? $request->str('from') : date('Y-m-d', strtotime(uk_today() . ' -30 days'));
        $to = Validator::isDate($request->str('to')) ? $request->str('to') : uk_today();
        $rows = DB::all('SELECT * FROM attendance_records WHERE employee_id = ? AND work_date BETWEEN ? AND ? ORDER BY check_in_at DESC', [$id, $from, $to]);
        $open = DB::one("SELECT * FROM attendance_records WHERE employee_id = ? AND check_out_at IS NULL AND status <> 'incomplete'", [$id]);
        return view('my/attendance', ['title' => 'My Attendance', 'rows' => $rows, 'open' => $open, 'from' => $from, 'to' => $to,
            'total' => array_sum(array_map(fn ($r) => (int) $r['net_minutes'], $rows))]);
    }

    public function checkIn(Request $request): Response
    {
        return $this->attempt($request, '/my', function () {
            AttendanceService::checkIn((int) Auth::employeeId(), $this->userId());
            flash('success', 'Checked in at ' . uk_now()->format('H:i') . '.');
            return redirect('/my');
        });
    }

    public function checkOut(Request $request): Response
    {
        return $this->attempt($request, '/my', function () {
            $r = AttendanceService::checkOut((int) Auth::employeeId(), $this->userId());
            flash('success', 'Checked out at ' . uk_now()->format('H:i') . '. Net time recorded: ' . hours($r['net_minutes']) . ' hours.');
            return redirect('/my');
        });
    }

    public function shifts(Request $request): Response
    {
        $me = $this->me();
        $date = Validator::isDate($request->str('date')) ? $request->str('date') : uk_today();
        $y = (int) substr($date, 0, 4);
        $m = (int) substr($date, 5, 2);
        $weeks = WeekBoundary::monthGridWeeks($y, $m);
        [$f] = WeekBoundary::utcRange($weeks[0]);
        [, $t] = WeekBoundary::utcRange(end($weeks));
        $shifts = ShiftService::inRange($f, $t, ['employee_id' => (int) $me['id'], 'published_only' => true]);
        $byDay = [];
        $totals = array_fill_keys($weeks, 0);
        foreach ($shifts as $s) {
            $byDay[WeekBoundary::ukDateOfUtc($s['start_at'])][] = $s;
            if (isset($totals[$s['week_start']])) {
                $totals[$s['week_start']] += (int) $s['net_minutes'];
            }
        }
        $lastDay = date('Y-m-d', strtotime(end($weeks) . ' +6 days'));
        $leaveDays = [];
        foreach (DB::all("SELECT lr.start_date, lr.end_date, lt.name FROM leave_requests lr JOIN leave_types lt ON lt.id = lr.leave_type_id
                           WHERE lr.employee_id = ? AND lr.status = 'approved' AND lr.start_date <= ? AND lr.end_date >= ?", [$me['id'], $lastDay, $weeks[0]]) as $l) {
            for ($d = $l['start_date']; $d <= $l['end_date']; $d = date('Y-m-d', strtotime("$d +1 day"))) {
                $leaveDays[$d] = $l['name'];
            }
        }
        $first = sprintf('%04d-%02d-01', $y, $m);
        return view('my/shifts', ['title' => 'My Shifts', 'weeks' => $weeks, 'byDay' => $byDay, 'totals' => $totals, 'month' => $m, 'heading' => month_name($m) . " $y",
            'limit' => HoursCalculator::limitMinutes($me), 'leaveDays' => $leaveDays, 'shifts' => $shifts,
            'holidays' => array_column(DB::all('SELECT holiday_date, name FROM public_holidays WHERE holiday_date BETWEEN ? AND ?', [$weeks[0], $lastDay]), 'name', 'holiday_date'),
            'prev' => date('Y-m-d', strtotime("$first -1 month")), 'next' => date('Y-m-d', strtotime("$first +1 month"))]);
    }

    public function leave(Request $request): Response
    {
        $id = (int) Auth::employeeId();
        return view('my/leave', ['title' => 'My Leave', 'summary' => LeaveService::annualSummary($id),
            'requests' => DB::all('SELECT lr.*, lt.name AS type_name FROM leave_requests lr JOIN leave_types lt ON lt.id = lr.leave_type_id WHERE lr.employee_id = ? ORDER BY lr.start_date DESC', [$id]),
            'types' => DB::all('SELECT * FROM leave_types WHERE is_active = 1 AND employee_can_request = 1 ORDER BY sort_order, name'),
            'holidays' => DB::all('SELECT * FROM public_holidays WHERE holiday_date >= ? ORDER BY holiday_date LIMIT 8', [uk_today()])]);
    }

    public function requestLeave(Request $request): Response
    {
        return $this->attempt($request, '/my/leave', function () use ($request) {
            LeaveService::request((int) Auth::employeeId(), $request->only(['leave_type_id', 'start_date', 'end_date', 'start_half', 'end_half', 'reason']), $this->userId());
            flash('success', 'Leave request sent. You will be notified when it has been reviewed.');
            return redirect('/my/leave');
        });
    }

    public function cancelLeave(Request $request, int $id): Response
    {
        return $this->attempt($request, '/my/leave', function () use ($id) {
            LeaveService::cancel($id, $this->user(), false);
            flash('success', 'Leave request cancelled.');
            return redirect('/my/leave');
        });
    }

    public function payslips(Request $request): Response
    {
        $rows = DB::all("SELECT * FROM payslips WHERE employee_id = ? AND status = 'published' AND is_current = 1 ORDER BY period_year DESC, period_month DESC", [(int) Auth::employeeId()]);
        return view('my/payslips', ['title' => 'My Payslips', 'rows' => $rows]);
    }
}
