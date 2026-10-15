<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\DB;
use App\Core\Request;
use App\Core\Response;
use App\Core\ValidationException;
use App\Core\Validator;
use App\Services\AuditLogger;
use App\Services\HoursCalculator;
use App\Services\ShiftService;
use App\Services\WeekBoundary;

final class ShiftController extends Controller
{
    /** Calendar: day, week (department roster) and month views. */
    public function index(Request $request): Response
    {
        $view = in_array($request->str('view'), ['day', 'week', 'month'], true) ? $request->str('view') : 'week';
        $date = Validator::isDate($request->str('date')) ? $request->str('date') : uk_today();
        $filters = ['employee_id' => $request->int('employee_id'), 'department_id' => $request->int('department_id'), 'location_id' => $request->int('location_id')];
        $data = ['title' => 'Shifts', 'crumb' => 'Time', 'view' => $view, 'date' => $date, 'filters' => $filters, 'request' => $request,
            'employees' => $this->employeeOptions(), 'departments' => $this->departments(),
            'locations' => DB::all('SELECT id, name FROM locations WHERE is_active = 1 ORDER BY name')];
        $employee = $filters['employee_id'] ? DB::one('SELECT * FROM employees WHERE id = ?', [$filters['employee_id']]) : null;
        $data['employee'] = $employee;

        if ($view === 'day') {
            [$f, $t] = WeekBoundary::utcRangeForDates($date, $date);
            $data['shifts'] = ShiftService::inRange($f, $t, $filters);
            $data['prev'] = date('Y-m-d', strtotime("$date -1 day"));
            $data['next'] = date('Y-m-d', strtotime("$date +1 day"));
            $data['heading'] = from_utc(to_utc("$date 12:00"))->format('l d F Y');
        } elseif ($view === 'week') {
            $week = WeekBoundary::weekStartForDate($date);
            $days = WeekBoundary::daysOfWeek($week);
            [$f, $t] = WeekBoundary::utcRange($week);
            $shifts = ShiftService::inRange($f, $t, $filters);
            $people = [];
            $empSql = "SELECT e.*, d.name AS department FROM employees e LEFT JOIN departments d ON d.id = e.department_id WHERE e.deleted_at IS NULL AND e.status <> 'left'";
            $params = [];
            if ($filters['department_id']) {
                $empSql .= ' AND e.department_id = ?';
                $params[] = $filters['department_id'];
            }
            if ($filters['employee_id']) {
                $empSql .= ' AND e.id = ?';
                $params[] = $filters['employee_id'];
            }
            foreach (DB::all($empSql . ' ORDER BY d.name, e.last_name, e.first_name', $params) as $e) {
                $people[$e['id']] = $e + ['cells' => array_fill_keys($days, []), 'minutes' => 0, 'limit' => HoursCalculator::limitMinutes($e)];
            }
            foreach ($shifts as $s) {
                if (!isset($people[$s['employee_id']]) || $s['week_start'] !== $week) {
                    continue;
                }
                $people[$s['employee_id']]['cells'][WeekBoundary::ukDateOfUtc($s['start_at'])][] = $s;
                $people[$s['employee_id']]['minutes'] += (int) $s['net_minutes'];
            }
            if ($filters['location_id']) {
                $people = array_filter($people, fn ($p) => $p['minutes'] > 0);
            }
            $leave = DB::all("SELECT lr.employee_id, lr.start_date, lr.end_date, lt.name FROM leave_requests lr JOIN leave_types lt ON lt.id = lr.leave_type_id
                               WHERE lr.status = 'approved' AND lr.start_date <= ? AND lr.end_date >= ?", [$days[6], $days[0]]);
            foreach ($leave as $l) {
                foreach ($days as $d) {
                    if (isset($people[$l['employee_id']]) && $d >= $l['start_date'] && $d <= $l['end_date']) {
                        $people[$l['employee_id']]['leave'][$d] = $l['name'];
                    }
                }
            }
            $exceptions = DB::all('SELECT employee_id FROM hours_exceptions WHERE week_start = ?', [$week]);
            $data += ['week' => $week, 'days' => $days, 'people' => $people, 'exceptions' => array_flip(array_column($exceptions, 'employee_id')),
                'holidays' => array_flip(DB::column('SELECT holiday_date FROM public_holidays WHERE holiday_date BETWEEN ? AND ?', [$days[0], $days[6]])),
                'prev' => date('Y-m-d', strtotime("$week -7 days")), 'next' => date('Y-m-d', strtotime("$week +7 days")),
                'heading' => 'Week commencing ' . uk_date($week)];
        } else {
            $y = (int) substr($date, 0, 4);
            $m = (int) substr($date, 5, 2);
            $weeks = WeekBoundary::monthGridWeeks($y, $m);
            [$f] = WeekBoundary::utcRange($weeks[0]);
            [, $t] = WeekBoundary::utcRange(end($weeks));
            $shifts = ShiftService::inRange($f, $t, $filters);
            $byDay = [];
            $weekTotals = array_fill_keys($weeks, ['minutes' => 0, 'count' => 0]);
            foreach ($shifts as $s) {
                $byDay[WeekBoundary::ukDateOfUtc($s['start_at'])][] = $s;
                if (isset($weekTotals[$s['week_start']])) {
                    $weekTotals[$s['week_start']]['minutes'] += (int) $s['net_minutes'];
                    $weekTotals[$s['week_start']]['count']++;
                }
            }
            $leaveDays = [];
            if ($employee) {
                $lastDay = date('Y-m-d', strtotime(end($weeks) . ' +6 days'));
                foreach (DB::all("SELECT lr.start_date, lr.end_date, lt.name FROM leave_requests lr JOIN leave_types lt ON lt.id = lr.leave_type_id
                                   WHERE lr.employee_id = ? AND lr.status = 'approved' AND lr.start_date <= ? AND lr.end_date >= ?", [$employee['id'], $lastDay, $weeks[0]]) as $l) {
                    for ($d = $l['start_date']; $d <= $l['end_date']; $d = date('Y-m-d', strtotime("$d +1 day"))) {
                        $leaveDays[$d] = $l['name'];
                    }
                }
            }
            $first = sprintf('%04d-%02d-01', $y, $m);
            $data += ['weeks' => $weeks, 'byDay' => $byDay, 'weekTotals' => $weekTotals, 'month' => $m, 'leaveDays' => $leaveDays,
                'limit' => $employee ? HoursCalculator::limitMinutes($employee) : null,
                'holidays' => array_column(DB::all('SELECT holiday_date, name FROM public_holidays WHERE holiday_date BETWEEN ? AND ?', [$weeks[0], date('Y-m-d', strtotime(end($weeks) . ' +6 days'))]), 'name', 'holiday_date'),
                'prev' => date('Y-m-d', strtotime("$first -1 month")), 'next' => date('Y-m-d', strtotime("$first +1 month")),
                'heading' => month_name($m) . ' ' . $y];
        }
        return view('shifts/index', $data);
    }

    private function formData(?array $shift, Request $request): array
    {
        return [
            'employees' => $this->employeeOptions(),
            'types' => DB::all('SELECT * FROM shift_types WHERE is_active = 1 ORDER BY default_start'),
            'locations' => DB::all('SELECT id, name FROM locations WHERE is_active = 1 ORDER BY name'),
            'shift' => $shift, 'employeeId' => $request->int('employee_id'),
            'defaultType' => !$shift && $request->int('employee_id') ? DB::one('SELECT st.* FROM employees e JOIN shift_types st ON st.id = e.default_shift_type_id WHERE e.id = ? AND st.is_active = 1', [$request->int('employee_id')]) : null, 'date' => Validator::isDate($request->str('date')) ? $request->str('date') : uk_today(),
        ];
    }

    public function create(Request $request): Response
    {
        return view('shifts/form', ['title' => 'Add shift', 'crumb' => 'Time · Shifts'] + $this->formData(null, $request));
    }

    private function input(Request $request): array
    {
        $in = $request->only(['employee_id', 'date', 'start_time', 'end_time', 'break_minutes', 'shift_type_id', 'location_id', 'status', 'notes']);
        $in['override_reason'] = $request->bool('approve_over_limit') ? $request->str('override_reason') : '';
        return $in;
    }

    public function store(Request $request): Response
    {
        return $this->attempt($request, '/shifts/create', function () use ($request) {
            $r = ShiftService::save(null, $this->input($request), $this->userId());
            flash('success', 'Shift saved.');
            foreach ($r['warnings'] as $w) {
                flash('warning', $w);
            }
            $date = $request->str('date');
            return redirect('/shifts?view=week&date=' . $date . '&employee_id=' . $request->str('employee_id'));
        });
    }

    public function edit(Request $request, int $id): Response
    {
        $s = $this->findOr404('SELECT s.*, e.employee_code, e.first_name, e.last_name FROM shifts s JOIN employees e ON e.id = s.employee_id WHERE s.id = ?', [$id]);
        return view('shifts/form', ['title' => 'Edit shift', 'crumb' => 'Time · Shifts'] + $this->formData($s, $request));
    }

    public function update(Request $request, int $id): Response
    {
        $this->findOr404('SELECT id FROM shifts WHERE id = ?', [$id]);
        return $this->attempt($request, "/shifts/$id/edit", function () use ($request, $id) {
            $r = ShiftService::save($id, $this->input($request), $this->userId());
            flash('success', 'Shift updated.');
            foreach ($r['warnings'] as $w) {
                flash('warning', $w);
            }
            return redirect('/shifts?view=week&date=' . $request->str('date') . '&employee_id=' . $request->str('employee_id'));
        });
    }

    public function cancel(Request $request, int $id): Response
    {
        $s = $this->findOr404('SELECT * FROM shifts WHERE id = ?', [$id]);
        return $this->attempt($request, "/shifts/$id/edit", function () use ($id, $s) {
            ShiftService::cancel($id, $this->userId());
            flash('success', 'Shift cancelled.');
            return redirect('/shifts?view=week&date=' . WeekBoundary::ukDateOfUtc($s['start_at']));
        });
    }

    public function publish(Request $request): Response
    {
        $week = WeekBoundary::weekStartForDate(Validator::isDate($request->str('date')) ? $request->str('date') : uk_today());
        [$f, $t] = WeekBoundary::utcRange($week);
        $n = ShiftService::publishRange($f, $t, $request->int('department_id'), $request->int('employee_id'), $this->userId());
        flash($n ? 'success' : 'info', $n ? "$n draft shifts published for the week of " . uk_date($week) . '. Employees have been notified.' : 'There were no draft shifts to publish.');
        return redirect('/shifts?view=week&date=' . $week . ($request->int('department_id') ? '&department_id=' . $request->int('department_id') : ''));
    }

    /** Weekly limit exceptions for a week, with approval. */
    public function exceptions(Request $request): Response
    {
        $week = WeekBoundary::weekStartForDate(Validator::isDate($request->str('week')) ? $request->str('week') : uk_today());
        $rows = HoursCalculator::overLimitForWeek($week, $request->int('department_id'));
        $approved = DB::all('SELECT h.*, e.employee_code, e.first_name, e.last_name, u.username FROM hours_exceptions h JOIN employees e ON e.id = h.employee_id
                              LEFT JOIN users u ON u.id = h.approved_by WHERE h.week_start = ? ORDER BY e.last_name', [$week]);
        return view('shifts/exceptions', ['title' => 'Weekly hours exceptions', 'crumb' => 'Time · Shifts', 'week' => $week, 'rows' => $rows,
            'approved' => $approved, 'departments' => $this->departments(), 'request' => $request]);
    }

    public function approveException(Request $request): Response
    {
        $week = WeekBoundary::weekStartForDate($request->str('week'));
        return $this->attempt($request, '/shifts/exceptions?week=' . $week, function () use ($request, $week) {
            $emp = DB::one('SELECT * FROM employees WHERE id = ?', [(int) $request->int('employee_id', 0)]) ?? throw new ValidationException('Employee not found.');
            $reason = $request->str('reason');
            if ($reason === '') {
                throw new ValidationException('A reason is required to approve hours over the limit.');
            }
            ShiftService::approveException((int) $emp['id'], $week, HoursCalculator::limitMinutes($emp), HoursCalculator::scheduledMinutes((int) $emp['id'], $week), $reason, $this->userId());
            flash('success', 'Over-limit hours approved for ' . employee_name($emp) . '.');
            return redirect('/shifts/exceptions?week=' . $week);
        });
    }

    public function export(Request $request): Response
    {
        $from = Validator::isDate($request->str('from')) ? $request->str('from') : WeekBoundary::weekStartForDate(uk_today());
        $to = Validator::isDate($request->str('to')) ? $request->str('to') : date('Y-m-d', strtotime("$from +6 days"));
        [$f, $t] = WeekBoundary::utcRangeForDates($from, $to);
        $rows = ShiftService::inRange($f, $t, ['department_id' => $request->int('department_id'), 'employee_id' => $request->int('employee_id')]);
        $out = array_map(fn ($s) => [$s['employee_code'], employee_name($s), $s['department'], uk_date(WeekBoundary::ukDateOfUtc($s['start_at'])),
            uk_time($s['start_at']), uk_time($s['end_at']), (int) $s['break_minutes'], round($s['net_minutes'] / 60, 2), $s['type_name'], $s['location_name'], ucfirst($s['status']), (string) $s['notes']], $rows);
        AuditLogger::log('shift.exported', 'shift', null, 'Exported roster ' . uk_date($from) . ' to ' . uk_date($to));
        return $this->exportTable($request->str('format') === 'csv' ? 'csv' : 'xlsx', 'roster', ['Employee ID', 'Name', 'Department', 'Date', 'Start', 'End', 'Break (min)', 'Net hours', 'Shift type', 'Location', 'Status', 'Notes'], $out);
    }
}
