<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\DB;
use App\Core\Request;
use App\Core\Response;
use App\Services\AttendanceService;
use App\Services\DocumentService;
use App\Services\HoursCalculator;
use App\Services\WeekBoundary;

final class DashboardController extends Controller
{
    public function home(Request $request): Response
    {
        return redirect(Auth::isAdmin() ? '/dashboard' : '/my');
    }

    public function admin(Request $request): Response
    {
        AttendanceService::flagIncomplete();
        $dept = $request->int('department_id');
        $today = uk_today();
        $week = $request->str('week');
        $week = \App\Core\Validator::isDate($week) ? WeekBoundary::weekStartForDate($week) : WeekBoundary::weekStartForDate($today);
        $deptSql = $dept ? ' AND e.department_id = ' . (int) $dept : '';

        $headcount = DB::one("SELECT COUNT(*) AS total, SUM(status IN ('active','probation','on_leave')) AS active FROM employees e WHERE deleted_at IS NULL AND status <> 'left' $deptSql");
        [$dayStart, $dayEnd] = WeekBoundary::utcRangeForDates($today, $today);
        $rostered = (int) DB::value("SELECT COUNT(DISTINCT s.employee_id) FROM shifts s JOIN employees e ON e.id = s.employee_id WHERE s.status = 'published' AND s.start_at >= ? AND s.start_at < ? $deptSql", [$dayStart, $dayEnd]);
        $present = (int) DB::value("SELECT COUNT(DISTINCT a.employee_id) FROM attendance_records a JOIN employees e ON e.id = a.employee_id WHERE a.work_date = ? $deptSql", [$today]);
        $absences = AttendanceService::absences($today, $today, null, $dept);
        $absentToday = count(array_filter($absences, fn ($a) => $a['day_status'] === 'absent'));
        $overLimit = HoursCalculator::overLimitForWeek($week, $dept);
        $pendingLeave = DB::all("SELECT lr.*, lt.name AS type_name, e.employee_code, e.first_name, e.last_name FROM leave_requests lr
                                   JOIN leave_types lt ON lt.id = lr.leave_type_id JOIN employees e ON e.id = lr.employee_id
                                  WHERE lr.status = 'pending' $deptSql ORDER BY lr.start_date LIMIT 6");
        $pendingCount = (int) DB::value("SELECT COUNT(*) FROM leave_requests lr JOIN employees e ON e.id = lr.employee_id WHERE lr.status = 'pending' $deptSql");
        $warnDays = (int) setting('document_expiry_warning_days', 30);
        $expiring = DocumentService::expiring($warnDays);
        if ($dept) {
            $deptName = DB::value('SELECT name FROM departments WHERE id = ?', [$dept]);
            $expiring = array_values(array_filter($expiring, fn ($d) => $d['department'] === $deptName));
        }
        $missingDocs = $this->missingDocuments($dept);
        $incomplete = (int) DB::value("SELECT COUNT(*) FROM attendance_records a JOIN employees e ON e.id = a.employee_id WHERE a.status = 'incomplete' AND a.work_date >= ? $deptSql", [date('Y-m-d', strtotime($today . ' -14 days'))]);

        // Attendance for the last 7 days: distinct employees with a record vs rostered.
        $labels = $presentSeries = $rosterSeries = [];
        for ($i = 6; $i >= 0; $i--) {
            $d = date('Y-m-d', strtotime("$today -$i days"));
            [$s, $e] = WeekBoundary::utcRangeForDates($d, $d);
            $labels[] = $i === 0 ? 'Today' : date('D d/m', strtotime($d));
            $presentSeries[] = (int) DB::value("SELECT COUNT(DISTINCT a.employee_id) FROM attendance_records a JOIN employees e ON e.id = a.employee_id WHERE a.work_date = ? $deptSql", [$d]);
            $rosterSeries[] = (int) DB::value("SELECT COUNT(DISTINCT s.employee_id) FROM shifts s JOIN employees e ON e.id = s.employee_id WHERE s.status = 'published' AND s.start_at >= ? AND s.start_at < ? $deptSql", [$s, $e]);
        }
        $chart = [
            'type' => 'bar',
            'data' => ['labels' => $labels, 'datasets' => [
                ['label' => 'Rostered', 'data' => $rosterSeries, 'backgroundColor' => '#c9d5e4', 'borderRadius' => 3],
                ['label' => 'Attended', 'data' => $presentSeries, 'backgroundColor' => '#1b3150', 'borderRadius' => 3],
            ]],
            'options' => ['responsive' => true, 'maintainAspectRatio' => false, 'plugins' => ['legend' => ['position' => 'bottom']],
                'scales' => ['y' => ['beginAtZero' => true, 'ticks' => ['precision' => 0]]]],
        ];
        $byDept = DB::all("SELECT d.name, COUNT(e.id) AS n FROM departments d LEFT JOIN employees e ON e.department_id = d.id AND e.deleted_at IS NULL AND e.status <> 'left' GROUP BY d.id ORDER BY d.name");

        return view('dashboard/admin', [
            'title' => 'Dashboard', 'charts' => true, 'departments' => $this->departments(), 'dept' => $dept, 'week' => $week,
            'headcount' => $headcount, 'rostered' => $rostered, 'present' => $present, 'absentToday' => $absentToday, 'absences' => $absences,
            'overLimit' => $overLimit, 'pendingLeave' => $pendingLeave, 'pendingCount' => $pendingCount, 'expiring' => $expiring,
            'missingDocs' => $missingDocs, 'incomplete' => $incomplete, 'chart' => $chart, 'byDept' => $byDept, 'warnDays' => $warnDays,
        ]);
    }

    private function missingDocuments(?int $dept): int
    {
        $n = 0;
        $sql = "SELECT * FROM employees WHERE deleted_at IS NULL AND status <> 'left'" . ($dept ? ' AND department_id = ' . (int) $dept : '');
        foreach (DB::all($sql) as $e) {
            $n += DocumentService::compliance($e)['missing'];
        }
        return $n;
    }
}
