<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\DB;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Services\OrgChart;
use App\Services\ReportAccess;
use App\Services\ReportCatalog;
use App\Services\ReportPeriod;
use App\Services\ReportService;
use App\Services\ReportStats;

/** Reporting dashboards (employee, manager, Directors), the reporting hierarchy, report history and templates. */
final class ReportingDashboardController extends Controller
{
    private function period(Request $request, string $default = 'daily'): string
    {
        $p = $request->str('period', $default);
        return isset(ReportCatalog::PERIODS[$p]) ? $p : $default;
    }

    private function date(Request $request): string
    {
        $d = $request->str('date');
        return Validator::isDate($d) && $d <= uk_today() ? $d : uk_today();
    }

    public function home(Request $request): Response
    {
        $me = Auth::employeeId();
        if ($me !== null && ReportAccess::roleOf($me) !== null) {
            return redirect('/reporting/my');
        }
        return redirect(ReportAccess::seesCompany() ? '/reporting/directors' : '/reporting/reports');
    }

    /** Employee dashboard: my Daily / Weekly / Monthly reports. */
    public function my(Request $request): Response
    {
        $me = Auth::employeeId() ?? throw new HttpException(403, 'Your account is not linked to an employee record.');
        $period = $this->period($request);
        $role = ReportAccess::roleOf($me);
        $person = ReportAccess::person($me);
        $count = ['daily' => 14, 'weekly' => 8, 'monthly' => 6][$period];
        $current = ReportPeriod::bounds($period, uk_today())[0];
        $from = ReportPeriod::shift($period, $current, -($count - 1));
        $firstDay = ReportPeriod::firstDay($person['start_date']);
        if ($firstDay < $from) {
            $from = ReportPeriod::bounds($period, $firstDay)[0];   // every period worked since the start date (from 01/06/2026) needs its report
        }
        $expected = $role ? (ReportPeriod::expected([$me], $period, $from, uk_today())[$me] ?? []) : [];
        $reports = ReportStats::reports([$me], $period, $from, uk_today())[$me] ?? [];
        $rows = [];
        for ($s = $current; $s >= $from; $s = ReportPeriod::shift($period, $s, -1)) {
            $r = $reports[$s] ?? null;
            $isExpected = in_array($s, $expected, true);
            if (!$r && !$isExpected && $s !== $current) {
                continue;
            }
            $due = $r['due_at'] ?? ReportPeriod::dueAt($period, ReportPeriod::bounds($period, $s)[1]);
            $rows[] = ['start' => $s, 'report' => $r, 'expected' => $isExpected, 'due' => $due, 'late' => !$r || (int) $r['current_version'] === 0 ? $due < now_utc() : false];
        }
        $month = ReportPeriod::bounds('monthly', uk_today());
        $stats = DB::one("SELECT SUM(current_version > 0) AS submitted, SUM(status IN ('approved','consolidated','forwarded','completed')) AS approved,
                                 SUM(status = 'returned') AS returned, SUM(status = 'overdue') AS overdue
                            FROM reports WHERE employee_id = ? AND period_start BETWEEN ? AND ?", [$me, $month[0], $month[1]]);
        return view('reporting/my', [
            'title' => 'My Reports', 'crumb' => employee_name($person) . ' · ' . ($person['job_title'] ?? 'No job title') . ' · reports to '
                . (($m = ReportAccess::managerOf($me)) ? employee_name(ReportAccess::person($m)) : (ReportAccess::isDirector($me) ? 'no one (Director)' : 'no line manager set')),
            'period' => $period, 'role' => $role, 'rows' => $rows, 'stats' => $stats, 'current' => $current,
            'tpl' => $role ? ReportService::templateFor($role, $period) : null,
            'returned' => DB::all("SELECT id, report_no, period, period_start FROM reports WHERE employee_id = ? AND status IN ('returned','overdue') ORDER BY period_start DESC LIMIT 10", [$me]),
            'actions' => ReportStats::actions([$me], 10), 'isManager' => ReportAccess::isManager($me), 'startDate' => $firstDay, 'canBackdate' => Auth::canBackdateReports(),
            'manager' => ($m = ReportAccess::managerOf($me)) ? ReportAccess::person($m) : null,
        ]);
    }

    /** Manager dashboard: the people who report to me (directly, or at every level). */
    public function team(Request $request): Response
    {
        $me = Auth::employeeId();
        $managerId = $me;
        if (ReportAccess::seesCompany() && $request->int('manager_id')) {
            $managerId = $request->int('manager_id');
        }
        if ($managerId === null || !ReportAccess::isManager($managerId)) {
            if (ReportAccess::seesCompany()) {
                return redirect('/reporting/directors');
            }
            throw new HttpException(403, 'The team dashboard is for managers: no one reports to you.');
        }
        $period = $this->period($request);
        $date = $this->date($request);
        $scope = $request->str('scope') === 'all' ? 'all' : 'direct';
        $team = $scope === 'all' ? ReportAccess::subordinates($managerId) : ReportAccess::directReports($managerId);
        [$start, $end] = ReportPeriod::bounds($period, $date);
        $today = uk_today();
        $week = ReportPeriod::bounds('weekly', $date);
        $month = ReportPeriod::bounds('monthly', $date);

        $comp = ReportStats::compliance($team, $period, $start, $end);
        $reports = ReportStats::reports($team, $period, $start, $end);
        $members = [];
        foreach ($team as $id) {
            $p = ReportAccess::person($id);
            $r = $reports[$id][$start] ?? null;
            $members[] = ['id' => $id, 'person' => $p, 'role' => ReportAccess::roleOf($id), 'report' => $r, 'manager' => ReportAccess::managerOf($id),
                'expected' => isset($comp['rows'][$id]) && $comp['rows'][$id]['expected'] > 0, 'comp' => $comp['rows'][$id] ?? null];
        }
        usort($members, fn ($a, $b) => strcmp(employee_name($a['person']), employee_name($b['person'])));

        $awaiting = DB::all(ReportService::select() . ' WHERE r.status IN (' . DB::placeholders(ReportAccess::AWAITING) . ') AND COALESCE(r.escalated_to_employee_id, r.line_manager_id) = ? ORDER BY r.submitted_at',
            [...ReportAccess::AWAITING, $managerId]);
        $returned = $team ? DB::all(ReportService::select() . " WHERE r.status = 'returned' AND r.employee_id IN (" . DB::placeholders($team) . ') ORDER BY r.decided_at DESC LIMIT 20', $team) : [];
        $mine = DB::all(ReportService::select() . ' WHERE r.employee_id = ? AND r.current_version > 0 ORDER BY r.submitted_at DESC LIMIT 8', [$managerId]);

        // Weekly performance summary per employee, from approved daily reports of the week.
        $weekly = [];
        foreach ($members as $m) {
            if (!$m['role']) {
                continue;
            }
            $ids = array_map('intval', DB::column("SELECT id FROM reports WHERE employee_id = ? AND period = 'daily' AND period_start BETWEEN ? AND ? AND status IN ('approved','consolidated','forwarded','completed')",
                [$m['id'], $week[0], $week[1]]));
            $c = ReportStats::compliance([$m['id']], 'daily', $week[0], min($week[1], $today))['rows'][$m['id']] ?? null;
            $weekly[] = ['person' => $m['person'], 'role' => $m['role'], 'comp' => $c, 'kpis' => array_slice(array_filter(ReportService::kpiRollup($ids, $m['role'], 'daily'), fn ($k) => $k['value'] !== null), 0, 4),
                'weekly' => DB::one("SELECT id, report_no, status FROM reports WHERE employee_id = ? AND period = 'weekly' AND period_start = ?", [$m['id'], $week[0]])];
        }

        $trend = ReportStats::dailyTrend($team, $today, 14);
        $chart = [
            'type' => 'bar',
            'data' => ['labels' => array_map(fn ($d) => date('D d/m', strtotime($d)), array_keys($trend)), 'datasets' => [
                ['label' => 'Expected', 'data' => array_column($trend, 'expected'), 'backgroundColor' => '#c7d6f2', 'borderRadius' => 6],
                ['label' => 'Submitted', 'data' => array_column($trend, 'submitted'), 'backgroundColor' => '#2563c9', 'borderRadius' => 6],
                ['label' => 'Approved', 'data' => array_column($trend, 'approved'), 'backgroundColor' => '#15966a', 'borderRadius' => 6],
            ]],
            'options' => ['responsive' => true, 'maintainAspectRatio' => false, 'plugins' => ['legend' => ['position' => 'top']],
                'scales' => ['x' => ['grid' => ['display' => false]], 'y' => ['beginAtZero' => true, 'ticks' => ['precision' => 0]]]],
        ];
        $counts = [
            'daily_today' => ReportStats::compliance($team, 'daily', $today, $today)['totals'],
            'weekly' => ReportStats::compliance($team, 'weekly', $week[0], $week[0])['totals'],
            'monthly' => ReportStats::compliance($team, 'monthly', $month[0], $month[0])['totals'],
        ];
        $managers = ReportAccess::seesCompany() ? array_filter(ReportAccess::people(), fn ($p, $id) => ReportAccess::isManager($id), ARRAY_FILTER_USE_BOTH) : [];
        return view('reporting/team', [
            'title' => $managerId === $me ? 'My Team Reports' : 'Team Reports · ' . employee_name(ReportAccess::person($managerId)), 'crumb' => 'Reporting · manager dashboard',
            'charts' => true, 'chart' => $chart, 'period' => $period, 'date' => $date, 'start' => $start, 'end' => $end, 'scope' => $scope,
            'members' => $members, 'comp' => $comp, 'awaiting' => $awaiting, 'returned' => $returned, 'mine' => $mine, 'weekly' => $weekly, 'week' => $week,
            'areas' => array_filter(ReportStats::areas($team, $period, $start, $end), fn ($a) => $a['approved'] + $a['pending'] > 0),
            'actions' => ReportStats::actions([...$team, $managerId], 20), 'counts' => $counts, 'managerId' => $managerId, 'managers' => $managers,
            'critical' => ReportStats::critical($team, date('Y-m-d', strtotime("$today -30 days")), 10),
            'noAccount' => ReportStats::withoutAccount($team),
        ]);
    }

    /** Directors' dashboard: the whole company, with drill-down to departments and individual reports. */
    public function directors(Request $request): Response
    {
        if (!ReportAccess::seesCompany()) {
            throw new HttpException(403, "The Directors' dashboard is for Directors and HR administrators.");
        }
        $period = $this->period($request, 'weekly');
        $date = $this->date($request);
        $dept = $request->int('department_id');
        [$start, $end] = ReportPeriod::bounds($period, $date);
        $all = array_keys(ReportAccess::people());
        $ids = $dept ? array_values(array_filter($all, fn ($id) => (int) ReportAccess::person($id)['department_id'] === $dept)) : $all;
        $scope = $dept ? $ids : null;
        $today = uk_today();
        $comp = ReportStats::compliance($ids, $period, $start, $end);
        $statusCounts = [];
        foreach (DB::all('SELECT status, COUNT(*) n FROM reports WHERE period = ? AND period_start BETWEEN ? AND ?' . ($scope !== null ? ' AND employee_id IN (' . DB::placeholders($ids ?: [0]) . ')' : '') . ' GROUP BY status',
            [$period, $start, $end, ...($scope !== null ? ($ids ?: [0]) : [])]) as $s) {
            $statusCounts[$s['status']] = (int) $s['n'];
        }
        $trend = ReportStats::dailyTrend($ids, min($end, $today), 14);
        $colours = ['draft' => '#94a3b8', 'submitted' => '#38bdf8', 'under_review' => '#2563c9', 'returned' => '#f59e0b', 'approved' => '#22c55e', 'consolidated' => '#16a34a',
            'forwarded' => '#15966a', 'escalated' => '#dc3545', 'overdue' => '#b91c1c', 'completed' => '#14283f'];
        $charts = [
            'trend' => ['type' => 'line', 'data' => ['labels' => array_map(fn ($d) => date('D d/m', strtotime($d)), array_keys($trend)), 'datasets' => [
                ['label' => 'Daily reports expected', 'data' => array_column($trend, 'expected'), 'borderColor' => '#94a3b8', 'tension' => .35, 'borderWidth' => 2, 'pointRadius' => 3],
                ['label' => 'Submitted', 'data' => array_column($trend, 'submitted'), 'borderColor' => '#2563c9', 'fillGradient' => '#2563c9', 'tension' => .35, 'borderWidth' => 2.5, 'pointRadius' => 3],
                ['label' => 'Approved', 'data' => array_column($trend, 'approved'), 'borderColor' => '#15966a', 'tension' => .35, 'borderWidth' => 2, 'pointRadius' => 3],
            ]], 'options' => ['responsive' => true, 'maintainAspectRatio' => false, 'interaction' => ['mode' => 'index', 'intersect' => false],
                'scales' => ['x' => ['grid' => ['display' => false]], 'y' => ['beginAtZero' => true, 'ticks' => ['precision' => 0]]]]],
            'status' => ['type' => 'doughnut', 'data' => ['labels' => array_map(fn ($s) => ReportService::STATUSES[$s], array_keys($statusCounts)), 'datasets' => [
                ['data' => array_values($statusCounts), 'backgroundColor' => array_map(fn ($s) => $colours[$s], array_keys($statusCounts)), 'borderWidth' => 0]]],
                'options' => ['responsive' => true, 'maintainAspectRatio' => false, 'cutout' => '62%', 'plugins' => ['legend' => ['position' => 'right']]]],
        ];
        $managerReports = DB::all(ReportService::select() . ' WHERE r.period = ? AND r.period_start BETWEEN ? AND ? AND r.current_version > 0'
            . ($scope !== null ? ' AND r.employee_id IN (' . DB::placeholders($ids ?: [0]) . ')' : '') . ' ORDER BY r.submitted_at DESC', [$period, $start, $end, ...($scope !== null ? ($ids ?: [0]) : [])]);
        $managerReports = array_values(array_filter($managerReports, fn ($r) => ReportAccess::isManager((int) $r['employee_id'])));
        $lowest = [];
        foreach ($comp['rows'] as $id => $c) {
            if ($c['expected'] > 0 && $c['submitted'] < $c['expected']) {
                $lowest[] = ['person' => ReportAccess::person($id), 'c' => $c];
            }
        }
        usort($lowest, fn ($a, $b) => [$a['c']['rate'], employee_name($a['person'])] <=> [$b['c']['rate'], employee_name($b['person'])]);
        return view('reporting/directors', [
            'title' => "Directors' Dashboard", 'crumb' => 'Reporting · company performance · ' . ReportPeriod::label($period, $start), 'charts' => true, 'chartData' => $charts,
            'period' => $period, 'date' => $date, 'start' => $start, 'end' => $end, 'dept' => $dept, 'departments' => $this->departments(),
            'comp' => $comp, 'areas' => ReportStats::areas($scope, $period, $start, $end), 'deptRows' => ReportStats::departments($scope, $period, $start, $end),
            'critical' => ReportStats::critical($scope, date('Y-m-d', strtotime("$today -30 days")), 12), 'actions' => ReportStats::actions($scope, 15),
            'recommendations' => ReportStats::recommendations($scope, ReportPeriod::bounds('monthly', $date)[0]), 'managerReports' => array_slice($managerReports, 0, 15),
            'lowest' => array_slice($lowest, 0, 10), 'statusCounts' => $statusCounts,
            'awaiting' => (int) DB::value('SELECT COUNT(*) FROM reports WHERE status IN (' . DB::placeholders(ReportAccess::AWAITING) . ')' . ($scope !== null ? ' AND employee_id IN (' . DB::placeholders($ids ?: [0]) . ')' : ''),
                [...ReportAccess::AWAITING, ...($scope !== null ? ($ids ?: [0]) : [])]),
            'escalated' => (int) DB::value("SELECT COUNT(*) FROM reports WHERE status = 'escalated'" . ($scope !== null ? ' AND employee_id IN (' . DB::placeholders($ids ?: [0]) . ')' : ''), $scope !== null ? ($ids ?: [0]) : []),
        ]);
    }

    /** The reporting hierarchy: the real reporting lines, with each person's report status for a period. */
    public function hierarchy(Request $request): Response
    {
        $period = $this->period($request);
        $date = $this->date($request);
        [$start] = ReportPeriod::bounds($period, $date);
        $people = OrgChart::people();
        $tree = OrgChart::build($people);
        $byId = array_column($people, null, 'id');
        $visible = ReportAccess::visibleEmployeeIds();
        $ids = array_map('intval', array_keys($byId));
        $reports = ReportStats::reports($ids, $period, $start, $start);
        $expected = ReportPeriod::expected($ids, $period, $start, $start);
        $status = [];
        foreach ($ids as $id) {
            if ($visible !== null && !in_array($id, $visible, true)) {
                continue;
            }
            $r = $reports[$id][$start] ?? null;
            $status[$id] = $r ? ['id' => (int) $r['id'], 'status' => $r['status'], 'critical' => (bool) $r['has_critical']]
                : (in_array($start, $expected[$id] ?? [], true) ? ['id' => null, 'status' => 'missing', 'critical' => false] : null);
        }
        $unassigned = array_values(array_filter($people, fn ($p) => $p['line_manager_id'] === null && !OrgChart::isDirector($p['designation'])));
        $unapproved = array_values(array_filter($people, fn ($p) => ReportAccess::roleOf((int) $p['id']) === null));
        return view('reporting/hierarchy', [
            'title' => 'Reporting Hierarchy', 'crumb' => 'Reporting · who reports to whom · ' . ReportPeriod::label($period, $start),
            'period' => $period, 'date' => $date, 'start' => $start, 'tree' => $tree, 'byId' => $byId, 'status' => $status,
            'unassigned' => $unassigned, 'unapproved' => $unapproved, 'isAdmin' => Auth::isAdmin(), 'me' => Auth::employeeId(),
        ]);
    }

    /** Report history: search and filter every report the current user may see. */
    public function search(Request $request): Response
    {
        [$where, $params, $filters] = $this->searchWhere($request);
        $total = (int) DB::value("SELECT COUNT(*) FROM reports r JOIN employees e ON e.id = r.employee_id WHERE $where", $params);
        $pg = paginate($total, max(1, (int) $request->int('page', 1)));
        $rows = DB::all(ReportService::select() . " WHERE $where ORDER BY r.period_start DESC, r.id DESC LIMIT {$pg['per_page']} OFFSET {$pg['offset']}", $params);
        $visible = ReportAccess::visibleEmployeeIds();
        $employees = array_filter(ReportAccess::people(), fn ($p, $id) => $visible === null || in_array($id, $visible, true), ARRAY_FILTER_USE_BOTH);
        uasort($employees, fn ($a, $b) => strcmp(employee_name($a), employee_name($b)));
        return view('reporting/search', ['title' => 'Report History', 'crumb' => 'Reporting · search and filter reports', 'rows' => $rows, 'pg' => $pg, 'filters' => $filters,
            'departments' => $this->departments(), 'employees' => $employees, 'request' => $request]);
    }

    /** Search results as an Excel workbook. */
    public function searchExport(Request $request): Response
    {
        [$where, $params] = $this->searchWhere($request);
        $rows = DB::all(ReportService::select() . " WHERE $where ORDER BY r.period_start DESC, r.id DESC LIMIT 5000", $params);
        \App\Services\AuditLogger::log('report.exported', 'report', null, 'Exported report history (' . count($rows) . ' reports) to Excel');
        return $this->exportTable('xlsx', 'Report-History', ['Report ID', 'Period', 'Period start', 'Period end', 'Employee ID', 'Employee', 'Job title', 'Department', 'Line manager', 'Status', 'Version', 'Submitted', 'Late', 'Critical issue'],
            array_map(fn ($r) => [$r['report_no'], ReportCatalog::PERIODS[$r['period']], uk_date($r['period_start']), uk_date($r['period_end']), $r['employee_code'], employee_name($r), $r['job_title'], $r['department'],
                trim($r['m_first_name'] . ' ' . $r['m_last_name']), ReportService::STATUSES[$r['status']], (int) $r['current_version'], uk_datetime($r['submitted_at']), $r['is_late'] ? 'Yes' : 'No', (string) $r['critical_note']], $rows));
    }

    private function searchWhere(Request $request): array
    {
        $where = ['1=1'];
        $params = [];
        $visible = ReportAccess::visibleEmployeeIds();
        if ($visible !== null) {
            $where[] = 'r.employee_id IN (' . DB::placeholders($visible ?: [0]) . ')';
            array_push($params, ...($visible ?: [0]));
        }
        $f = ['q' => $request->str('q'), 'period' => $request->str('period'), 'status' => $request->str('status'), 'department_id' => $request->int('department_id'),
            'employee_id' => $request->int('employee_id'), 'role' => $request->str('role'), 'from' => $request->str('from'), 'to' => $request->str('to'), 'critical' => $request->bool('critical')];
        if ($f['q'] !== '') {
            $where[] = "(r.report_no LIKE ? OR e.employee_code LIKE ? OR CONCAT(e.first_name, ' ', e.last_name) LIKE ?)";
            $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $f['q']) . '%';
            array_push($params, $like, $like, $like);
        }
        if (isset(ReportCatalog::PERIODS[$f['period']])) {
            $where[] = 'r.period = ?';
            $params[] = $f['period'];
        }
        if (isset(ReportService::STATUSES[$f['status']])) {
            $where[] = 'r.status = ?';
            $params[] = $f['status'];
        } elseif ($f['status'] === 'awaiting') {
            $where[] = 'r.status IN (' . DB::placeholders(ReportAccess::AWAITING) . ')';
            array_push($params, ...ReportAccess::AWAITING);
        }
        if ($f['department_id']) {
            $where[] = 'COALESCE(r.department_id, e.department_id) = ?';
            $params[] = $f['department_id'];
        }
        if ($f['employee_id']) {
            $where[] = 'r.employee_id = ?';
            $params[] = $f['employee_id'];
        }
        if (isset(ReportCatalog::ROLES[$f['role']])) {
            $where[] = 'r.template_id IN (SELECT id FROM report_templates WHERE role_code = ?)';
            $params[] = $f['role'];
        }
        if (Validator::isDate($f['from'])) {
            $where[] = 'r.period_end >= ?';
            $params[] = $f['from'];
        }
        if (Validator::isDate($f['to'])) {
            $where[] = 'r.period_start <= ?';
            $params[] = $f['to'];
        }
        if ($f['critical']) {
            $where[] = 'r.has_critical = 1';
        }
        return [implode(' AND ', $where), $params, $f];
    }

    /** The 36 reporting templates (12 approved job titles x Daily, Weekly, Monthly). */
    public function templates(Request $request): Response
    {
        $rows = DB::all("SELECT t.*, COUNT(f.id) AS fields, SUM(f.section = 'role') AS role_fields, SUM(f.field_type = 'calc') AS calculated, SUM(f.is_kpi) AS kpis,
                                (SELECT COUNT(*) FROM job_titles j JOIN employees e ON e.job_title_id = j.id AND e.deleted_at IS NULL AND e.status <> 'left' WHERE j.report_role = t.role_code) AS people
                           FROM report_templates t LEFT JOIN report_template_fields f ON f.template_id = t.id WHERE t.is_active = 1 GROUP BY t.id");
        $grid = [];
        foreach ($rows as $r) {
            $grid[$r['role_code']][$r['period']] = $r;
        }
        return view('reporting/templates', ['title' => 'Reporting Templates', 'crumb' => 'Reporting · 12 approved job titles × Daily, Weekly and Monthly', 'grid' => $grid]);
    }

    public function template(Request $request, int $id): Response
    {
        $this->findOr404('SELECT id FROM report_templates WHERE id = ?', [$id]);
        $t = ReportService::template($id);
        return view('reporting/template', ['title' => $t['title'], 'crumb' => 'Reporting · Templates', 't' => $t,
            'siblings' => DB::all('SELECT id, period FROM report_templates WHERE role_code = ? AND is_active = 1', [$t['role_code']])]);
    }
}
