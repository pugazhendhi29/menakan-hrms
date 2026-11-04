<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\DB;
use App\Core\ValidationException;

/**
 * Management reports: one per employee per Daily / Weekly / Monthly period, written on the template of the
 * employee's approved job title, and passed up the real reporting lines:
 *
 *   employee submits -> their line manager reviews (approve / return for correction / escalate, with a comment)
 *   -> the manager's own report for the same period consolidates the approved team reports and adds the
 *   manager's assessment -> it goes to their line manager -> ... -> a Director, where the chain is completed.
 *
 * Submitted content is never changed: every submission is frozen in report_versions (version 1 = original,
 * later versions = amendments with a reason). Every action is written to the audit log.
 */
final class ReportService
{
    public const STATUSES = [
        'draft' => 'Draft', 'submitted' => 'Submitted', 'under_review' => 'Under Review', 'returned' => 'Returned for Correction',
        'approved' => 'Approved', 'consolidated' => 'Consolidated', 'forwarded' => 'Forwarded to Next Manager', 'escalated' => 'Escalated',
        'overdue' => 'Overdue', 'completed' => 'Completed',
    ];
    public const STATUS_TONE = [
        'draft' => 'secondary', 'submitted' => 'info', 'under_review' => 'primary', 'returned' => 'warning', 'approved' => 'success',
        'consolidated' => 'success', 'forwarded' => 'success', 'escalated' => 'danger', 'overdue' => 'danger', 'completed' => 'dark',
    ];
    public const MAX_ATTACHMENTS = 10;
    public const ATTACHMENT_TYPES = ['pdf', 'jpg', 'jpeg', 'png', 'xlsx'];

    // ------------------------------------------------------------------ Templates

    public static function template(int $id): array
    {
        static $cache = [];
        if (!isset($cache[$id])) {
            $t = DB::one('SELECT * FROM report_templates WHERE id = ?', [$id]) ?? throw new \RuntimeException("Template $id not found");
            $t['fields'] = DB::all('SELECT * FROM report_template_fields WHERE template_id = ? ORDER BY sort_order', [$id]);
            $cache[$id] = $t;
        }
        return $cache[$id];
    }

    public static function templateFor(string $role, string $period): ?array
    {
        $id = DB::value('SELECT id FROM report_templates WHERE role_code = ? AND period = ? AND is_active = 1 ORDER BY version DESC LIMIT 1', [$role, $period]);
        return $id ? self::template((int) $id) : null;
    }

    // ------------------------------------------------------------------ Reading

    public static function find(int $id): ?array
    {
        return DB::one(self::select() . ' WHERE r.id = ?', [$id]);
    }

    public static function select(): string
    {
        return "SELECT r.*, t.title AS template_title, t.role_code, e.employee_code, e.first_name, e.last_name, e.photo_key, e.department_id AS current_department_id,
                       COALESCE(j.title, cj.title) AS job_title, COALESCE(d.name, cd.name) AS department,
                       m.first_name AS m_first_name, m.last_name AS m_last_name, m.employee_code AS m_code,
                       x.first_name AS x_first_name, x.last_name AS x_last_name
                  FROM reports r
                  JOIN report_templates t ON t.id = r.template_id
                  JOIN employees e ON e.id = r.employee_id
                  LEFT JOIN job_titles j ON j.id = r.job_title_id LEFT JOIN job_titles cj ON cj.id = e.job_title_id
                  LEFT JOIN departments d ON d.id = r.department_id LEFT JOIN departments cd ON cd.id = e.department_id
                  LEFT JOIN employees m ON m.id = COALESCE(r.line_manager_id, e.line_manager_id)
                  LEFT JOIN employees x ON x.id = r.escalated_to_employee_id";
    }

    public static function forPeriod(int $employeeId, string $period, string $start): ?array
    {
        $id = DB::value('SELECT id FROM reports WHERE employee_id = ? AND period = ? AND period_start = ?', [$employeeId, $period, $start]);
        return $id ? self::find((int) $id) : null;
    }

    /** The working copy (what the form shows). */
    public static function data(array $report): array
    {
        $d = $report['data'] ? (json_decode((string) $report['data'], true) ?: []) : [];
        return $d + ['fields' => [], 'critical' => 0, 'critical_note' => '', 'assessment' => '', 'team_rating' => null];
    }

    public static function version(int $reportId, ?int $no = null): ?array
    {
        $v = $no === null
            ? DB::one('SELECT * FROM report_versions WHERE report_id = ? ORDER BY version_no DESC LIMIT 1', [$reportId])
            : DB::one('SELECT * FROM report_versions WHERE report_id = ? AND version_no = ?', [$reportId, $no]);
        if ($v) {
            $v['data'] = json_decode((string) $v['data'], true) ?: [];
            $v['consolidation'] = $v['consolidation'] ? json_decode((string) $v['consolidation'], true) : null;
        }
        return $v;
    }

    public static function versions(int $reportId): array
    {
        return DB::all('SELECT v.id, v.version_no, v.amendment_note, v.submitted_at, u.username FROM report_versions v LEFT JOIN users u ON u.id = v.submitted_by
                         WHERE v.report_id = ? ORDER BY v.version_no', [$reportId]);
    }

    public static function reviews(int $reportId): array
    {
        return DB::all('SELECT rv.*, e.first_name, e.last_name, e.employee_code, u.username FROM report_reviews rv LEFT JOIN employees e ON e.id = rv.reviewer_employee_id
                         LEFT JOIN users u ON u.id = rv.reviewer_user_id WHERE rv.report_id = ? ORDER BY rv.created_at, rv.id', [$reportId]);
    }

    public static function attachments(int $reportId): array
    {
        return DB::all('SELECT * FROM report_attachments WHERE report_id = ? ORDER BY created_at, id', [$reportId]);
    }

    public static function children(int $reportId): array
    {
        return DB::all(self::select() . ' JOIN report_consolidations c ON c.child_report_id = r.id WHERE c.parent_report_id = ? ORDER BY e.first_name, e.last_name', [$reportId]);
    }

    public static function parents(int $reportId): array
    {
        return DB::all(self::select() . ' JOIN report_consolidations c ON c.parent_report_id = r.id WHERE c.child_report_id = ?', [$reportId]);
    }

    /** Latest approval/return decision on a report: [comment, rating, action]. */
    public static function lastDecision(int $reportId): ?array
    {
        return DB::one("SELECT action, comment, rating, created_at FROM report_reviews WHERE report_id = ? AND action IN ('approved','returned') ORDER BY created_at DESC, id DESC LIMIT 1", [$reportId]);
    }

    // ------------------------------------------------------------------ Creating

    /** Opens the employee's report for the period containing $date, creating the draft the first time. */
    public static function openOrCreate(int $employeeId, string $period, string $date, ?int $userId): array
    {
        if (!isset(ReportCatalog::PERIODS[$period])) {
            throw new ValidationException('Unknown reporting period.');
        }
        [$start, $end] = ReportPeriod::bounds($period, $date);
        if ($start > uk_today()) {
            throw new ValidationException('You cannot start a report for a future period.');
        }
        if ($end < ReportPeriod::EARLIEST) {
            throw new ValidationException('Reports start from ' . uk_date(ReportPeriod::EARLIEST) . ', so there is nothing to report for an earlier period.');
        }
        if ($end < (string) (ReportAccess::person($employeeId)['start_date'] ?? '')) {
            throw new ValidationException('That period is before your start date (' . uk_date(ReportAccess::person($employeeId)['start_date']) . '), so there is nothing to report.');
        }
        if ($existing = self::forPeriod($employeeId, $period, $start)) {
            return $existing;
        }
        // A missed report the rota expected (a shift day, or a week or month with shifts) can always be completed;
        // any other earlier period needs backdated report access.
        $missed = $end < uk_today() && in_array($start, ReportPeriod::expected([$employeeId], $period, $start, $start)[$employeeId] ?? [], true);
        if ($end < uk_today() && !$missed && !($userId !== null && DB::value('SELECT can_backdate_reports FROM users WHERE id = ? AND is_active = 1', [$userId]))) {
            throw new ValidationException('You do not have access to start reports for an earlier date. Please ask HR. You can still complete reports already marked Overdue.');
        }
        $role = ReportAccess::roleOf($employeeId) ?? throw new ValidationException('Your job title is not one of the approved reporting roles, so there is no report form for you yet. Please ask HR to update your job title.');
        $template = self::templateFor($role, $period) ?? throw new ValidationException('The reporting template for your role is not available.');
        $id = DB::transaction(function () use ($template, $employeeId, $period, $start, $end, $userId, $missed) {
            $p = ReportAccess::person($employeeId);
            $due = ReportPeriod::dueAt($period, $end);
            // A report started for a period that ended before today (only people with backdated report access can do this).
            // It is recorded internally but looks and travels exactly like a normal report.
            // A missed expected report is not backdated: it is simply late.
            $backdated = $end < uk_today() && !$missed;
            $id = DB::insert('reports', [
                'template_id' => (int) $template['id'], 'employee_id' => $employeeId, 'period' => $period, 'period_start' => $start, 'period_end' => $end,
                'status' => 'draft', 'department_id' => $p['department_id'], 'job_title_id' => $p['job_title_id'], 'line_manager_id' => ReportAccess::managerOf($employeeId),
                'due_at' => $due, 'is_backdated' => $backdated ? 1 : 0, 'created_by' => $userId, 'created_at' => now_utc(),
            ]);
            DB::update('reports', ['report_no' => self::number($period, $id)], 'id = ?', [$id]);
            AuditLogger::log($backdated ? 'report.backdated' : 'report.created', 'report', $id, sprintf('Started %s report %s for %s', $period,
                self::number($period, $id), ReportPeriod::label($period, $start)));
            return $id;
        });
        return self::find($id);
    }

    public static function number(string $period, int $id): string
    {
        return sprintf('MT-%s-%06d', strtoupper($period[0]), $id);
    }

    /**
     * A missing or unfinished report whose deadline has passed becomes Overdue (it is never approved automatically;
     * the employee can still complete and submit it, and it is marked as late). Returns the report id when changed.
     */
    public static function markOverdue(int $employeeId, string $period, string $start): ?int
    {
        $r = self::forPeriod($employeeId, $period, $start);
        if ($r === null) {
            $role = ReportAccess::roleOf($employeeId);
            $template = $role ? self::templateFor($role, $period) : null;
            if (!$template) {
                return null;
            }
            [, $end] = ReportPeriod::bounds($period, $start);
            $p = ReportAccess::person($employeeId);
            $id = DB::insert('reports', [
                'template_id' => (int) $template['id'], 'employee_id' => $employeeId, 'period' => $period, 'period_start' => $start, 'period_end' => $end,
                'status' => 'overdue', 'department_id' => $p['department_id'], 'job_title_id' => $p['job_title_id'], 'line_manager_id' => ReportAccess::managerOf($employeeId),
                'due_at' => ReportPeriod::dueAt($period, $end), 'created_at' => now_utc(),
            ]);
            DB::update('reports', ['report_no' => self::number($period, $id)], 'id = ?', [$id]);
        } elseif ($r['status'] === 'draft') {
            $id = (int) $r['id'];
            DB::update('reports', ['status' => 'overdue'], 'id = ?', [$id]);
        } else {
            return null;
        }
        AuditLogger::log('report.overdue', 'report', $id, sprintf('%s report for %s is overdue', ucfirst($period), ReportPeriod::label($period, $start)), null, null);
        return $id;
    }

    // ------------------------------------------------------------------ Calculations

    /**
     * Values of the calculated fields, from the employee's approved daily reports in the period (and, for a
     * monthly report, their approved weekly reports that start in the month).
     * @return array{values: array<string,?float>, daily: array{approved:int,pending:int}, weekly: array{approved:int,pending:int}}
     */
    /**
     * Text answers rolled up from the reports underneath: a weekly report gets the employee's submitted daily
     * reports for the week, a monthly report their weekly reports for the month - one dated line each, for the
     * text fields with the same name. Used to pre-fill empty text fields; the employee can edit them.
     * @return array<string,string> field key => text
     */
    public static function rolledUp(array $report, array $template): array
    {
        if ($report['period'] === 'daily') {
            return [];
        }
        $source = $report['period'] === 'weekly' ? 'daily' : 'weekly';
        $rows = DB::all('SELECT period_start, data FROM reports WHERE employee_id = ? AND period = ? AND period_start BETWEEN ? AND ? AND current_version > 0 ORDER BY period_start',
            [(int) $report['employee_id'], $source, $report['period_start'], $report['period_end']]);
        $out = [];
        foreach ($template['fields'] as $f) {
            if ($f['field_type'] !== 'text') {
                continue;
            }
            $lines = [];
            foreach ($rows as $row) {
                $v = trim((string) ((json_decode((string) $row['data'], true) ?: [])['fields'][$f['field_key']] ?? ''));
                if ($v !== '') {
                    $lines[] = ReportPeriod::shortLabel($source, $row['period_start']) . ': ' . $v;
                }
            }
            if ($lines) {
                $out[$f['field_key']] = implode("\n", $lines);
            }
        }
        return $out;
    }

    /** The working answers with empty text fields pre-filled from the reports underneath (see rolledUp). */
    public static function withRollUp(array $working, array $report, array $template): array
    {
        foreach (self::rolledUp($report, $template) as $k => $v) {
            if (trim((string) ($working['fields'][$k] ?? '')) === '') {
                $working['fields'][$k] = $v;
            }
        }
        return $working;
    }

    /** Daily (or weekly) reports under a weekly (or monthly) report: how many were expected and how many submitted. */
    public static function underneath(array $report): ?array
    {
        if ($report['period'] === 'daily') {
            return null;
        }
        $source = $report['period'] === 'weekly' ? 'daily' : 'weekly';
        $id = (int) $report['employee_id'];
        $expected = ReportPeriod::expected([$id], $source, $report['period_start'], min($report['period_end'], uk_today()))[$id] ?? [];
        $submitted = DB::column('SELECT period_start FROM reports WHERE employee_id = ? AND period = ? AND period_start BETWEEN ? AND ? AND current_version > 0',
            [$id, $source, $report['period_start'], $report['period_end']]);
        return ['source' => $source, 'expected' => count($expected), 'submitted' => count($submitted), 'missing' => array_values(array_diff($expected, $submitted))];
    }

    public static function calculated(array $report, array $template): array
    {
        $out = ['values' => [], 'daily' => ['approved' => 0, 'pending' => 0], 'weekly' => ['approved' => 0, 'pending' => 0]];
        $calc = array_filter($template['fields'], fn ($f) => $f['field_type'] === 'calc');
        if (!$calc || $report['period'] === 'daily') {
            return $out;
        }
        $series = ['daily' => [], 'weekly' => []];
        foreach (['daily' => $report['period_start'], 'weekly' => $report['period_start']] as $source => $from) {
            if ($source === 'weekly' && $report['period'] !== 'monthly') {
                continue;
            }
            $rows = DB::all('SELECT r.id, r.status, r.period_start, m.field_key, m.value FROM reports r LEFT JOIN report_metrics m ON m.report_id = r.id
                              WHERE r.employee_id = ? AND r.period = ? AND r.period_start BETWEEN ? AND ? AND r.current_version > 0 ORDER BY r.period_start',
                [(int) $report['employee_id'], $source, $from, $report['period_end']]);
            $seen = [];
            foreach ($rows as $row) {
                $approved = in_array($row['status'], ReportAccess::APPROVED, true);
                if (!isset($seen[$row['id']])) {
                    $seen[$row['id']] = true;
                    $out[$source][$approved ? 'approved' : 'pending']++;
                }
                if ($approved && $row['field_key'] !== null) {
                    $series[$source][$row['field_key']][$row['period_start']] = (float) $row['value'];
                }
            }
        }
        $resolve = function (string $agg, string $key) use ($series): ?float {
            $vals = $series[str_starts_with($agg, 'w') ? 'weekly' : 'daily'][$key] ?? [];
            if (!$vals) {
                return null;
            }
            return match (ltrim($agg, 'w')) {
                'sum' => array_sum($vals),
                'avg' => array_sum($vals) / count($vals),
                'last' => $vals[max(array_keys($vals))],
            };
        };
        foreach ($calc as $f) {
            $out['values'][$f['field_key']] = ReportFormula::evaluate((string) $f['formula'], $resolve);
        }
        return $out;
    }

    /**
     * KPI totals for a set of reports of one role and period: counts and money add up; rates and
     * averages are averaged. Only reports with approved data should be passed in.
     * @param int[] $reportIds
     * @return array<int, array{key:string,label:string,display:string,value:?float,reports:int}>
     */
    public static function kpiRollup(array $reportIds, string $role, string $period): array
    {
        $template = self::templateFor($role, $period);
        if (!$template || !$reportIds) {
            return [];
        }
        $vals = [];
        foreach (DB::all('SELECT field_key, value FROM report_metrics WHERE report_id IN (' . DB::placeholders($reportIds) . ')', $reportIds) as $m) {
            $vals[$m['field_key']][] = (float) $m['value'];
        }
        $out = [];
        foreach ($template['fields'] as $f) {
            if (!$f['is_kpi']) {
                continue;
            }
            $v = $vals[$f['field_key']] ?? [];
            $out[] = ['key' => $f['field_key'], 'label' => $f['label'], 'display' => $f['display'], 'reports' => count($v),
                'value' => $v ? (in_array($f['display'], ['pct', 'dec'], true) ? array_sum($v) / count($v) : array_sum($v)) : null];
        }
        return $out;
    }

    /**
     * The team consolidation for a manager's report: each direct report's report for the same period, team
     * counts, KPI totals per role (approved data only), critical issues and the manager's ratings.
     */
    public static function team(array $report): array
    {
        $managerId = (int) $report['employee_id'];
        $members = [];
        $counts = ['team' => 0, 'expected' => 0, 'received' => 0, 'approved' => 0, 'awaiting' => 0, 'returned' => 0, 'missing' => 0];
        $byRole = [];
        $critical = [];
        $ratings = [];
        foreach (ReportAccess::directReports($managerId) as $id) {
            $p = ReportAccess::person($id);
            $counts['team']++;
            $expected = in_array($report['period_start'], ReportPeriod::expected([$id], $report['period'], $report['period_start'], $report['period_start'])[$id] ?? [], true);
            $r = self::forPeriod($id, $report['period'], $report['period_start']);
            $row = ['employee_id' => $id, 'name' => employee_name($p), 'code' => $p['employee_code'], 'job_title' => $p['job_title'], 'role' => ReportAccess::roleOf($id),
                'expected' => $expected, 'report' => null];
            $counts['expected'] += $expected ? 1 : 0;
            if ($r) {
                $decision = self::lastDecision((int) $r['id']);
                $row['report'] = ['id' => (int) $r['id'], 'report_no' => $r['report_no'], 'status' => $r['status'], 'has_critical' => (bool) $r['has_critical'],
                    'critical_note' => $r['critical_note'], 'rating' => $decision && $decision['action'] === 'approved' ? $decision['rating'] : null,
                    'comment' => $decision['comment'] ?? null, 'is_late' => (bool) $r['is_late']];
                if ((int) $r['current_version'] > 0) {
                    $counts['received']++;
                }
                if (in_array($r['status'], ReportAccess::APPROVED, true)) {
                    $counts['approved']++;
                    if ($row['role']) {
                        $byRole[$row['role']][] = (int) $r['id'];
                    }
                    if ($row['report']['rating']) {
                        $ratings[] = (int) $row['report']['rating'];
                    }
                } elseif (in_array($r['status'], ReportAccess::AWAITING, true)) {
                    $counts['awaiting']++;
                } elseif ($r['status'] === 'returned') {
                    $counts['returned']++;
                }
                if ($r['has_critical'] && (int) $r['current_version'] > 0) {
                    $critical[] = ['report_id' => (int) $r['id'], 'name' => $row['name'], 'note' => $r['critical_note']];
                }
            }
            if ($expected && (!$r || (int) $r['current_version'] === 0)) {
                $counts['missing']++;
            }
            $members[] = $row;
        }
        $roles = [];
        foreach ($byRole as $role => $ids) {
            $roles[$role] = ['title' => ReportCatalog::ROLES[$role][0], 'reports' => count($ids), 'kpis' => self::kpiRollup($ids, $role, $report['period'])];
        }
        return ['members' => $members, 'counts' => $counts, 'roles' => $roles, 'critical' => $critical,
            'avg_rating' => $ratings ? round(array_sum($ratings) / count($ratings), 1) : null, 'built_at' => now_utc()];
    }

    // ------------------------------------------------------------------ Saving and submitting

    /** Reads the form into the working-copy shape. Text is trimmed; numbers keep what was typed until validated. */
    private static function input(array $template, array $post): array
    {
        $fields = [];
        foreach ($template['fields'] as $f) {
            if ($f['field_type'] === 'calc') {
                continue;
            }
            $v = $post['fields'][$f['field_key']] ?? '';
            $fields[$f['field_key']] = is_string($v) ? trim(str_replace("\r\n", "\n", $v)) : '';
        }
        $rating = trim((string) ($post['team_rating'] ?? ''));
        return ['fields' => $fields, 'critical' => !empty($post['critical']) ? 1 : 0, 'critical_note' => trim((string) ($post['critical_note'] ?? '')),
            'assessment' => trim(str_replace("\r\n", "\n", (string) ($post['assessment'] ?? ''))), 'team_rating' => $rating === '' ? null : $rating];
    }

    /**
     * Checks the values. $final = true for a submission (required fields must be filled).
     * Returns the cleaned working copy; throws ValidationException listing every problem.
     */
    private static function validate(array $template, array $data, bool $final, bool $isManager): array
    {
        $errors = [];
        foreach ($template['fields'] as $f) {
            if ($f['field_type'] === 'calc') {
                continue;
            }
            $k = $f['field_key'];
            $v = (string) ($data['fields'][$k] ?? '');
            $name = 'fields.' . $k;
            if ($v === '') {
                if ($final && $f['is_required']) {
                    $errors[$name] = $f['label'] . ' is required.';
                }
                continue;
            }
            if ($f['field_type'] === 'text') {
                if (mb_strlen($v) > 5000) {
                    $errors[$name] = $f['label'] . ' must be no more than 5,000 characters.';
                }
                continue;
            }
            $n = str_replace([',', '£', '%', ' '], '', $v);
            $ok = match ($f['field_type']) {
                'int' => preg_match('/^\d{1,9}$/', $n) === 1,
                'money' => preg_match('/^-?\d{1,12}(\.\d{1,2})?$/', $n) === 1,
                'pct' => is_numeric($n) && (float) $n >= 0 && (float) $n <= 100,
                default => is_numeric($n) && (float) $n >= 0 && (float) $n < 1e9,
            };
            if (!$ok) {
                $errors[$name] = $f['label'] . match ($f['field_type']) {
                    'int' => ' must be a whole number (0 or more).',
                    'money' => ' must be an amount in pounds, e.g. 1250.50.',
                    'pct' => ' must be a percentage between 0 and 100.',
                    default => ' must be a number (0 or more).',
                };
                continue;
            }
            $data['fields'][$k] = $n;
        }
        if ($data['critical'] && $data['critical_note'] === '') {
            $errors['critical_note'] = 'Describe the critical issue.';
        }
        if (mb_strlen($data['critical_note']) > 500) {
            $errors['critical_note'] = 'The critical issue must be no more than 500 characters.';
        }
        if ($data['team_rating'] !== null && !in_array((string) $data['team_rating'], ['1', '2', '3', '4', '5'], true)) {
            $errors['team_rating'] = 'Choose a team performance rating from 1 to 5.';
        }
        if ($isManager && $final) {
            if (mb_strlen($data['assessment']) < 20) {
                $errors['assessment'] = 'Add your own management assessment of the team (at least 20 characters): a manager\'s report cannot just pass on the team\'s reports.';
            }
            if ($data['team_rating'] === null) {
                $errors['team_rating'] = 'Rate your team\'s performance for the period.';
            }
        }
        if (mb_strlen($data['assessment']) > 5000) {
            $errors['assessment'] = 'The management assessment must be no more than 5,000 characters.';
        }
        if ($errors) {
            throw new ValidationException($final ? 'The report could not be submitted. Please correct the highlighted fields.' : 'The draft could not be saved. Please correct the highlighted fields.', $errors);
        }
        $data['team_rating'] = $data['team_rating'] === null ? null : (int) $data['team_rating'];
        return $data;
    }

    public static function saveDraft(array $report, array $post, int $userId): void
    {
        $template = self::template((int) $report['template_id']);
        $isManager = ReportAccess::isManager((int) $report['employee_id']);
        $data = self::validate($template, self::input($template, $post), false, $isManager);
        DB::transaction(function () use ($report, $data, $isManager) {
            DB::update('reports', ['data' => json_encode($data, JSON_UNESCAPED_UNICODE), 'has_critical' => $data['critical'], 'critical_note' => $data['critical_note'] ?: null], 'id = ?', [(int) $report['id']]);
            if ($isManager) {
                self::markConsolidated($report);
            }
        });
        AuditLogger::log('report.saved', 'report', (int) $report['id'], 'Saved draft of ' . $report['report_no']);
    }

    /** Approved team reports for the same period, now included in this manager's report. */
    private static function teamReportIds(array $report, array $statuses): array
    {
        $team = ReportAccess::directReports((int) $report['employee_id']);
        if (!$team) {
            return [];
        }
        return array_map('intval', DB::column('SELECT id FROM reports WHERE period = ? AND period_start = ? AND status IN (' . DB::placeholders($statuses) . ') AND employee_id IN (' . DB::placeholders($team) . ')',
            [$report['period'], $report['period_start'], ...$statuses, ...$team]));
    }

    private static function markConsolidated(array $report): void
    {
        foreach (self::teamReportIds($report, ['approved']) as $id) {
            DB::update('reports', ['status' => 'consolidated'], 'id = ?', [$id]);
            AuditLogger::log('report.consolidated', 'report', $id, 'Consolidated into ' . $report['report_no']);
        }
    }

    public static function submit(array $report, array $post, int $userId): void
    {
        $authorId = (int) $report['employee_id'];
        $template = self::template((int) $report['template_id']);
        $isManager = ReportAccess::isManager($authorId);
        $data = self::validate($template, self::input($template, $post), true, $isManager);
        $amendment = trim((string) ($post['amendment_note'] ?? ''));
        $versionNo = (int) $report['current_version'] + 1;
        if ($versionNo > 1 && mb_strlen($amendment) < 5) {
            throw new ValidationException('Explain what you changed and why: the original report is kept and your changes are recorded as an amendment.', ['amendment_note' => 'Please describe the amendment.']);
        }
        $isTop = ReportAccess::isDirector($authorId);
        $managerId = ReportAccess::managerOf($authorId);
        if (!$isTop && $managerId === null) {
            throw new ValidationException('You do not have a line manager assigned, so the report has nowhere to go. Please ask HR to set your line manager.');
        }
        $shownAt = self::submissionTime($report, $post, $userId);

        $result = DB::transaction(function () use ($report, $template, $data, $amendment, $versionNo, $isTop, $managerId, $authorId, $userId, $isManager, $shownAt) {
            $fresh = DB::one('SELECT status, current_version FROM reports WHERE id = ? FOR UPDATE', [(int) $report['id']]);
            if (!in_array($fresh['status'], ReportAccess::EDITABLE, true) || (int) $fresh['current_version'] + 1 !== $versionNo) {
                throw new ValidationException('This report has already been submitted.');
            }
            $now = now_utc();
            $calc = self::calculated($report, $template);
            $p = ReportAccess::person($authorId);
            $team = $isManager ? self::team($report) : null;
            $snapshot = $data + ['calculated' => $calc['values'], 'calculated_sources' => ['daily' => $calc['daily'], 'weekly' => $calc['weekly']],
                'meta' => ['employee' => employee_name($p), 'employee_code' => $p['employee_code'], 'job_title' => $p['job_title'], 'department' => $p['department'],
                    'line_manager' => $managerId ? employee_name(ReportAccess::person($managerId)) : null, 'period' => ReportPeriod::label($report['period'], $report['period_start']),
                    'template' => $template['title']]];
            DB::insert('report_versions', ['report_id' => (int) $report['id'], 'version_no' => $versionNo, 'data' => json_encode($snapshot, JSON_UNESCAPED_UNICODE),
                'consolidation' => $team ? json_encode($team, JSON_UNESCAPED_UNICODE) : null, 'amendment_note' => $versionNo > 1 ? $amendment : null,
                'submitted_by' => $userId, 'submitted_at' => $shownAt]);
            DB::query('UPDATE report_attachments SET version_no = ? WHERE report_id = ? AND version_no IS NULL', [$versionNo, (int) $report['id']]);

            DB::query('DELETE FROM report_metrics WHERE report_id = ?', [(int) $report['id']]);
            foreach ($template['fields'] as $f) {
                $v = $f['field_type'] === 'calc' ? ($calc['values'][$f['field_key']] ?? null) : ($f['field_type'] === 'text' ? null : ($data['fields'][$f['field_key']] ?? ''));
                if ($v !== null && $v !== '') {
                    DB::insert('report_metrics', ['report_id' => (int) $report['id'], 'field_key' => $f['field_key'], 'value' => (string) round((float) $v, 4)]);
                }
            }
            // A report submitted after its deadline is late - except a backdated one, which is treated as a normal report.
            $late = !$report['is_backdated'] && strtotime($shownAt . ' UTC') > strtotime($report['due_at'] . ' UTC');
            DB::update('reports', [
                'status' => $isTop ? 'completed' : 'submitted', 'data' => json_encode($data, JSON_UNESCAPED_UNICODE), 'current_version' => $versionNo,
                'department_id' => $p['department_id'], 'job_title_id' => $p['job_title_id'], 'line_manager_id' => $managerId, 'escalated_to_employee_id' => null,
                'has_critical' => $data['critical'], 'critical_note' => $data['critical_note'] ?: null, 'is_late' => ((int) $report['is_late'] || $late) ? 1 : 0,
                'submitted_at' => $shownAt, 'received_at' => $now, 'decided_at' => null, 'completed_at' => $isTop ? $now : null,
            ], 'id = ?', [(int) $report['id']]);

            $children = $isManager ? self::teamReportIds($report, ['approved', 'consolidated']) : [];
            foreach ($children as $childId) {
                DB::query('INSERT IGNORE INTO report_consolidations (parent_report_id, child_report_id, created_at) VALUES (?, ?, ?)', [(int) $report['id'], $childId, $now]);
                DB::update('reports', ['status' => $isTop ? 'completed' : 'forwarded', 'forwarded_at' => $now, 'completed_at' => $isTop ? $now : null], 'id = ?', [$childId]);
                AuditLogger::log($isTop ? 'report.completed' : 'report.forwarded', 'report', $childId, ($isTop ? 'Completed in Director report ' : 'Forwarded to the next manager in ') . $report['report_no']);
            }
            if ($isTop) {
                self::completeTree((int) $report['id']);
            }
            AuditLogger::log($versionNo > 1 ? 'report.resubmitted' : 'report.submitted', 'report', (int) $report['id'],
                sprintf('%s %s (version %d)%s%s', $versionNo > 1 ? 'Resubmitted' : 'Submitted', $report['report_no'], $versionNo, $children ? ', consolidating ' . count($children) . ' team report(s)' : '', $late ? ', after the deadline' : ''),
                array_filter(['amendment' => $versionNo > 1 ? $amendment : null, 'submission_time_shown' => $shownAt !== $now ? $shownAt : null, 'recorded_at' => $shownAt !== $now ? $now : null]) ?: null);
            return ['children' => count($children), 'late' => $late];
        });

        $label = ReportCatalog::PERIODS[$report['period']] . ' report';
        $who = employee_name($report);
        $link = '/reporting/reports/' . $report['id'];
        if (!$isTop) {
            ReportNotifier::submittedToManager($managerId, self::find((int) $report['id']), ($versionNo > 1 ? 'Corrected ' : 'New ') . strtolower($label) . " from $who awaiting your approval",
                ReportPeriod::label($report['period'], $report['period_start']) . ' · ' . $report['report_no'] . ($result['late'] ? ' · submitted late' : ''));
        }
        if ($data['critical']) {
            $notify = array_unique(array_filter([$managerId, ...array_keys(array_filter(ReportAccess::people(), fn ($p, $id) => ReportAccess::isDirector($id) && $id !== $authorId, ARRAY_FILTER_USE_BOTH))]));
            foreach ($notify as $id) {
                ReportNotifier::toEmployee((int) $id, 'report_critical', "Critical issue raised by $who", mb_substr($data['critical_note'], 0, 300), $link);
            }
        }
    }

    /**
     * Whether the submitter may choose the submission date and time: on an overdue report (its deadline has
     * passed), or on a report for an earlier date sent by someone with backdated report access.
     */
    public static function canSetSubmissionTime(array $report, int $userId): bool
    {
        return $report['due_at'] < now_utc()
            || ($report['is_backdated'] && DB::value('SELECT can_backdate_reports FROM users WHERE id = ? AND is_active = 1', [$userId]));
    }

    /**
     * The submission date and time shown on the report: now, or - on an overdue report, or a report for an
     * earlier date sent by someone with backdated report access - the date and time they chose (UK time, from
     * the start of the period up to now). Lateness follows the chosen time. The real time is kept in
     * received_at and the audit log.
     */
    private static function submissionTime(array $report, array $post, int $userId): string
    {
        $now = now_utc();
        $typed = trim((string) ($post['submitted_at'] ?? ''));
        if ($typed === '') {
            return $now;
        }
        if (!self::canSetSubmissionTime($report, $userId)) {
            throw new ValidationException('The submission date and time can only be chosen on an overdue report, or by people with backdated report access on a report for an earlier date.',
                ['submitted_at' => 'You cannot set the submission time.']);
        }
        $local = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $typed, uk_tz());
        if (!$local || $local->format('Y-m-d\TH:i') !== $typed) {
            throw new ValidationException('Enter a valid submission date and time.', ['submitted_at' => 'Enter a date and time.']);
        }
        $utc = to_utc($local->format('Y-m-d H:i:00'));
        if ($utc < to_utc($report['period_start'] . ' 00:00') || $utc > $now) {
            throw new ValidationException('The submission date and time must be between the start of the report period (' . uk_date($report['period_start']) . ') and now.',
                ['submitted_at' => 'Choose a time within the period or later, and not in the future.']);
        }
        return $utc;
    }

    // ------------------------------------------------------------------ Reviewing

    /** The manager opens a submitted report: it moves to Under Review. */
    public static function opened(array $report, int $userId): void
    {
        if ($report['status'] !== 'submitted' || !ReportAccess::canReview($report)) {
            return;
        }
        DB::update('reports', ['status' => 'under_review'], 'id = ? AND status = ?', [(int) $report['id'], 'submitted']);
        DB::insert('report_reviews', ['report_id' => (int) $report['id'], 'version_no' => (int) $report['current_version'], 'reviewer_employee_id' => \App\Core\Auth::employeeId(),
            'reviewer_user_id' => $userId, 'action' => 'opened', 'created_at' => now_utc()]);
        AuditLogger::log('report.under_review', 'report', (int) $report['id'], 'Opened ' . $report['report_no'] . ' for review');
    }

    /** Approve or return for correction. A management comment is required either way. */
    public static function decide(array $report, string $action, string $comment, ?int $rating, int $userId): void
    {
        if (!ReportAccess::canReview($report)) {
            throw new ValidationException('You cannot review this report: it is not waiting for your decision, or it is your own report.');
        }
        $comment = trim($comment);
        if (!in_array($action, ['approved', 'returned'], true)) {
            throw new ValidationException('Unknown review action.');
        }
        if (mb_strlen($comment) < 3) {
            throw new ValidationException('Add your management comments before you ' . ($action === 'approved' ? 'approve' : 'return') . ' the report.', ['comment' => 'Management comments are required.']);
        }
        if ($rating !== null && ($rating < 1 || $rating > 5)) {
            throw new ValidationException('The rating must be from 1 to 5.', ['rating' => 'Choose 1 to 5.']);
        }
        $me = (int) \App\Core\Auth::employeeId();
        $top = $action === 'approved' && ReportAccess::managerOf($me) === null;
        DB::transaction(function () use ($report, $action, $comment, $rating, $userId, $me, $top) {
            $now = now_utc();
            $status = $action === 'returned' ? 'returned' : ($top ? 'completed' : 'approved');
            $n = DB::update('reports', ['status' => $status, 'decided_at' => $now, 'completed_at' => $top ? $now : null], 'id = ? AND status IN (' . DB::placeholders(ReportAccess::AWAITING) . ')',
                [(int) $report['id'], ...ReportAccess::AWAITING]);
            if ($n !== 1) {
                throw new ValidationException('This report has already been decided.');
            }
            DB::insert('report_reviews', ['report_id' => (int) $report['id'], 'version_no' => (int) $report['current_version'], 'reviewer_employee_id' => $me,
                'reviewer_user_id' => $userId, 'action' => $action, 'rating' => $action === 'approved' ? $rating : null, 'comment' => $comment, 'created_at' => $now]);
            if ($top) {
                self::completeTree((int) $report['id']);
            }
            AuditLogger::log('report.' . $action, 'report', (int) $report['id'], ($action === 'approved' ? ($top ? 'Approved and completed ' : 'Approved ') : 'Returned for correction ') . $report['report_no'],
                ['comment' => mb_substr($comment, 0, 500), 'rating' => $rating]);
        });
        $label = ReportCatalog::PERIODS[$report['period']] . ' report ' . $report['report_no'];
        ReportNotifier::toEmployee((int) $report['employee_id'], $action === 'approved' ? 'report_approved' : 'report_returned',
            $action === 'approved' ? "Your $label was approved" : "Your $label was returned for correction", mb_substr($comment, 0, 300), '/reporting/reports/' . $report['id']);
    }

    /**
     * Sends the decision to the next manager up (by the current decider, or by the scheduler when nobody
     * decided in time). Returns false when there is no one above.
     */
    public static function escalate(array $report, string $comment, ?int $userId, bool $auto = false): bool
    {
        $current = ReportAccess::decider($report);
        if ($current === null || (!$auto && !ReportAccess::canReview($report))) {
            throw new ValidationException('You cannot escalate this report.');
        }
        $next = ReportAccess::managerOf($current);
        if ($next === null || $next === (int) $report['employee_id']) {
            if ($auto) {
                return false;
            }
            throw new ValidationException('There is no manager above you to escalate to.');
        }
        $comment = trim($comment);
        if (!$auto && mb_strlen($comment) < 3) {
            throw new ValidationException('Say why you are escalating the report.', ['comment' => 'Management comments are required.']);
        }
        DB::transaction(function () use ($report, $comment, $userId, $auto, $current, $next) {
            DB::update('reports', ['status' => 'escalated', 'escalated_to_employee_id' => $next], 'id = ?', [(int) $report['id']]);
            DB::insert('report_reviews', ['report_id' => (int) $report['id'], 'version_no' => (int) $report['current_version'], 'reviewer_employee_id' => $auto ? null : $current,
                'reviewer_user_id' => $userId, 'action' => $auto ? 'auto_escalated' : 'escalated', 'comment' => $comment ?: null, 'created_at' => now_utc()]);
            AuditLogger::log('report.escalated', 'report', (int) $report['id'], ($auto ? 'Automatically escalated ' : 'Escalated ') . $report['report_no'] . ' to ' . employee_name(ReportAccess::person($next)),
                ['from' => $current, 'to' => $next], $userId);
        });
        $who = employee_name($report);
        $link = '/reporting/reports/' . $report['id'];
        ReportNotifier::toEmployee($next, 'report_escalated', "Escalated: {$report['report_no']} from $who needs your decision",
            $auto ? 'It was not reviewed within ' . (int) setting('report_escalate_after_days', 2) . ' days.' : mb_substr($comment, 0, 300), $link);
        if ($auto) {
            ReportNotifier::toEmployee($current, 'report_escalated', "{$report['report_no']} from $who was escalated to your manager", 'It waited too long for your review.', $link);
        }
        return true;
    }

    /** Marks a report and every report consolidated beneath it as Completed (the chain reached the top). */
    private static function completeTree(int $reportId): void
    {
        $now = now_utc();
        $queue = [$reportId];
        $seen = [];
        while ($queue) {
            $id = array_shift($queue);
            if (isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            if (DB::update('reports', ['status' => 'completed', 'completed_at' => $now], "id = ? AND status <> 'completed'", [$id]) && $id !== $reportId) {
                AuditLogger::log('report.completed', 'report', $id, 'Completed: the report chain reached a Director');
            }
            array_push($queue, ...array_map('intval', DB::column('SELECT child_report_id FROM report_consolidations WHERE parent_report_id = ?', [$id])));
        }
    }

    // ------------------------------------------------------------------ Attachments

    public static function addAttachment(array $report, ?array $file, int $userId): void
    {
        if ((int) DB::value('SELECT COUNT(*) FROM report_attachments WHERE report_id = ?', [(int) $report['id']]) >= self::MAX_ATTACHMENTS) {
            throw new ValidationException('A report can have at most ' . self::MAX_ATTACHMENTS . ' attachments.');
        }
        [$ext, $mime, $size] = FileStore::validateUpload($file, self::ATTACHMENT_TYPES, 10 * 1024 * 1024);
        $key = FileStore::store((string) $file['tmp_name'], 'reports', $ext);
        $name = mb_substr(preg_replace('/[^\w .()-]+/u', '_', (string) $file['name']), 0, 200);
        $id = DB::insert('report_attachments', ['report_id' => (int) $report['id'], 'storage_key' => $key, 'original_filename' => $name, 'mime_type' => $mime,
            'size_bytes' => $size, 'uploaded_by' => $userId, 'created_at' => now_utc()]);
        AuditLogger::log('report.attachment_added', 'report', (int) $report['id'], "Attached \"$name\" to " . $report['report_no'], ['attachment_id' => $id]);
    }

    /** Only attachments not yet submitted can be removed; submitted ones stay with their version. */
    public static function removeAttachment(array $report, int $attachmentId): void
    {
        $a = DB::one('SELECT * FROM report_attachments WHERE id = ? AND report_id = ? AND version_no IS NULL', [$attachmentId, (int) $report['id']])
            ?? throw new ValidationException('Submitted attachments cannot be removed.');
        DB::query('DELETE FROM report_attachments WHERE id = ?', [$attachmentId]);
        if (FileStore::exists($a['storage_key'])) {
            @unlink(FileStore::path($a['storage_key']));
        }
        AuditLogger::log('report.attachment_removed', 'report', (int) $report['id'], "Removed \"{$a['original_filename']}\" from " . $report['report_no']);
    }

    // ------------------------------------------------------------------ Management actions

    /** People an action on this report may be given to: the author, the person raising it and those below them. */
    public static function actionAssignees(array $report): array
    {
        $me = \App\Core\Auth::employeeId();
        $ids = [(int) $report['employee_id']];
        if ($me !== null) {
            $ids = [...$ids, $me, ...ReportAccess::subordinates($me)];
        }
        if (ReportAccess::seesCompany()) {
            $ids = array_keys(ReportAccess::people());
        }
        $people = ReportAccess::people();
        $out = [];
        foreach (array_unique($ids) as $id) {
            if (isset($people[$id])) {
                $out[$id] = employee_name($people[$id]) . ' · ' . $people[$id]['employee_code'];
            }
        }
        asort($out);
        return $out;
    }

    public static function raiseAction(array $report, array $post, int $userId): int
    {
        if (!ReportAccess::canRaiseActions($report)) {
            throw new ValidationException('You cannot raise actions on this report.');
        }
        $title = trim((string) ($post['title'] ?? ''));
        $assignee = (int) ($post['assigned_to'] ?? 0);
        $priority = (string) ($post['priority'] ?? 'medium');
        $due = trim((string) ($post['due_date'] ?? ''));
        $errors = [];
        if ($title === '' || mb_strlen($title) > 200) {
            $errors['title'] = 'Give the action a short title (up to 200 characters).';
        }
        if (!isset(self::actionAssignees($report)[$assignee])) {
            $errors['assigned_to'] = 'Choose who the action is for.';
        }
        if (!in_array($priority, ['low', 'medium', 'high', 'critical'], true)) {
            $errors['priority'] = 'Choose a priority.';
        }
        if ($due !== '' && !\App\Core\Validator::isDate($due)) {
            $errors['due_date'] = 'Enter a valid date.';
        }
        if ($errors) {
            throw new ValidationException('The action could not be added.', $errors);
        }
        $id = DB::insert('management_actions', ['report_id' => (int) $report['id'], 'raised_by_employee_id' => \App\Core\Auth::employeeId(), 'raised_by_user_id' => $userId,
            'assigned_to_employee_id' => $assignee, 'title' => $title, 'details' => mb_substr(trim((string) ($post['details'] ?? '')), 0, 5000) ?: null,
            'priority' => $priority, 'due_date' => $due ?: null, 'created_at' => now_utc()]);
        AuditLogger::log('report.action_raised', 'management_action', $id, "Raised action \"$title\" on " . $report['report_no'], ['assigned_to' => $assignee, 'priority' => $priority]);
        ReportNotifier::toEmployee($assignee, 'report_action', 'New management action: ' . $title, ucfirst($priority) . ' priority' . ($due ? ' · due ' . uk_date($due) : '') . ' · from ' . $report['report_no'], '/reporting/actions');
        return $id;
    }

    public static function action(int $id): ?array
    {
        return DB::one('SELECT a.*, e.first_name, e.last_name, e.employee_code, r.report_no, rb.first_name AS rb_first_name, rb.last_name AS rb_last_name
                          FROM management_actions a JOIN employees e ON e.id = a.assigned_to_employee_id LEFT JOIN reports r ON r.id = a.report_id
                          LEFT JOIN employees rb ON rb.id = a.raised_by_employee_id WHERE a.id = ?', [$id]);
    }

    /** The assignee, the person who raised it, anyone above the assignee, Directors and admins may update an action. */
    public static function canUpdateAction(array $a): bool
    {
        $me = \App\Core\Auth::employeeId();
        return ReportAccess::seesCompany() || ($me !== null && ($me === (int) $a['assigned_to_employee_id'] || $me === (int) $a['raised_by_employee_id']
            || in_array($me, ReportAccess::chain((int) $a['assigned_to_employee_id']), true)));
    }

    public static function updateAction(array $a, string $status, string $note, int $userId): void
    {
        if (!self::canUpdateAction($a)) {
            throw new ValidationException('You cannot update this action.');
        }
        if (!in_array($status, ['open', 'in_progress', 'done', 'cancelled'], true)) {
            throw new ValidationException('Choose a status.');
        }
        if (in_array($status, ['done', 'cancelled'], true) && trim($note) === '') {
            throw new ValidationException('Say what was done (or why it was cancelled).', ['resolution_note' => 'A note is required.']);
        }
        DB::update('management_actions', ['status' => $status, 'resolution_note' => trim($note) !== '' ? mb_substr(trim($note), 0, 1000) : $a['resolution_note'],
            'completed_at' => in_array($status, ['done', 'cancelled'], true) ? now_utc() : null], 'id = ?', [(int) $a['id']]);
        AuditLogger::log('report.action_updated', 'management_action', (int) $a['id'], "Action \"{$a['title']}\" is now " . str_replace('_', ' ', $status), ['status' => [$a['status'], $status], 'note' => $note]);
        if ($a['raised_by_employee_id'] && (int) $a['raised_by_employee_id'] !== \App\Core\Auth::employeeId() && in_array($status, ['done', 'cancelled'], true)) {
            ReportNotifier::toEmployee((int) $a['raised_by_employee_id'], 'report_action', "Action \"{$a['title']}\" is " . ($status === 'done' ? 'done' : 'cancelled'), mb_substr($note, 0, 300), '/reporting/actions');
        }
    }
}
