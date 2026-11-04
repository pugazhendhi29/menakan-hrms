<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\DB;

/**
 * Figures for the manager dashboard, the Directors' dashboard and the team / department / company exports.
 * Every method takes the employees in scope, so a manager only ever sees their own part of the hierarchy.
 */
final class ReportStats
{
    /** Reports in scope for a period range: employee id => [period_start => report row]. */
    public static function reports(array $employeeIds, string $period, string $from, string $to): array
    {
        if (!$employeeIds) {
            return [];
        }
        $out = [];
        foreach (DB::all('SELECT id, employee_id, period_start, status, current_version, has_critical, is_late, is_backdated, due_at, report_no FROM reports
                           WHERE period = ? AND period_start BETWEEN ? AND ? AND employee_id IN (' . DB::placeholders($employeeIds) . ')',
            [$period, ReportPeriod::bounds($period, $from)[0], $to, ...$employeeIds]) as $r) {
            $out[(int) $r['employee_id']][$r['period_start']] = $r;
        }
        return $out;
    }

    /**
     * Reporting compliance per employee and overall.
     * @return array{rows: array<int,array>, totals: array}
     */
    public static function compliance(array $employeeIds, string $period, string $from, string $to): array
    {
        $expected = ReportPeriod::expected($employeeIds, $period, $from, $to);
        $reports = self::reports(array_keys($expected), $period, $from, $to);
        $now = now_utc();
        $rows = [];
        $t = ['expected' => 0, 'submitted' => 0, 'approved' => 0, 'awaiting' => 0, 'returned' => 0, 'overdue' => 0, 'late' => 0, 'outstanding' => 0];
        foreach ($expected as $id => $starts) {
            $c = ['expected' => count($starts), 'submitted' => 0, 'approved' => 0, 'awaiting' => 0, 'returned' => 0, 'overdue' => 0, 'late' => 0, 'outstanding' => 0];
            foreach ($starts as $s) {
                $r = $reports[$id][$s] ?? null;
                $submitted = $r && (int) $r['current_version'] > 0;
                if ($submitted) {
                    $c['submitted']++;
                    $c['late'] += (int) $r['is_late'];
                    $c['approved'] += in_array($r['status'], ReportAccess::APPROVED, true) ? 1 : 0;
                    $c['awaiting'] += in_array($r['status'], ReportAccess::AWAITING, true) ? 1 : 0;
                }
                if ($r && $r['status'] === 'returned') {
                    $c['returned']++;
                }
                if (!$submitted) {
                    $due = $r['due_at'] ?? ReportPeriod::dueAt($period, ReportPeriod::bounds($period, $s)[1]);
                    $c[$due < $now ? 'overdue' : 'outstanding']++;
                }
            }
            $c['rate'] = $c['expected'] ? (int) round($c['submitted'] / $c['expected'] * 100) : null;
            $rows[$id] = $c;
            foreach ($t as $k => $_) {
                $t[$k] += $c[$k];
            }
        }
        $t['rate'] = $t['expected'] ? (int) round($t['submitted'] / $t['expected'] * 100) : null;
        $t['people'] = count($expected);
        return ['rows' => $rows, 'totals' => $t];
    }

    /** Daily reports expected vs submitted for each of the last $days days up to $to. */
    public static function dailyTrend(array $employeeIds, string $to, int $days = 14): array
    {
        $from = date('Y-m-d', strtotime("$to -" . ($days - 1) . ' days'));
        $expected = ReportPeriod::expected($employeeIds, 'daily', $from, $to);
        $reports = self::reports(array_keys($expected), 'daily', $from, $to);
        $out = [];
        for ($d = $from; $d <= $to; $d = date('Y-m-d', strtotime("$d +1 day"))) {
            $out[$d] = ['expected' => 0, 'submitted' => 0, 'approved' => 0];
        }
        foreach ($expected as $id => $starts) {
            foreach ($starts as $s) {
                $out[$s]['expected']++;
                $r = $reports[$id][$s] ?? null;
                if ($r && (int) $r['current_version'] > 0) {
                    $out[$s]['submitted']++;
                    $out[$s]['approved'] += in_array($r['status'], ReportAccess::APPROVED, true) ? 1 : 0;
                }
            }
        }
        return $out;
    }

    /**
     * Performance areas: for each approved role (except Director), the KPI totals from approved reports
     * in the range, how many reports they come from, and how many are still awaiting approval.
     */
    public static function areas(?array $employeeIds, string $period, string $from, string $to): array
    {
        $scope = $employeeIds === null ? '' : ' AND r.employee_id IN (' . DB::placeholders($employeeIds ?: [0]) . ')';
        $rows = DB::all("SELECT r.id, r.status, t.role_code FROM reports r JOIN report_templates t ON t.id = r.template_id
                          WHERE r.period = ? AND r.period_start BETWEEN ? AND ? AND r.current_version > 0 $scope",
            [$period, ReportPeriod::bounds($period, $from)[0], $to, ...($employeeIds === null ? [] : ($employeeIds ?: [0]))]);
        $byRole = [];
        foreach ($rows as $r) {
            $byRole[$r['role_code']][in_array($r['status'], ReportAccess::APPROVED, true) ? 'approved' : 'pending'][] = (int) $r['id'];
        }
        $out = [];
        foreach (ReportCatalog::ROLES as $role => [$title, $area]) {
            if ($role === 'director') {
                continue;
            }
            $ids = $byRole[$role]['approved'] ?? [];
            $out[$role] = ['title' => $title, 'area' => $area, 'approved' => count($ids), 'pending' => count($byRole[$role]['pending'] ?? []),
                'kpis' => ReportService::kpiRollup($ids, $role, $period)];
        }
        return $out;
    }

    /** Critical issues raised in submitted reports since $since (newest first). */
    public static function critical(?array $employeeIds, string $since, int $limit = 20): array
    {
        $scope = $employeeIds === null ? '' : ' AND r.employee_id IN (' . DB::placeholders($employeeIds ?: [0]) . ')';
        return DB::all("SELECT r.id, r.report_no, r.period, r.period_start, r.status, r.critical_note, r.submitted_at, e.first_name, e.last_name, e.employee_code, j.title AS job_title
                          FROM reports r JOIN employees e ON e.id = r.employee_id LEFT JOIN job_titles j ON j.id = e.job_title_id
                         WHERE r.has_critical = 1 AND r.current_version > 0 AND r.period_start >= ? $scope ORDER BY r.submitted_at DESC LIMIT $limit",
            [$since, ...($employeeIds === null ? [] : ($employeeIds ?: [0]))]);
    }

    /** Open management actions assigned to people in scope. */
    public static function actions(?array $employeeIds, int $limit = 50): array
    {
        $scope = $employeeIds === null ? '' : ' AND a.assigned_to_employee_id IN (' . DB::placeholders($employeeIds ?: [0]) . ')';
        return DB::all("SELECT a.*, e.first_name, e.last_name, e.employee_code, r.report_no FROM management_actions a JOIN employees e ON e.id = a.assigned_to_employee_id
                          LEFT JOIN reports r ON r.id = a.report_id WHERE a.status IN ('open','in_progress') $scope
                         ORDER BY FIELD(a.priority,'critical','high','medium','low'), a.due_date IS NULL, a.due_date LIMIT $limit",
            $employeeIds === null ? [] : ($employeeIds ?: [0]));
    }

    /** Recommendation-type answers from monthly reports for a month (manager and Director reports first). */
    public static function recommendations(?array $employeeIds, string $monthStart, int $limit = 12): array
    {
        $scope = $employeeIds === null ? '' : ' AND r.employee_id IN (' . DB::placeholders($employeeIds ?: [0]) . ')';
        $rows = DB::all("SELECT r.id, r.report_no, r.employee_id, r.status, t.role_code, v.data, e.first_name, e.last_name, e.employee_code
                           FROM reports r JOIN report_templates t ON t.id = r.template_id JOIN employees e ON e.id = r.employee_id
                           JOIN report_versions v ON v.report_id = r.id AND v.version_no = r.current_version
                          WHERE r.period = 'monthly' AND r.period_start = ? $scope", [$monthStart, ...($employeeIds === null ? [] : ($employeeIds ?: [0]))]);
        $out = [];
        foreach ($rows as $r) {
            $data = json_decode((string) $r['data'], true) ?: [];
            $template = ReportService::templateFor($r['role_code'], 'monthly');
            foreach ($template['fields'] ?? [] as $f) {
                if (preg_match('/recommend|improvement_plans|next_month_priorities/', $f['field_key']) && trim((string) ($data['fields'][$f['field_key']] ?? '')) !== '') {
                    $out[] = ['report_id' => (int) $r['id'], 'report_no' => $r['report_no'], 'name' => employee_name($r), 'role' => ReportCatalog::ROLES[$r['role_code']][0],
                        'label' => $f['label'], 'text' => $data['fields'][$f['field_key']], 'manager' => ReportAccess::isManager((int) $r['employee_id'])];
                }
            }
            if (!empty($data['assessment'])) {
                $out[] = ['report_id' => (int) $r['id'], 'report_no' => $r['report_no'], 'name' => employee_name($r), 'role' => ReportCatalog::ROLES[$r['role_code']][0],
                    'label' => 'Management assessment', 'text' => $data['assessment'], 'manager' => true];
            }
        }
        usort($out, fn ($a, $b) => [$b['manager'], $a['name']] <=> [$a['manager'], $b['name']]);
        return array_slice($out, 0, $limit);
    }

    /** Compliance and issues by department. */
    public static function departments(?array $employeeIds, string $period, string $from, string $to): array
    {
        $people = ReportAccess::people();
        $ids = $employeeIds ?? array_keys($people);
        $comp = self::compliance($ids, $period, $from, $to)['rows'];
        $critical = [];
        foreach (DB::all('SELECT employee_id, COUNT(*) n FROM reports WHERE has_critical = 1 AND current_version > 0 AND period = ? AND period_start BETWEEN ? AND ? GROUP BY employee_id',
            [$period, ReportPeriod::bounds($period, $from)[0], $to]) as $c) {
            $critical[(int) $c['employee_id']] = (int) $c['n'];
        }
        $out = [];
        foreach ($comp as $id => $c) {
            $dept = (int) ($people[$id]['department_id'] ?? 0);
            $d = &$out[$dept];
            $d ??= ['id' => $dept ?: null, 'name' => $people[$id]['department'] ?? 'No department', 'people' => 0, 'expected' => 0, 'submitted' => 0, 'approved' => 0, 'overdue' => 0, 'awaiting' => 0, 'critical' => 0];
            $d['people']++;
            foreach (['expected', 'submitted', 'approved', 'overdue', 'awaiting'] as $k) {
                $d[$k] += $c[$k];
            }
            $d['critical'] += $critical[$id] ?? 0;
            unset($d);
        }
        foreach ($out as &$d) {
            $d['rate'] = $d['expected'] ? (int) round($d['submitted'] / $d['expected'] * 100) : null;
        }
        unset($d);
        uasort($out, fn ($a, $b) => strcmp($a['name'], $b['name']));
        return array_values($out);
    }

    /** Employees in scope who have an approved role but no active login (they cannot report). */
    public static function withoutAccount(array $employeeIds): array
    {
        $withRole = array_values(array_filter($employeeIds, fn ($id) => ReportAccess::roleOf($id) !== null));
        if (!$withRole) {
            return [];
        }
        $have = array_map('intval', DB::column('SELECT employee_id FROM users WHERE is_active = 1 AND employee_id IN (' . DB::placeholders($withRole) . ')', $withRole));
        return array_values(array_diff($withRole, $have));
    }
}
