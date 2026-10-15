<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\DB;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\ValidationException;
use App\Services\AuditLogger;
use App\Services\DocumentService;
use App\Services\EmployeeService;
use App\Services\EmployeeTransfer;
use App\Services\ExcelIO;
use App\Services\HoursCalculator;
use App\Services\IdCardService;
use App\Services\LeaveService;
use App\Services\PasswordTokens;
use App\Services\ShiftService;
use App\Services\WeekBoundary;

final class EmployeeController extends Controller
{
    private const SORTS = [
        'code' => 'e.employee_code', 'name' => 'e.last_name, e.first_name', 'department' => 'd.name, e.last_name',
        'start' => 'e.start_date', 'status' => 'e.status, e.last_name',
    ];

    /** Builds the filtered employee query shared by the list and the export. */
    private function query(Request $request): array
    {
        $where = ['1=1'];
        $params = [];
        $archived = $request->str('archived') === '1';
        $where[] = $archived ? 'e.deleted_at IS NOT NULL' : 'e.deleted_at IS NULL';
        if ($q = $request->str('q')) {
            $where[] = "(e.employee_code LIKE ? OR CONCAT(e.first_name, ' ', e.last_name) LIKE ? OR e.email LIKE ? OR e.phone LIKE ?)";
            $like = '%' . addcslashes($q, '%_\\') . '%';
            array_push($params, $like, $like, $like, $like);
        }
        if ($dept = $request->int('department_id')) {
            $where[] = 'e.department_id = ?';
            $params[] = $dept;
        }
        if ($job = $request->int('job_title_id')) {
            $where[] = 'e.job_title_id = ?';
            $params[] = $job;
        }
        $status = $request->str('status', 'current');
        if ($status === 'current') {
            $where[] = "e.status <> 'left'";
        } elseif (isset(EmployeeService::STATUSES[$status])) {
            $where[] = 'e.status = ?';
            $params[] = $status;
        }
        $sort = self::SORTS[$request->str('sort')] ?? self::SORTS['code'];
        $sql = 'FROM employees e LEFT JOIN departments d ON d.id = e.department_id LEFT JOIN job_titles j ON j.id = e.job_title_id
                LEFT JOIN employees m ON m.id = e.line_manager_id WHERE ' . implode(' AND ', $where);
        return [$sql, $params, $sort];
    }

    public function index(Request $request): Response
    {
        [$from, $params, $sort] = $this->query($request);
        $select = 'SELECT e.*, d.name AS department, j.title AS job_title, COALESCE(m.first_name, e.reporting_manager_name) AS m_first_name, m.last_name AS m_last_name ';
        $docFilter = $request->str('documents');
        if ($docFilter !== '') {
            // Compliance is derived per employee, so filter in PHP over the full result.
            $rows = DB::all($select . $from . " ORDER BY $sort", $params);
            $rows = array_values(array_filter(array_map(function ($r) {
                $r['compliance'] = DocumentService::compliance($r);
                return $r;
            }, $rows), fn ($r) => $docFilter === 'incomplete'
                ? ($r['compliance']['missing'] + $r['compliance']['expired'] + $r['compliance']['expiring']) > 0
                : ($r['compliance']['missing'] + $r['compliance']['expired'] + $r['compliance']['expiring']) === 0));
            $p = paginate(count($rows), $request->int('page', 1));
            $rows = array_slice($rows, $p['offset'], $p['per_page']);
        } else {
            $p = paginate((int) DB::value('SELECT COUNT(*) ' . $from, $params), $request->int('page', 1));
            $rows = DB::all($select . $from . " ORDER BY $sort LIMIT {$p['per_page']} OFFSET {$p['offset']}", $params);
            foreach ($rows as &$r) {
                $r['compliance'] = DocumentService::compliance($r);
            }
            unset($r);
        }
        return view('employees/index', [
            'title' => 'Employees', 'crumb' => 'People', 'rows' => $rows, 'p' => $p, 'request' => $request,
            'departments' => $this->departments(), 'jobTitles' => DB::all('SELECT id, title FROM job_titles ORDER BY title'),
        ]);
    }

    /** Every employee field (Employees sheet, re-importable) plus work experience and education, for the list as filtered. */
    public function export(Request $request): Response
    {
        [$from, $params, $sort] = $this->query($request);
        [$rows, $ids] = EmployeeTransfer::exportRows($from, $params, $sort);
        AuditLogger::log('employee.exported', 'employee', null, 'Exported ' . count($rows) . ' employees with all fields, including identity and pay data');
        if ($request->str('format') === 'csv') {
            return Response::download(ExcelIO::csv(EmployeeTransfer::headers(), $rows), 'employees-' . uk_today() . '.csv', 'text/csv; charset=utf-8');
        }
        return Response::download(EmployeeTransfer::workbook($rows, $ids), 'employees-' . uk_today() . '.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    }

    /** Employee fields plus the repeatable work experience / education rows. */
    private function employeeInput(Request $request): array
    {
        return $request->only(EmployeeService::FIELDS) + ['history_submitted' => $request->str('history_submitted'),
            'experience' => $request->arr('experience'), 'education' => $request->arr('education')];
    }

    private function historyRows(?int $id): array
    {
        return [
            'experienceRows' => $id ? DB::all('SELECT * FROM employee_work_experience WHERE employee_id = ? ORDER BY is_current DESC, from_date DESC', [$id]) : [],
            'educationRows' => $id ? DB::all('SELECT * FROM employee_education WHERE employee_id = ? ORDER BY completion_date IS NULL, completion_date DESC', [$id]) : [],
        ];
    }

    public function create(Request $request): Response
    {
        return view('employees/form', ['title' => 'New employee', 'crumb' => 'People · Employees', 'employee' => null,
            'nextCode' => EmployeeService::previewNextCode()] + $this->historyRows(null) + EmployeeService::lookups());
    }

    public function store(Request $request): Response
    {
        return $this->attempt($request, '/employees/create', function () use ($request) {
            $photo = $request->file('photo');
            if ($photo) {
                EmployeeService::validatePhoto($photo);
            }
            $id = EmployeeService::create($this->employeeInput($request), $this->userId());
            if ($photo) {
                EmployeeService::setPhoto($id, $photo);
            }
            flash('success', 'Employee created.');
            return redirect('/employees/' . $id);
        });
    }

    private function load(int $id, bool $withDeleted = false): array
    {
        return $this->findOr404(
            'SELECT e.*, d.name AS department, j.title AS job_title, COALESCE(m.first_name, e.reporting_manager_name) AS m_first_name, m.last_name AS m_last_name, m.employee_code AS m_code,
                    wp.name AS pattern_name, loc.name AS location_name, dst.name AS default_shift_name
               FROM employees e LEFT JOIN departments d ON d.id = e.department_id LEFT JOIN job_titles j ON j.id = e.job_title_id
               LEFT JOIN employees m ON m.id = e.line_manager_id LEFT JOIN work_patterns wp ON wp.id = e.work_pattern_id
               LEFT JOIN locations loc ON loc.id = e.location_id LEFT JOIN shift_types dst ON dst.id = e.default_shift_type_id
              WHERE e.id = ?' . ($withDeleted ? '' : ' AND e.deleted_at IS NULL'),
            [$id]
        );
    }

    public function show(Request $request, int $id): Response
    {
        $e = $this->load($id, true);
        $tab = $request->str('tab', 'overview');
        $tabs = ['overview' => 'Overview', 'idcard' => 'ID card', 'personal' => 'Personal & profile', 'employment' => 'Employment', 'documents' => 'Documents', 'attendance' => 'Attendance',
            'shifts' => 'Shifts', 'leave' => 'Leave', 'payslips' => 'Payslips', 'account' => 'Account'];
        if (!isset($tabs[$tab])) {
            $tab = 'overview';
        }
        $data = ['title' => employee_name($e), 'crumb' => 'People · Employees · ' . $e['employee_code'], 'e' => $e, 'tab' => $tab, 'tabs' => $tabs, 'request' => $request];
        $data['compliance'] = DocumentService::compliance($e);
        $data['account'] = DB::one('SELECT * FROM users WHERE employee_id = ? ORDER BY is_active DESC, id DESC LIMIT 1', [$id]);
        $week = WeekBoundary::weekStartForDate(uk_today());
        $data['weekMinutes'] = HoursCalculator::scheduledMinutes($id, $week);
        $data['limitMinutes'] = HoursCalculator::limitMinutes($e);
        $data['leaveSummary'] = $e['deleted_at'] === null && $e['status'] !== 'left' ? LeaveService::annualSummary($id) : null;

        switch ($tab) {
            case 'personal':
                $data += $this->historyRows($id);
                $data['experience'] = EmployeeService::experience($e);
                break;
            case 'documents':
                $data['documents'] = DB::all('SELECT d.*, dc.name AS category_name FROM employee_documents d JOIN document_categories dc ON dc.id = d.category_id
                                               WHERE d.employee_id = ? ORDER BY d.is_current DESC, dc.name, d.created_at DESC', [$id]);
                break;
            case 'attendance':
                $data['attendance'] = DB::all('SELECT * FROM attendance_records WHERE employee_id = ? ORDER BY check_in_at DESC LIMIT 60', [$id]);
                break;
            case 'shifts':
                [$f, $t] = WeekBoundary::utcRangeForDates(date('Y-m-d', strtotime('-28 days')), date('Y-m-d', strtotime('+35 days')));
                $data['shifts'] = ShiftService::inRange($f, $t, ['employee_id' => $id, 'include_cancelled' => true]);
                break;
            case 'leave':
                $data['requests'] = DB::all('SELECT lr.*, lt.name AS type_name FROM leave_requests lr JOIN leave_types lt ON lt.id = lr.leave_type_id WHERE lr.employee_id = ? ORDER BY lr.start_date DESC', [$id]);
                $data['ledger'] = DB::all('SELECT l.*, lt.name AS type_name FROM leave_ledger l JOIN leave_types lt ON lt.id = l.leave_type_id WHERE l.employee_id = ? ORDER BY l.leave_year DESC, l.created_at DESC, l.id DESC', [$id]);
                $data['leaveTypes'] = DB::all('SELECT id, name FROM leave_types WHERE deducts_balance = 1 ORDER BY sort_order, name');
                break;
            case 'payslips':
                $data['payslips'] = DB::all('SELECT * FROM payslips WHERE employee_id = ? ORDER BY period_year DESC, period_month DESC, created_at DESC', [$id]);
                break;
            case 'idcard':
                $data['card'] = IdCardService::current($id);
                $data['cardHistory'] = IdCardService::history($id);
                break;
            case 'account':
                $data['accounts'] = DB::all('SELECT * FROM users WHERE employee_id = ? ORDER BY id DESC', [$id]);
                break;
        }
        return view('employees/show', $data);
    }

    public function edit(Request $request, int $id): Response
    {
        $e = $this->load($id);
        return view('employees/form', ['title' => 'Edit ' . employee_name($e), 'crumb' => 'People · Employees · ' . $e['employee_code'], 'employee' => $e] + $this->historyRows($id) + EmployeeService::lookups());
    }

    public function update(Request $request, int $id): Response
    {
        $this->load($id);
        return $this->attempt($request, "/employees/$id/edit", function () use ($request, $id) {
            $photo = $request->file('photo');
            if ($photo) {
                EmployeeService::validatePhoto($photo);
            }
            EmployeeService::update($id, $this->employeeInput($request), $this->userId());
            if ($photo) {
                EmployeeService::setPhoto($id, $photo);
            }
            flash('success', 'Employee updated.');
            return redirect('/employees/' . $id);
        });
    }

    public function destroy(Request $request, int $id): Response
    {
        $e = $this->load($id);
        if ((int) ($this->user()['employee_id'] ?? 0) === $id) {
            flash('danger', 'You cannot archive your own employee record.');
            return redirect('/employees/' . $id);
        }
        EmployeeService::softDelete($id);
        flash('success', $e['employee_code'] . ' has been archived. The record is kept and can be restored.');
        return redirect('/employees');
    }

    public function restore(Request $request, int $id): Response
    {
        $this->load($id, true);
        EmployeeService::restore($id);
        flash('success', 'Employee restored. Their user account stays deactivated until you re-enable it.');
        return redirect('/employees/' . $id);
    }

    /** Creates a portal account linked to the employee and returns an activation link. */
    public function createAccount(Request $request, int $id): Response
    {
        $e = $this->load($id);
        return $this->attempt($request, "/employees/$id?tab=account", function () use ($request, $e, $id) {
            $username = strtolower($request->str('username'));
            if (!preg_match('/^[a-z0-9._-]{3,60}$/', $username)) {
                throw new ValidationException('Username must be 3–60 lowercase letters, numbers, dots, dashes or underscores.');
            }
            if (DB::value('SELECT id FROM users WHERE username = ?', [$username])) {
                throw new ValidationException('That username is already taken.');
            }
            if (DB::value('SELECT id FROM users WHERE employee_id = ? AND is_active = 1', [$id])) {
                throw new ValidationException('This employee already has an active account.');
            }
            if ($e['status'] === 'left') {
                throw new ValidationException('Accounts cannot be created for leavers.');
            }
            $email = $e['email'] && !DB::value('SELECT id FROM users WHERE email = ?', [$e['email']]) ? $e['email'] : null;
            $userId = DB::insert('users', ['employee_id' => $id, 'username' => $username, 'email' => $email, 'role' => 'employee',
                'is_active' => 1, 'created_by' => $this->userId()]);
            $link = PasswordTokens::issue($userId, 'activation', $this->userId());
            AuditLogger::log('user.created', 'user', $userId, "Created portal account '$username' for " . $e['employee_code'], ['employee_id' => $id]);
            flash('success', 'Account created. Send this activation link to the employee (it was also written to the mail log): ' . $link);
            return redirect("/employees/$id?tab=account");
        });
    }

    public function uploadPhoto(Request $request, int $id): Response
    {
        $this->load($id);
        return $this->attempt($request, "/employees/$id?tab=personal", function () use ($request, $id) {
            EmployeeService::setPhoto($id, $request->file('photo'));
            flash('success', 'Photo updated.');
            return redirect("/employees/$id?tab=personal");
        });
    }

    public function deletePhoto(Request $request, int $id): Response
    {
        $this->load($id);
        EmployeeService::removePhoto($id);
        flash('success', 'Photo removed.');
        return redirect("/employees/$id?tab=personal");
    }

    public function recalculateLeave(Request $request, int $id): Response
    {
        $this->load($id);
        [, , $year] = LeaveService::yearBounds(uk_today());
        $year = $request->int('year', $year);
        LeaveService::recalculate($id, $year, $this->userId());
        AuditLogger::log('leave.recalculated', 'employee', $id, "Recalculated $year annual leave entitlement");
        flash('success', "Entitlement for $year recalculated.");
        return redirect("/employees/$id?tab=leave");
    }

    public function adjustLeave(Request $request, int $id): Response
    {
        $this->load($id);
        return $this->attempt($request, "/employees/$id?tab=leave", function () use ($request, $id) {
            $days = $request->str('days');
            if (!is_numeric($days) || fmod((float) $days * 2, 1.0) != 0.0) {
                throw new ValidationException('Days must be a number in half-day steps, e.g. 1, -0.5, 2.5.');
            }
            [, , $year] = LeaveService::yearBounds(uk_today());
            LeaveService::manualAdjustment($id, $request->int('leave_type_id', LeaveService::annualTypeId()), $request->int('year', $year), (float) $days, $request->str('note'), $this->userId());
            flash('success', 'Leave balance adjusted.');
            return redirect("/employees/$id?tab=leave");
        });
    }
}
