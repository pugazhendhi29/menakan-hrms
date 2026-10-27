<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\DB;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Services\AttendanceService;
use App\Services\AuditLogger;

final class AttendanceController extends Controller
{
    private function filters(Request $request): array
    {
        $from = $request->str('from');
        $to = $request->str('to');
        $from = Validator::isDate($from) ? $from : date('Y-m-d', strtotime(uk_today() . ' -13 days'));
        $to = Validator::isDate($to) ? $to : uk_today();
        $where = ['a.work_date BETWEEN ? AND ?', 'e.deleted_at IS NULL'];
        $params = [$from, $to];
        if ($emp = $request->int('employee_id')) {
            $where[] = 'a.employee_id = ?';
            $params[] = $emp;
        }
        if ($dept = $request->int('department_id')) {
            $where[] = 'e.department_id = ?';
            $params[] = $dept;
        }
        if (in_array($s = $request->str('status'), ['present', 'late', 'incomplete'], true)) {
            $where[] = 'a.status = ?';
            $params[] = $s;
            if ($s === 'incomplete' && !$request->str('from')) {
                $where[0] = 'a.work_date BETWEEN ? AND ?';
            }
        }
        if (in_array($src = $request->str('source'), ['web', 'import', 'manual', 'rota'], true)) {
            $where[] = 'a.source = ?';
            $params[] = $src;
        }
        if ($request->str('approved') !== '') {
            $where[] = 'a.is_approved = ?';
            $params[] = $request->str('approved') === '1' ? 1 : 0;
        }
        return [$from, $to, implode(' AND ', $where), $params];
    }

    public function index(Request $request): Response
    {
        AttendanceService::fillFromRota();   // shifts that ended since the last visit
        AttendanceService::flagIncomplete();
        [$from, $to, $where, $params] = $this->filters($request);
        $base = "FROM attendance_records a JOIN employees e ON e.id = a.employee_id LEFT JOIN departments d ON d.id = e.department_id
                 LEFT JOIN shifts s ON s.id = a.shift_id WHERE $where";
        $p = paginate((int) DB::value("SELECT COUNT(*) $base", $params), $request->int('page', 1), 50);
        $rows = DB::all("SELECT a.*, e.employee_code, e.first_name, e.last_name, d.name AS department, s.start_at AS shift_start, s.end_at AS shift_end
                         $base ORDER BY a.check_in_at DESC LIMIT {$p['per_page']} OFFSET {$p['offset']}", $params);
        $totals = DB::one("SELECT COUNT(*) AS n, COALESCE(SUM(a.net_minutes),0) AS mins, SUM(a.status = 'late') AS late, SUM(a.status = 'incomplete') AS incomplete $base", $params);
        foreach ($rows as &$row) {
            $row['locked'] = AttendanceService::isLocked($row);
        }
        unset($row);
        return view('attendance/index', ['title' => 'Attendance', 'crumb' => 'Time', 'rows' => $rows, 'p' => $p, 'request' => $request,
            'from' => $from, 'to' => $to, 'totals' => $totals, 'employees' => $this->employeeOptions(true), 'departments' => $this->departments()]);
    }

    public function export(Request $request): Response
    {
        [$from, $to, $where, $params] = $this->filters($request);
        $rows = DB::all("SELECT a.*, e.employee_code, e.first_name, e.last_name, d.name AS department FROM attendance_records a
                          JOIN employees e ON e.id = a.employee_id LEFT JOIN departments d ON d.id = e.department_id WHERE $where ORDER BY a.work_date, e.employee_code", $params);
        $out = array_map(fn ($a) => [$a['employee_code'], employee_name($a), $a['department'], uk_date($a['work_date']), uk_time($a['check_in_at']),
            uk_time($a['check_out_at']), (int) $a['break_minutes'], $a['net_minutes'] === null ? '' : round($a['net_minutes'] / 60, 2), ucfirst($a['status']),
            $a['source'] === 'rota' ? 'From rota' : ucfirst($a['source']), $a['is_approved'] ? 'Yes' : 'No', (string) $a['notes']], $rows);
        AuditLogger::log('attendance.exported', 'attendance', null, 'Exported ' . count($out) . " attendance records ($from to $to)");
        return $this->exportTable($request->str('format') === 'csv' ? 'csv' : 'xlsx', 'attendance', ['Employee ID', 'Name', 'Department', 'Work date', 'Check in', 'Check out', 'Break (min)', 'Net hours', 'Status', 'Source', 'Approved', 'Notes'], $out);
    }

    public function show(Request $request, int $id): Response
    {
        $a = $this->findOr404('SELECT a.*, e.employee_code, e.first_name, e.last_name, s.start_at AS shift_start, s.end_at AS shift_end, u.username AS approved_by_name
                                 FROM attendance_records a JOIN employees e ON e.id = a.employee_id LEFT JOIN shifts s ON s.id = a.shift_id
                                 LEFT JOIN users u ON u.id = a.approved_by WHERE a.id = ?', [$id]);
        $corrections = DB::all('SELECT c.*, u.username FROM attendance_corrections c LEFT JOIN users u ON u.id = c.changed_by WHERE c.attendance_id = ? ORDER BY c.changed_at DESC, c.id DESC', [$id]);
        return view('attendance/show', ['title' => 'Attendance record', 'crumb' => 'Time · Attendance', 'a' => $a, 'corrections' => $corrections,
            'locked' => AttendanceService::isLocked($a), 'onLeave' => AttendanceService::onLeave($a)]);
    }

    public function create(Request $request): Response
    {
        return view('attendance/form', ['title' => 'Add attendance record', 'crumb' => 'Time · Attendance', 'a' => null,
            'employees' => $this->employeeOptions(), 'employeeId' => $request->int('employee_id')]);
    }

    public function store(Request $request): Response
    {
        return $this->attempt($request, '/attendance/create', function () use ($request) {
            $id = AttendanceService::save(null, $request->only(['employee_id', 'work_date', 'check_in', 'check_out', 'break_minutes', 'notes', 'reason']), $this->userId());
            flash('success', 'Attendance record created.');
            return redirect('/attendance/' . $id);
        });
    }

    public function edit(Request $request, int $id): Response
    {
        $a = $this->findOr404('SELECT a.*, e.employee_code, e.first_name, e.last_name FROM attendance_records a JOIN employees e ON e.id = a.employee_id WHERE a.id = ?', [$id]);
        if (AttendanceService::isLocked($a)) {
            flash('warning', AttendanceService::LOCKED_MESSAGE);
            return redirect('/attendance/' . $id);
        }
        return view('attendance/form', ['title' => 'Correct attendance', 'crumb' => 'Time · Attendance', 'a' => $a, 'employees' => [], 'employeeId' => (int) $a['employee_id']]);
    }

    /** Removes a rota record on a day the employee is on approved leave. */
    public function remove(Request $request, int $id): Response
    {
        $this->findOr404('SELECT id FROM attendance_records WHERE id = ?', [$id]);
        return $this->attempt($request, '/attendance/' . $id, function () use ($request, $id) {
            AttendanceService::removeRotaRecord($id, $request->str('reason'), $this->userId());
            flash('success', 'Attendance removed: the employee was on leave.');
            return redirect('/attendance');
        });
    }

    public function update(Request $request, int $id): Response
    {
        $this->findOr404('SELECT id FROM attendance_records WHERE id = ?', [$id]);
        return $this->attempt($request, "/attendance/$id/edit", function () use ($request, $id) {
            AttendanceService::save($id, $request->only(['work_date', 'check_in', 'check_out', 'break_minutes', 'notes', 'reason']), $this->userId());
            flash('success', 'Attendance corrected. The change and reason are recorded in the history.');
            return redirect('/attendance/' . $id);
        });
    }

    public function approve(Request $request): Response
    {
        $n = AttendanceService::approve($request->arr('ids'), $this->userId());
        flash($n ? 'success' : 'warning', $n ? "$n records approved." : 'No completed, unapproved records were selected.');
        $back = $request->str('back');
        return redirect(str_starts_with($back, '/attendance') ? $back : '/attendance');
    }

    public function absences(Request $request): Response
    {
        $from = Validator::isDate($request->str('from')) ? $request->str('from') : date('Y-m-d', strtotime(uk_today() . ' -6 days'));
        $to = Validator::isDate($request->str('to')) ? $request->str('to') : uk_today();
        $rows = AttendanceService::absences($from, $to, $request->int('employee_id'), $request->int('department_id'));
        if ($request->str('show') !== 'all') {
            $rows = array_values(array_filter($rows, fn ($r) => $r['day_status'] === 'absent'));
        }
        return view('attendance/absences', ['title' => 'Absences', 'crumb' => 'Time · Attendance', 'rows' => $rows, 'from' => $from, 'to' => $to,
            'request' => $request, 'departments' => $this->departments(), 'employees' => $this->employeeOptions()]);
    }
}
