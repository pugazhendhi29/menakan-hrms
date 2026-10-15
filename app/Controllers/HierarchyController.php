<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Request;
use App\Core\Response;
use App\Services\AuditLogger;
use App\Services\OrgChart;
use App\Services\OrgChartPdf;

/**
 * "Menakan Transport Organizational Structure": the reporting tree built from each employee's
 * reporting manager. Everyone signed in can view it; only admins get links to employee
 * records and to change reporting lines (which stay on the existing admin-only routes).
 */
final class HierarchyController extends Controller
{
    public function index(Request $request): Response
    {
        $people = OrgChart::people();
        $byId = [];
        $managerOf = [];
        foreach ($people as $p) {
            $byId[(int) $p['id']] = $p;
            // Directors report to the company itself, so a search never climbs above them.
            $managerOf[(int) $p['id']] = $p['line_manager_id'] !== null && !OrgChart::isDirector($p['designation']) ? (int) $p['line_manager_id'] : null;
        }
        $tree = OrgChart::build($people);

        $q = mb_strtolower($request->str('q'));
        $dept = $request->int('department_id');
        $filtering = $q !== '' || $dept;
        $matches = [];
        if ($filtering) {
            foreach ($people as $p) {
                $name = mb_strtolower($p['first_name'] . ' ' . $p['last_name'] . ' ' . ($p['preferred_name'] ?? '') . ' ' . $p['employee_code']);
                if (($q === '' || str_contains($name, $q)) && (!$dept || (int) $p['department_id'] === $dept)) {
                    $matches[(int) $p['id']] = true;
                }
            }
        }
        $visible = $filtering ? OrgChart::withAncestors(array_keys($matches), array_map(fn ($m) => $m !== null && isset($byId[$m]) ? $m : null, $managerOf)) : null;

        $depth = 0;
        $walk = function (int $id, int $d) use (&$walk, &$depth, $tree) {
            $depth = max($depth, $d);
            foreach ($tree['children'][$id] ?? [] as $c) {
                $walk($c, $d + 1);
            }
        };
        foreach ($tree['top'] as $r) {
            $walk($r, 1);
        }
        $stats = [
            'people' => count($people),
            'directors' => count(array_filter($tree['top'], fn ($id) => OrgChart::isDirector($byId[$id]['designation']))),
            'managers' => count($tree['children']),
            'unassigned' => count(array_filter($tree['loose'], fn ($id) => $byId[$id]['line_manager_id'] === null && !$byId[$id]['reporting_manager_name'])),
            'external' => count(array_filter($people, fn ($p) => $p['line_manager_id'] === null && $p['reporting_manager_name'])),
            'levels' => $depth,
        ];

        return view('hierarchy/index', [
            'title' => 'Menakan Transport Organizational Structure', 'company' => (string) setting('company_name', 'Menakan Transport Ltd'), 'crumb' => 'People · Hierarchy', 'byId' => $byId, 'tree' => $tree,
            'visible' => $visible, 'matches' => $matches, 'filtering' => $filtering, 'request' => $request, 'stats' => $stats,
            'departments' => $this->departments(), 'isAdmin' => Auth::isAdmin(), 'myEmployeeId' => Auth::employeeId(),
        ]);
    }

    /**
     * The organisational structure as a PDF, built from the current records. Same access as the chart
     * itself (everyone signed in). Cards carry only name, photo, designation and department.
     * The page's button fetches it in the background (Accept: application/json for errors) to show
     * progress and the result; a plain link still downloads it.
     */
    public function pdf(Request $request): Response
    {
        $format = strtoupper($request->str('format', 'auto'));
        $format = isset(OrgChartPdf::FORMATS[$format]) ? $format : 'auto';
        $fromScript = str_contains((string) ($request->server['HTTP_ACCEPT'] ?? ''), 'application/json');
        try {
            $r = OrgChartPdf::generate($format);
        } catch (\Throwable $e) {
            error_log(sprintf("[%s] Hierarchy PDF failed: %s in %s:%d", date('c'), $e->getMessage(), $e->getFile(), $e->getLine()));
            $msg = 'The PDF could not be created. Please try again in a moment; if it keeps failing, contact the HR system administrator.';
            if ($fromScript) {
                return Response::json(['error' => $msg], 500);
            }
            flash('danger', $msg);
            return redirect('/hierarchy');
        }
        AuditLogger::log('hierarchy.exported', 'hierarchy', null, sprintf('Exported the organisational structure to PDF (%s landscape, %d pages, %d people)', $r['format'], $r['pages'], $r['people']));
        $res = Response::download($r['pdf'], 'Menakan-Transport-Organizational-Structure-' . uk_today() . '.pdf', 'application/pdf');
        $res->headers['X-Pdf-Pages'] = (string) $r['pages'];
        $res->headers['X-Pdf-Format'] = $r['format'];
        return $res;
    }
}