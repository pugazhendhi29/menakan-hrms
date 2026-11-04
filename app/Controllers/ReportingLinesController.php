<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\DB;
use App\Core\Request;
use App\Core\Response;
use App\Services\AuditLogger;
use App\Services\ReportAccess;

/**
 * HR admins set each employee's line manager, i.e. who their reports go to (admins only).
 * Rules: nobody reports to themselves, there are no loops (A -> B -> A), Directors report to no one,
 * and the manager must be a current employee. The same field is used by the employee record and the
 * organisation chart, so they always agree.
 */
final class ReportingLinesController extends Controller
{
    public function index(Request $request): Response
    {
        $people = ReportAccess::people();
        uasort($people, fn ($a, $b) => strcmp(employee_name($a), employee_name($b)));
        $issues = $this->problems(array_map(fn ($p) => $p['line_manager_id'] === null ? null : (int) $p['line_manager_id'], $people), $people);
        return view('reporting/lines', ['title' => 'Reporting Lines', 'crumb' => 'Reporting · who each employee reports to', 'people' => $people, 'issues' => $issues,
            'departments' => $this->departments(), 'dept' => $request->int('department_id')]);
    }

    public function update(Request $request): Response
    {
        $people = ReportAccess::people();
        $posted = $request->arr('manager');
        $map = [];
        $changes = [];
        foreach ($people as $id => $p) {
            $old = $p['line_manager_id'] === null ? null : (int) $p['line_manager_id'];
            $new = array_key_exists((string) $id, $posted) || array_key_exists($id, $posted)
                ? (($v = (string) ($posted[$id] ?? '')) === '' ? null : (int) $v) : $old;
            $map[$id] = $new;
            if ($new !== $old) {
                $changes[$id] = [$old, $new];
            }
        }
        if (!$changes) {
            flash('info', 'No reporting lines were changed.');
            return redirect('/reporting/lines');
        }
        $errors = $this->problems($map, $people, array_keys($changes));
        if ($errors) {
            return back_with_errors('/reporting/lines', $errors, [], 'The reporting lines were not saved: ' . implode(' ', array_slice(array_values($errors), 0, 3)));
        }
        DB::transaction(function () use ($changes, $people) {
            foreach ($changes as $id => [$old, $new]) {
                DB::update('employees', ['line_manager_id' => $new, 'reporting_manager_name' => null], 'id = ?', [$id]);
                AuditLogger::log('employee.line_manager_changed', 'employee', $id, sprintf('Reporting line: %s now reports to %s', employee_name($people[$id]),
                    $new ? employee_name($people[$new]) : 'no one'), ['line_manager_id' => [$old, $new]]);
            }
        });
        ReportAccess::flush();
        flash('success', count($changes) . ' reporting line(s) updated. Reports submitted from now on go to the new managers.');
        return redirect('/reporting/lines');
    }

    /**
     * @param array<int,?int> $map employee => line manager
     * @param int[]|null $only check only these employees (the changed ones); null = everyone (for the warnings list)
     * @return array<string,string> "manager.<id>" => problem
     */
    private function problems(array $map, array $people, ?array $only = null): array
    {
        $errors = [];
        foreach ($only ?? array_keys($map) as $id) {
            $m = $map[$id];
            $name = employee_name($people[$id]);
            $isDirector = ($people[$id]['report_role'] ?? null) === 'director';
            if ($m === null) {
                if (!$isDirector && $only === null) {
                    $errors["manager.$id"] = "$name has no line manager, so their reports have nowhere to go.";
                }
                continue;
            }
            if ($isDirector) {
                $errors["manager.$id"] = "$name is a Director: Directors are the top of the reporting chain and report to no one.";
            } elseif ($m === $id) {
                $errors["manager.$id"] = "$name cannot report to themselves.";
            } elseif (!isset($people[$m])) {
                $errors["manager.$id"] = "$name's manager must be a current employee.";
            } else {
                $seen = [$id => true];
                $cur = $m;
                while ($cur !== null && isset($map[$cur]) && ($people[$cur]['report_role'] ?? null) !== 'director') {
                    if (isset($seen[$cur])) {
                        $errors["manager.$id"] = "$name: this would create a loop in the reporting lines.";
                        break;
                    }
                    $seen[$cur] = true;
                    $cur = $map[$cur];
                }
            }
        }
        return $errors;
    }
}
