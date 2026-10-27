<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\DB;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Services\AttendanceService;
use App\Services\AuditLogger;
use App\Services\DocumentService;
use App\Services\EmployeeService;
use App\Services\HoursCalculator;
use App\Services\LeaveService;
use App\Services\Settings;
use App\Services\WeekBoundary;
use App\Services\WorkingTimeChecker;

/**
 * 17 admin reports. Each is defined by a title, the filters it uses and a builder that
 * returns [headers, rows]. Every report can be viewed, or exported as CSV or Excel.
 */
final class ReportController extends Controller
{
    private function definitions(): array
    {
        return [
            'People' => [
                'employee_directory' => ['Employee directory', 'All current employees with job, department, manager and contact details.', ['department', 'status', 'pay']],
                'headcount' => ['Headcount by department', 'Employees per department by status and employment type.', []],
                'starters_leavers' => ['Starters and leavers', 'People who joined or left in the period.', ['period', 'department']],
                'probation' => ['Probation reviews due', 'Employees on probation and when their probation ends.', ['department']],
                'document_compliance' => ['Document compliance', 'Required documents per employee: satisfied, missing, expired and expiring.', ['department']],
                'document_expiry' => ['Documents expiring', 'Current documents that expire within the chosen number of days (or have expired).', ['days', 'department']],
            ],
            'Time & attendance' => [
                'attendance_summary' => ['Attendance summary', 'Per employee: days attended, net hours, late arrivals and incomplete sessions.', ['period', 'department', 'employee']],
                'lateness' => ['Late arrivals', 'Every check-in after shift start plus the grace period.', ['period', 'department', 'employee']],
                'absence' => ['Unexplained absences', 'Published shifts with no check-in and no approved leave.', ['period', 'department', 'employee']],
                'scheduled_vs_actual' => ['Scheduled vs worked hours', 'Published scheduled hours against attended net hours per employee.', ['period', 'department']],
            ],
            'Shifts & hours' => [
                'weekly_hours' => ['Weekly hours vs limit', 'Scheduled hours per employee for a week against their limit.', ['week', 'department']],
                'hours_exceptions' => ['Approved hours exceptions', 'Weeks where hours over the limit were approved, with reasons.', ['period', 'department']],
                'working_time' => ['Working Time check', '17-week average weekly hours (48h limit) and daily rest breaches (11h).', ['week', 'department']],
                'roster' => ['Shift roster', 'All shifts in the period, for printing or sharing.', ['period', 'department', 'employee']],
            ],
            'Leave & pay' => [
                'leave_balances' => ['Leave balances', 'Annual leave entitlement, remaining and pending for the leave year.', ['department']],
                'leave_taken' => ['Leave taken', 'Approved leave in the period by type.', ['period', 'department', 'employee']],
                'payslip_status' => ['Payslip publication', 'For a month: who has a published, draft or missing payslip.', ['month', 'department']],
            ],
        ];
    }

    private function find(string $key): array
    {
        foreach ($this->definitions() as $group => $items) {
            if (isset($items[$key])) {
                return $items[$key] + [3 => $group];
            }
        }
        throw new HttpException(404);
    }

    public function index(Request $request): Response
    {
        return view('reports/index', ['title' => 'Reports', 'crumb' => 'Admin', 'groups' => $this->definitions()]);
    }

    public function show(Request $request, string $key): Response
    {
        [$title, $description, $filterKeys, $group] = $this->find($key);
        $f = $this->filters($request);
        [$headers, $rows] = $this->build($key, $f);
        $format = $request->str('format');
        if (in_array($format, ['csv', 'xlsx'], true)) {
            AuditLogger::log('report.exported', 'report', null, "Exported report '$title' as " . strtoupper($format) . ' (' . count($rows) . ' rows)' . ($f['pay'] ? ' including pay data' : ''));
            return $this->exportTable($format, $key, $headers, $rows);
        }
        if ($f['pay']) {
            AuditLogger::log('report.viewed_sensitive', 'report', null, "Viewed report '$title' including pay data");
        }
        return view('reports/show', ['title' => $title, 'crumb' => 'Reports · ' . $group, 'description' => $description, 'key' => $key, 'filterKeys' => $filterKeys,
            'f' => $f, 'headers' => $headers, 'rows' => $rows, 'request' => $request, 'departments' => $this->departments(), 'employees' => $this->employeeOptions(true)]);
    }

    private function filters(Request $request): array
    {
        $today = uk_today();
        $from = Validator::isDate($request->str('from')) ? $request->str('from') : date('Y-m-01', strtotime($today));
        $to = Validator::isDate($request->str('to')) ? $request->str('to') : $today;
        if ($to < $from) {
            [$from, $to] = [$to, $from];
        }
        $month = preg_match('/^\d{4}-\d{2}$/', $request->str('month')) ? $request->str('month') : date('Y-m', strtotime("$today first day of last month"));
        return [
            'from' => $from, 'to' => $to, 'department' => $request->int('department_id'), 'employee' => $request->int('employee_id'),
            'status' => $request->str('status', 'current'), 'pay' => $request->bool('pay'), 'days' => max(1, min(365, $request->int('days', 60))),
            'week' => WeekBoundary::weekStartForDate(Validator::isDate($request->str('week')) ? $request->str('week') : $today), 'month' => $month,
        ];
    }

    /** Employees matching department/employee filters (not deleted, not left unless asked). */
    private function employees(array $f, bool $includeLeft = false): array
    {
        $sql = 'SELECT e.*, d.name AS department, j.title AS job_title FROM employees e LEFT JOIN departments d ON d.id = e.department_id
                LEFT JOIN job_titles j ON j.id = e.job_title_id WHERE e.deleted_at IS NULL' . ($includeLeft ? '' : " AND e.status <> 'left'");
        $params = [];
        if ($f['department']) {
            $sql .= ' AND e.department_id = ?';
            $params[] = $f['department'];
        }
        if ($f['employee']) {
            $sql .= ' AND e.id = ?';
            $params[] = $f['employee'];
        }
        return DB::all($sql . ' ORDER BY d.name, e.last_name, e.first_name', $params);
    }

    private function deptFilter(array $f, string $alias = 'e'): array
    {
        $sql = '';
        $p = [];
        if ($f['department']) {
            $sql .= " AND $alias.department_id = ?";
            $p[] = $f['department'];
        }
        if ($f['employee']) {
            $sql .= " AND $alias.id = ?";
            $p[] = $f['employee'];
        }
        return [$sql, $p];
    }

    private function build(string $key, array $f): array
    {
        [$dSql, $dP] = $this->deptFilter($f);
        switch ($key) {
            case 'employee_directory':
                $h = ['Employee ID', 'Name', 'Job title', 'Department', 'Line manager', 'Type', 'Contract hrs', 'Start date', 'Status', 'Email', 'Phone'];
                if ($f['pay']) {
                    array_push($h, 'Salary (£)', 'Pay frequency', 'Payroll ref');
                }
                $sql = 'SELECT e.*, d.name AS department, j.title AS job_title, COALESCE(CONCAT(m.first_name, " ", m.last_name), e.reporting_manager_name) AS manager FROM employees e
                        LEFT JOIN departments d ON d.id = e.department_id LEFT JOIN job_titles j ON j.id = e.job_title_id LEFT JOIN employees m ON m.id = e.line_manager_id
                        WHERE e.deleted_at IS NULL' . ($f['status'] === 'current' ? " AND e.status <> 'left'" : (isset(EmployeeService::statuses(true)[$f['status']]) ? ' AND e.status = ' . DB::pdo()->quote($f['status']) : '')) . $dSql . ' ORDER BY e.employee_code';
                $rows = [];
                foreach (DB::all($sql, $dP) as $e) {
                    $r = [$e['employee_code'], employee_name($e), $e['job_title'], $e['department'], $e['manager'], EmployeeService::TYPES[$e['employment_type']] ?? '', $e['contract_hours'] === null ? '' : (float) $e['contract_hours'],
                        uk_date($e['start_date']), EmployeeService::statuses(true)[$e['status']] ?? $e['status'], $e['email'], $e['phone']];
                    if ($f['pay']) {
                        array_push($r, $e['salary'] === null ? '' : (float) $e['salary'], $e['pay_frequency'], $e['payroll_reference']);
                    }
                    $rows[] = $r;
                }
                return [$h, $rows];

            case 'headcount':
                // One column per employee status (from Settings), apart from Left.
                $statuses = array_diff_key(EmployeeService::statuses(true), [EmployeeService::LEFT => 1]);
                $cols = [];
                $params = [];
                foreach (array_keys($statuses) as $i => $code) {
                    $cols[] = "SUM(e.status = ?) AS s$i";
                    $params[] = $code;
                }
                $rows = DB::all('SELECT d.name, ' . implode(', ', $cols) . ", SUM(e.employment_type = 'full_time') AS ft, SUM(e.employment_type <> 'full_time') AS other, COUNT(e.id) AS total
                                   FROM departments d LEFT JOIN employees e ON e.department_id = d.id AND e.deleted_at IS NULL AND e.status <> 'left' GROUP BY d.id ORDER BY d.name", $params);
                $n = count($statuses);
                $out = array_map(fn ($r) => [$r['name'], ...array_map(fn ($i) => (int) $r["s$i"], $n ? range(0, $n - 1) : []), (int) $r['ft'], (int) $r['other'], (int) $r['total']], $rows);
                $out[] = ['Total', ...array_map(fn ($i) => array_sum(array_column($out, $i)), range(1, $n + 3))];
                return [['Department', ...array_values($statuses), 'Full time', 'Other contracts', 'Total'], $out];

            case 'starters_leavers':
                $rows = DB::all("SELECT e.*, d.name AS department, j.title AS job_title FROM employees e LEFT JOIN departments d ON d.id = e.department_id LEFT JOIN job_titles j ON j.id = e.job_title_id
                                  WHERE e.deleted_at IS NULL AND ((e.start_date BETWEEN ? AND ?) OR (e.end_date BETWEEN ? AND ?)) $dSql ORDER BY COALESCE(e.end_date, e.start_date)",
                    array_merge([$f['from'], $f['to'], $f['from'], $f['to']], $dP));
                return [['Movement', 'Employee ID', 'Name', 'Job title', 'Department', 'Start date', 'End date', 'Leaving reason'], array_map(fn ($e) => [
                    $e['end_date'] && $e['end_date'] >= $f['from'] && $e['end_date'] <= $f['to'] ? 'Leaver' : 'Starter', $e['employee_code'], employee_name($e), $e['job_title'], $e['department'],
                    uk_date($e['start_date']), $e['end_date'] ? uk_date($e['end_date']) : '', (string) $e['leaving_reason']], $rows)];

            case 'probation':
                $rows = DB::all("SELECT e.*, d.name AS department, j.title AS job_title, COALESCE(CONCAT(m.first_name, ' ', m.last_name), e.reporting_manager_name) AS manager FROM employees e
                                  LEFT JOIN departments d ON d.id = e.department_id LEFT JOIN job_titles j ON j.id = e.job_title_id LEFT JOIN employees m ON m.id = e.line_manager_id
                                  WHERE e.deleted_at IS NULL AND e.status = 'probation' $dSql ORDER BY e.probation_end_date", $dP);
                return [['Employee ID', 'Name', 'Job title', 'Department', 'Manager', 'Start date', 'Probation ends', 'Days left'], array_map(fn ($e) => [
                    $e['employee_code'], employee_name($e), $e['job_title'], $e['department'], $e['manager'], uk_date($e['start_date']), uk_date($e['probation_end_date']),
                    $e['probation_end_date'] ? (int) floor((strtotime($e['probation_end_date']) - strtotime(uk_today())) / 86400) : ''], $rows)];

            case 'document_compliance':
                $out = [];
                foreach ($this->employees($f) as $e) {
                    $c = DocumentService::compliance($e);
                    $missing = implode(', ', array_map(fn ($r) => $r['category']['name'], array_filter($c['rows'], fn ($r) => in_array($r['status'], ['missing', 'expired', 'rejected'], true))));
                    $out[] = [$e['employee_code'], employee_name($e), $e['department'], $e['job_title'], $c['required'], $c['satisfied'], $c['missing'], $c['expired'], $c['expiring'], $missing];
                }
                return [['Employee ID', 'Name', 'Department', 'Job title', 'Required', 'Held', 'Missing', 'Expired', 'Expiring', 'Action needed'], $out];

            case 'document_expiry':
                $rows = DocumentService::expiring($f['days']);
                if ($f['department']) {
                    $name = DB::value('SELECT name FROM departments WHERE id = ?', [$f['department']]);
                    $rows = array_filter($rows, fn ($r) => $r['department'] === $name);
                }
                return [['Employee ID', 'Name', 'Department', 'Document', 'Number', 'Expiry date', 'Days left', 'Verification'], array_values(array_map(fn ($d) => [
                    $d['employee_code'], employee_name($d), $d['department'], $d['category_name'], (string) $d['document_number'], uk_date($d['expiry_date']),
                    (int) floor((strtotime($d['expiry_date']) - strtotime(uk_today())) / 86400), ucfirst($d['verification'])], $rows))];

            case 'attendance_summary':
                $rows = DB::all("SELECT e.employee_code, e.first_name, e.last_name, d.name AS department, COUNT(DISTINCT a.work_date) AS days, COALESCE(SUM(a.net_minutes),0) AS mins,
                                        SUM(a.status = 'late') AS late, SUM(a.status = 'incomplete') AS incomplete, SUM(a.is_approved = 0) AS unapproved
                                   FROM attendance_records a JOIN employees e ON e.id = a.employee_id LEFT JOIN departments d ON d.id = e.department_id
                                  WHERE a.work_date BETWEEN ? AND ? AND e.deleted_at IS NULL $dSql GROUP BY e.id ORDER BY d.name, e.last_name", array_merge([$f['from'], $f['to']], $dP));
                return [['Employee ID', 'Name', 'Department', 'Days attended', 'Net hours', 'Late', 'Incomplete', 'Unapproved'], array_map(fn ($r) => [
                    $r['employee_code'], employee_name($r), $r['department'], (int) $r['days'], round($r['mins'] / 60, 2), (int) $r['late'], (int) $r['incomplete'], (int) $r['unapproved']], $rows)];

            case 'lateness':
                $rows = DB::all("SELECT a.*, e.employee_code, e.first_name, e.last_name, d.name AS department, s.start_at AS shift_start FROM attendance_records a
                                   JOIN employees e ON e.id = a.employee_id LEFT JOIN departments d ON d.id = e.department_id LEFT JOIN shifts s ON s.id = a.shift_id
                                  WHERE a.status = 'late' AND a.work_date BETWEEN ? AND ? AND e.deleted_at IS NULL $dSql ORDER BY a.check_in_at", array_merge([$f['from'], $f['to']], $dP));
                return [['Date', 'Employee ID', 'Name', 'Department', 'Shift start', 'Checked in', 'Minutes late'], array_map(fn ($a) => [
                    uk_date($a['work_date']), $a['employee_code'], employee_name($a), $a['department'], uk_time($a['shift_start']), uk_time($a['check_in_at']),
                    $a['shift_start'] ? (int) round((strtotime($a['check_in_at'] . ' UTC') - strtotime($a['shift_start'] . ' UTC')) / 60) : ''], $rows)];

            case 'absence':
                $rows = array_filter(AttendanceService::absences($f['from'], min($f['to'], uk_today()), $f['employee'] ?: null, $f['department'] ?: null), fn ($r) => $r['day_status'] === 'absent');
                return [['Date', 'Employee ID', 'Name', 'Department', 'Shift'], array_values(array_map(fn ($r) => [
                    uk_date($r['uk_date']), $r['employee_code'], employee_name($r), $r['department'], uk_time($r['start_at']) . '–' . uk_time($r['end_at'])], $rows))];

            case 'scheduled_vs_actual':
                [$fu, $tu] = WeekBoundary::utcRangeForDates($f['from'], $f['to']);
                $out = [];
                foreach ($this->employees($f) as $e) {
                    $sched = (int) DB::value("SELECT COALESCE(SUM(net_minutes),0) FROM shifts WHERE employee_id = ? AND status = 'published' AND start_at >= ? AND start_at < ?", [$e['id'], $fu, $tu]);
                    $worked = (int) DB::value('SELECT COALESCE(SUM(net_minutes),0) FROM attendance_records WHERE employee_id = ? AND work_date BETWEEN ? AND ?', [$e['id'], $f['from'], $f['to']]);
                    if ($sched || $worked) {
                        $out[] = [$e['employee_code'], employee_name($e), $e['department'], round($sched / 60, 2), round($worked / 60, 2), round(($worked - $sched) / 60, 2)];
                    }
                }
                return [['Employee ID', 'Name', 'Department', 'Scheduled hrs', 'Worked hrs', 'Difference'], $out];

            case 'weekly_hours':
                $out = [];
                foreach ($this->employees($f) as $e) {
                    $m = HoursCalculator::scheduledMinutes((int) $e['id'], $f['week']);
                    $limit = HoursCalculator::limitMinutes($e);
                    $exc = DB::one('SELECT approved_minutes FROM hours_exceptions WHERE employee_id = ? AND week_start = ?', [$e['id'], $f['week']]);
                    $out[] = [$e['employee_code'], employee_name($e), $e['department'], round($m / 60, 2), round($limit / 60, 2), round(max(0, $m - $limit) / 60, 2),
                        $m > $limit ? ($exc && (int) $exc['approved_minutes'] >= $m ? 'Over · approved' : 'Over · needs approval') : 'Within limit'];
                }
                usort($out, fn ($a, $b) => $b[5] <=> $a[5]);
                return [['Employee ID', 'Name', 'Department', 'Scheduled hrs', 'Limit hrs', 'Excess hrs', 'Status'], $out];

            case 'hours_exceptions':
                $rows = DB::all("SELECT h.*, e.employee_code, e.first_name, e.last_name, d.name AS department, u.username FROM hours_exceptions h JOIN employees e ON e.id = h.employee_id
                                  LEFT JOIN departments d ON d.id = e.department_id LEFT JOIN users u ON u.id = h.approved_by
                                  WHERE h.week_start BETWEEN ? AND ? $dSql ORDER BY h.week_start DESC", array_merge([WeekBoundary::weekStartForDate($f['from']), $f['to']], $dP));
                return [['Week commencing', 'Employee ID', 'Name', 'Department', 'Approved hrs', 'Limit hrs', 'Reason', 'Approved by', 'Approved at'], array_map(fn ($h) => [
                    uk_date($h['week_start']), $h['employee_code'], employee_name($h), $h['department'], round($h['approved_minutes'] / 60, 2), round($h['limit_minutes'] / 60, 2),
                    $h['reason'], $h['username'], uk_datetime($h['approved_at'])], $rows)];

            case 'working_time':
                $weeks = (int) Settings::get('wtd_reference_weeks', 17);
                $limit = (int) Settings::get('wtd_average_limit_hours', 48);
                $rest = (int) Settings::get('daily_rest_hours', 11);
                [$fu] = WeekBoundary::utcRange(date('Y-m-d', strtotime($f['week'] . ' -' . ($weeks - 1) . ' weeks')));
                [, $tu] = WeekBoundary::utcRange($f['week']);
                $out = [];
                foreach ($this->employees($f) as $e) {
                    $avg = WorkingTimeChecker::averageWeeklyMinutes((int) $e['id'], $f['week'], $weeks) / 60;
                    $shifts = DB::all("SELECT id, start_at, end_at FROM shifts WHERE employee_id = ? AND status <> 'cancelled' AND start_at >= ? AND start_at < ?", [$e['id'], $fu, $tu]);
                    $breaches = WorkingTimeChecker::restBreaches($shifts, $rest);
                    if (!$shifts) {
                        continue;
                    }
                    $out[] = [$e['employee_code'], employee_name($e), $e['department'], round($avg, 1), $avg > $limit ? 'Over ' . $limit . 'h average' : 'OK', count($breaches),
                        $breaches ? uk_datetime($breaches[0]['after']['end_at']) . ' → ' . uk_datetime($breaches[0]['before']['start_at']) : ''];
                }
                return [['Employee ID', 'Name', 'Department', "Avg weekly hrs ($weeks wks)", '48h check', "Rest < {$rest}h", 'First breach'], $out];

            case 'roster':
                [$fu, $tu] = WeekBoundary::utcRangeForDates($f['from'], $f['to']);
                $rows = \App\Services\ShiftService::inRange($fu, $tu, ['department_id' => $f['department'], 'employee_id' => $f['employee']]);
                return [['Date', 'Start', 'End', 'Employee ID', 'Name', 'Department', 'Type', 'Location', 'Net hrs', 'Status'], array_map(fn ($s) => [
                    uk_date(WeekBoundary::ukDateOfUtc($s['start_at'])), uk_time($s['start_at']), uk_time($s['end_at']), $s['employee_code'], employee_name($s), $s['department'],
                    (string) $s['type_name'], (string) $s['location_name'], round($s['net_minutes'] / 60, 2), ucfirst($s['status'])], $rows)];

            case 'leave_balances':
                $out = [];
                foreach ($this->employees($f) as $e) {
                    $s = LeaveService::annualSummary((int) $e['id']);
                    $out[] = [$e['employee_code'], employee_name($e), $e['department'], $s['year'], $s['entitlement'], $s['bank_holidays'], $s['remaining'], $s['pending'], $s['available']];
                }
                return [['Employee ID', 'Name', 'Department', 'Leave year', 'Entitlement', 'Bank holidays', 'Remaining', 'Pending', 'Available'], $out];

            case 'leave_taken':
                $rows = DB::all("SELECT lr.*, lt.name AS type_name, e.employee_code, e.first_name, e.last_name, d.name AS department FROM leave_requests lr
                                   JOIN leave_types lt ON lt.id = lr.leave_type_id JOIN employees e ON e.id = lr.employee_id LEFT JOIN departments d ON d.id = e.department_id
                                  WHERE lr.status = 'approved' AND lr.start_date <= ? AND lr.end_date >= ? $dSql ORDER BY lr.start_date", array_merge([$f['to'], $f['from']], $dP));
                return [['Employee ID', 'Name', 'Department', 'Type', 'From', 'To', 'Days'], array_map(fn ($r) => [
                    $r['employee_code'], employee_name($r), $r['department'], $r['type_name'], uk_date($r['start_date']), uk_date($r['end_date']), (float) $r['days']], $rows)];

            case 'payslip_status':
                [$y, $m] = array_map('intval', explode('-', $f['month']));
                $first = sprintf('%04d-%02d-01', $y, $m);
                $out = [];
                foreach ($this->employees($f, true) as $e) {
                    if ($e['start_date'] > date('Y-m-t', strtotime($first)) || ($e['end_date'] && $e['end_date'] < $first)) {
                        continue;
                    }
                    $p = DB::one('SELECT status, published_at FROM payslips WHERE employee_id = ? AND period_year = ? AND period_month = ? AND is_current = 1', [$e['id'], $y, $m]);
                    $out[] = [$e['employee_code'], employee_name($e), $e['department'], month_name($m) . " $y", $p ? ucfirst($p['status']) : 'Missing', $p ? uk_datetime($p['published_at']) : ''];
                }
                return [['Employee ID', 'Name', 'Department', 'Period', 'Payslip', 'Published'], $out];
        }
        throw new HttpException(404);
    }
}
