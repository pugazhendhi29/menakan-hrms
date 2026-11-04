<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\DB;

/**
 * Who may see and act on management reports. Everything is decided from the real reporting lines
 * (employees.line_manager_id), never from job titles - except that a Director is the top of a chain.
 *
 * View: the author; anyone above the author in their line-management chain; the manager a report was
 *       submitted or escalated to; Directors; HR admins.
 * Review (approve / return / escalate): only the manager the report is with (the line manager it was
 *       submitted to, or the manager it was escalated to) - never the author.
 */
final class ReportAccess
{
    /** Statuses whose figures count as approved data (consolidated into weekly, monthly and team reports). */
    public const APPROVED = ['approved', 'consolidated', 'forwarded', 'completed'];
    /** Statuses the author may still change. */
    public const EDITABLE = ['draft', 'overdue', 'returned'];
    /** Statuses waiting for a manager's decision. */
    public const AWAITING = ['submitted', 'under_review', 'escalated'];

    private static ?array $people = null;

    /** Current employees: id => [line_manager_id, role, status, ...]. Cached per request. */
    public static function people(): array
    {
        if (self::$people === null) {
            self::$people = [];
            foreach (DB::all("SELECT e.id, e.employee_code, e.first_name, e.last_name, e.line_manager_id, e.department_id, e.job_title_id, e.status, e.start_date,
                                     e.photo_key, j.title AS job_title, j.report_role, d.name AS department
                                FROM employees e LEFT JOIN job_titles j ON j.id = e.job_title_id LEFT JOIN departments d ON d.id = e.department_id
                               WHERE e.deleted_at IS NULL AND e.status <> 'left'") as $p) {
                self::$people[(int) $p['id']] = $p;
            }
        }
        return self::$people;
    }

    public static function flush(): void
    {
        self::$people = null;
    }

    public static function person(?int $id): ?array
    {
        return $id === null ? null : (self::people()[$id] ?? null);
    }

    public static function roleOf(?int $employeeId): ?string
    {
        $r = self::person($employeeId)['report_role'] ?? null;
        return $r !== null && isset(ReportCatalog::ROLES[$r]) ? $r : null;
    }

    public static function isDirector(?int $employeeId): bool
    {
        return self::roleOf($employeeId) === 'director';
    }

    /** The line manager reports go to; Directors are the top and report to no one. */
    public static function managerOf(int $employeeId): ?int
    {
        if (self::isDirector($employeeId)) {
            return null;
        }
        $m = self::person($employeeId)['line_manager_id'] ?? null;
        return $m !== null && isset(self::people()[(int) $m]) && (int) $m !== $employeeId ? (int) $m : null;
    }

    /** @return int[] people whose line manager is $employeeId */
    public static function directReports(int $employeeId): array
    {
        $out = [];
        foreach (self::people() as $id => $p) {
            if ($id !== $employeeId && self::managerOf($id) === $employeeId) {
                $out[] = $id;
            }
        }
        return $out;
    }

    /** @return int[] everyone below $employeeId, at every level */
    public static function subordinates(int $employeeId): array
    {
        $seen = [];
        $queue = self::directReports($employeeId);
        while ($queue) {
            $id = array_shift($queue);
            if (isset($seen[$id]) || $id === $employeeId) {
                continue;
            }
            $seen[$id] = true;
            array_push($queue, ...self::directReports($id));
        }
        return array_keys($seen);
    }

    /** @return int[] line manager, their manager, ... up to the top (loops are cut) */
    public static function chain(int $employeeId): array
    {
        $out = [];
        $id = self::managerOf($employeeId);
        while ($id !== null && !in_array($id, $out, true) && $id !== $employeeId && count($out) < 50) {
            $out[] = $id;
            $id = self::managerOf($id);
        }
        return $out;
    }

    public static function isManager(?int $employeeId): bool
    {
        return $employeeId !== null && self::directReports($employeeId) !== [];
    }

    /** Signed in as an HR admin or a Director: company-wide reporting. */
    public static function seesCompany(): bool
    {
        return Auth::isAdmin() || self::isDirector(Auth::employeeId());
    }

    /** Employee ids whose reports the current user may see; null = everyone. */
    public static function visibleEmployeeIds(): ?array
    {
        if (self::seesCompany()) {
            return null;
        }
        $me = Auth::employeeId();
        if ($me === null) {
            return [];
        }
        return [$me, ...self::subordinates($me)];
    }

    public static function canView(array $report): bool
    {
        if (self::seesCompany()) {
            return true;
        }
        $me = Auth::employeeId();
        if ($me === null) {
            return false;
        }
        $author = (int) $report['employee_id'];
        return $author === $me
            || (int) ($report['line_manager_id'] ?? 0) === $me
            || (int) ($report['escalated_to_employee_id'] ?? 0) === $me
            || in_array($me, self::chain($author), true);
    }

    /** The manager who must decide on the report now (null when it is not waiting for a decision). */
    public static function decider(array $report): ?int
    {
        if (!in_array($report['status'], self::AWAITING, true)) {
            return null;
        }
        $id = $report['escalated_to_employee_id'] ?? $report['line_manager_id'] ?? null;
        return $id === null ? null : (int) $id;
    }

    public static function canReview(array $report): bool
    {
        $me = Auth::employeeId();
        return $me !== null && $me !== (int) $report['employee_id'] && self::decider($report) === $me;
    }

    public static function canEdit(array $report): bool
    {
        return Auth::employeeId() === (int) $report['employee_id'] && in_array($report['status'], self::EDITABLE, true);
    }

    /** Raise management actions: the decider, anyone above the author, Directors and admins. */
    public static function canRaiseActions(array $report): bool
    {
        $me = Auth::employeeId();
        if (Auth::isAdmin() || ($me !== null && self::isDirector($me) && $me !== (int) $report['employee_id'])) {
            return true;
        }
        return $me !== null && $me !== (int) $report['employee_id'] && in_array($me, self::chain((int) $report['employee_id']), true);
    }
}
