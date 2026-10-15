<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\DB;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Services\LeaveService;

final class LeaveController extends Controller
{
    public function index(Request $request): Response
    {
        $where = ['1=1'];
        $params = [];
        $status = $request->str('status', 'pending');
        if (in_array($status, ['pending', 'approved', 'rejected', 'cancelled'], true)) {
            $where[] = 'lr.status = ?';
            $params[] = $status;
        }
        if ($t = $request->int('leave_type_id')) {
            $where[] = 'lr.leave_type_id = ?';
            $params[] = $t;
        }
        if ($d = $request->int('department_id')) {
            $where[] = 'e.department_id = ?';
            $params[] = $d;
        }
        if ($emp = $request->int('employee_id')) {
            $where[] = 'lr.employee_id = ?';
            $params[] = $emp;
        }
        $base = 'FROM leave_requests lr JOIN leave_types lt ON lt.id = lr.leave_type_id JOIN employees e ON e.id = lr.employee_id
                 LEFT JOIN departments dep ON dep.id = e.department_id WHERE ' . implode(' AND ', $where);
        $p = paginate((int) DB::value("SELECT COUNT(*) $base", $params), $request->int('page', 1), 50);
        $rows = DB::all("SELECT lr.*, lt.name AS type_name, e.employee_code, e.first_name, e.last_name, dep.name AS department $base
                         ORDER BY " . ($status === 'pending' ? 'lr.start_date ASC' : 'lr.start_date DESC') . " LIMIT {$p['per_page']} OFFSET {$p['offset']}", $params);
        return view('leave/index', ['title' => 'Leave Management', 'crumb' => 'Time', 'rows' => $rows, 'p' => $p, 'request' => $request, 'status' => $status,
            'types' => DB::all('SELECT id, name FROM leave_types ORDER BY is_active DESC, sort_order, name'), 'departments' => $this->departments(), 'employees' => $this->employeeOptions()]);
    }

    public function show(Request $request, int $id): Response
    {
        $r = $this->findOr404('SELECT lr.*, lt.name AS type_name, lt.deducts_balance, e.employee_code, e.first_name, e.last_name, e.id AS emp_id,
                                      u.username AS decided_by_name, ru.username AS requested_by_name
                                 FROM leave_requests lr JOIN leave_types lt ON lt.id = lr.leave_type_id JOIN employees e ON e.id = lr.employee_id
                                 LEFT JOIN users u ON u.id = lr.decided_by LEFT JOIN users ru ON ru.id = lr.requested_by WHERE lr.id = ?', [$id]);
        $clashes = LeaveService::shiftClashes((int) $r['employee_id'], $r['start_date'], $r['end_date']);
        $summary = $r['deducts_balance'] ? LeaveService::annualSummary((int) $r['employee_id'], $r['start_date']) : null;
        $overlapping = DB::all("SELECT lr.*, e.first_name, e.last_name, lt.name AS type_name FROM leave_requests lr JOIN employees e ON e.id = lr.employee_id
                                  JOIN leave_types lt ON lt.id = lr.leave_type_id
                                 WHERE lr.status = 'approved' AND lr.id <> ? AND lr.start_date <= ? AND lr.end_date >= ?
                                   AND e.department_id = (SELECT department_id FROM employees WHERE id = ?)", [$id, $r['end_date'], $r['start_date'], $r['employee_id']]);
        $own = (int) ($this->user()['employee_id'] ?? 0) === (int) $r['employee_id'];
        return view('leave/show', ['title' => 'Leave request', 'crumb' => 'Time · Leave', 'r' => $r, 'clashes' => $clashes, 'summary' => $summary,
            'overlapping' => $overlapping, 'own' => $own]);
    }

    public function create(Request $request): Response
    {
        return view('leave/form', ['title' => 'Book leave', 'crumb' => 'Time · Leave', 'employees' => $this->employeeOptions(),
            'types' => DB::all('SELECT * FROM leave_types WHERE is_active = 1 ORDER BY sort_order, name'), 'employeeId' => $request->int('employee_id'), 'action' => url('/leave'), 'admin' => true]);
    }

    /** Admin books leave on an employee's behalf (created pending, then approved here or later). */
    public function store(Request $request): Response
    {
        $employeeId = (int) $request->int('employee_id', 0);
        return $this->attempt($request, '/leave/create?employee_id=' . $employeeId, function () use ($request, $employeeId) {
            if (!$employeeId) {
                throw new \App\Core\ValidationException('Choose an employee.', ['employee_id' => 'Choose an employee.']);
            }
            $approveNow = $request->bool('approve_now') || $request->str('approved_on') !== '';
            if ($approveNow && (int) ($this->user()['employee_id'] ?? 0) === $employeeId) {
                throw new \App\Core\ValidationException('You cannot approve your own leave. Untick "Approve now" so another administrator can decide.');
            }
            $approvedOn = $request->str('approved_on');
            if ($approvedOn !== '' && (!\App\Core\Validator::isDate($approvedOn) || $approvedOn > uk_today()
                    || ($request->str('requested_on') !== '' && $approvedOn < $request->str('requested_on')))) {
                throw new \App\Core\ValidationException('The approved date must be a real date, not in the future and not before the requested date.', ['approved_on' => 'Check the approved date.']);
            }
            $id = LeaveService::request($employeeId, $request->only(['leave_type_id', 'start_date', 'end_date', 'start_half', 'end_half', 'reason', 'requested_on']), $this->userId(), true);
            if ($approveNow) {
                $clashes = LeaveService::approve($id, $this->user(), 'Booked and approved by HR', $approvedOn ?: null);
                flash('success', 'Leave booked and approved.' . ($clashes ? " $clashes rostered shift(s) clash with it." : ''));
            } else {
                flash('success', 'Leave request created and awaiting approval.');
            }
            return redirect('/leave/' . $id);
        });
    }

    public function approve(Request $request, int $id): Response
    {
        return $this->attempt($request, '/leave/' . $id, function () use ($request, $id) {
            $clashes = LeaveService::approve($id, $this->user(), $request->str('note') ?: null, $request->str('approved_on') ?: null);
            flash('success', 'Leave approved. The employee has been notified.');
            if ($clashes) {
                flash('warning', "$clashes rostered shift(s) fall within this leave. Review the roster and cancel or reassign them.");
            }
            return redirect('/leave/' . $id);
        });
    }

    public function reject(Request $request, int $id): Response
    {
        return $this->attempt($request, '/leave/' . $id, function () use ($request, $id) {
            LeaveService::reject($id, $this->user(), $request->str('note'));
            flash('success', 'Leave rejected. The employee has been notified.');
            return redirect('/leave/' . $id);
        });
    }

    public function cancel(Request $request, int $id): Response
    {
        return $this->attempt($request, '/leave/' . $id, function () use ($id) {
            LeaveService::cancel($id, $this->user(), true);
            flash('success', 'Leave cancelled. Any deducted days have been returned to the balance.');
            return redirect('/leave/' . $id);
        });
    }

    public function balances(Request $request): Response
    {
        $date = Validator::isDate($request->str('date')) ? $request->str('date') : uk_today();
        $dept = $request->int('department_id');
        $rows = [];
        foreach (DB::all("SELECT e.*, d.name AS department FROM employees e LEFT JOIN departments d ON d.id = e.department_id
                          WHERE e.deleted_at IS NULL AND e.status <> 'left'" . ($dept ? ' AND e.department_id = ' . (int) $dept : '') . ' ORDER BY d.name, e.last_name') as $e) {
            $rows[] = ['e' => $e, 's' => LeaveService::annualSummary((int) $e['id'], $date)];
        }
        return view('leave/balances', ['title' => 'Leave balances', 'crumb' => 'Time · Leave', 'rows' => $rows, 'departments' => $this->departments(), 'request' => $request,
            'year' => LeaveService::yearBounds($date)]);
    }

    /** Who is off: approved leave per day for a month. */
    public function calendar(Request $request): Response
    {
        $date = Validator::isDate($request->str('date')) ? $request->str('date') : uk_today();
        $first = substr($date, 0, 8) . '01';
        $last = date('Y-m-t', strtotime($first));
        $rows = DB::all("SELECT lr.*, lt.name AS type_name, lt.colour, e.first_name, e.last_name, e.employee_code FROM leave_requests lr
                          JOIN leave_types lt ON lt.id = lr.leave_type_id JOIN employees e ON e.id = lr.employee_id
                         WHERE lr.status IN ('approved','pending') AND lr.start_date <= ? AND lr.end_date >= ? ORDER BY lr.start_date", [$last, $first]);
        return view('leave/calendar', ['title' => 'Leave calendar', 'crumb' => 'Time · Leave', 'rows' => $rows, 'first' => $first, 'last' => $last,
            'holidays' => array_column(DB::all('SELECT holiday_date, name FROM public_holidays WHERE holiday_date BETWEEN ? AND ?', [$first, $last]), 'name', 'holiday_date')]);
    }
}
