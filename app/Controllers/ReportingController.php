<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Services\AuditLogger;
use App\Services\FileStore;
use App\Services\ReportAccess;
use App\Services\ReportCatalog;
use App\Services\ReportPeriod;
use App\Services\ReportService;
use App\Services\ReportStats;

/**
 * One management report: writing it (the author only), reading it (author, their management chain,
 * Directors, HR admins), and reviewing it (only the manager it is waiting for). Every check is made
 * here on the server; a report someone may not see is a 404.
 */
final class ReportingController extends Controller
{
    /** The report, or 404 when it does not exist or the current user may not see it. */
    private function report(int $id): array
    {
        $r = ReportService::find($id);
        if (!$r || !ReportAccess::canView($r)) {
            throw new HttpException(404);
        }
        return $r;
    }

    /** Opens (creating if needed) the signed-in employee's report for a period and date. */
    public function start(Request $request): Response
    {
        $me = Auth::employeeId() ?? throw new HttpException(403, 'Your account is not linked to an employee record.');
        $period = $request->str('period');
        $date = $request->str('date');
        if (!isset(ReportCatalog::PERIODS[$period]) || !Validator::isDate($date)) {
            throw new HttpException(400);
        }
        return $this->attempt($request, '/reporting/my?period=' . $period, function () use ($me, $period, $date) {
            $r = ReportService::openOrCreate($me, $period, $date, $this->userId());
            return redirect('/reporting/reports/' . $r['id'] . (ReportAccess::canEdit($r) ? '/edit' : ''));
        });
    }

    public function edit(Request $request, int $id): Response
    {
        $r = $this->report($id);
        if (!ReportAccess::canEdit($r)) {
            flash('info', 'This report can no longer be changed: it is ' . strtolower(ReportService::STATUSES[$r['status']]) . '.');
            return redirect('/reporting/reports/' . $id);
        }
        $template = ReportService::template((int) $r['template_id']);
        $isManager = ReportAccess::isManager((int) $r['employee_id']);
        return view('reporting/edit', [
            'title' => $r['template_title'], 'crumb' => 'Reporting · ' . $r['report_no'] . ' · ' . ReportPeriod::label($r['period'], $r['period_start']),
            'r' => $r, 'tpl' => $template, 'working' => ReportService::withRollUp(ReportService::data($r), $r, $template), 'underneath' => ReportService::underneath($r), 'calc' => ReportService::calculated($r, $template),
            'team' => $isManager ? ReportService::team($r) : null, 'isManager' => $isManager, 'attachments' => ReportService::attachments($id),
            'lastReturn' => $r['status'] === 'returned' ? ReportService::lastDecision($id) : null,
            'manager' => ($m = ReportAccess::managerOf((int) $r['employee_id'])) ? ReportAccess::person($m) : null,
            'isTop' => ReportAccess::isDirector((int) $r['employee_id']),
            'canSetTime' => ReportService::canSetSubmissionTime($r, $this->userId()),
        ]);
    }

    public function save(Request $request, int $id): Response
    {
        $r = $this->report($id);
        if (!ReportAccess::canEdit($r)) {
            throw new HttpException(403);
        }
        $submit = $request->str('intent') === 'submit';
        return $this->attempt($request, "/reporting/reports/$id/edit", function () use ($r, $request, $submit, $id) {
            if ($submit) {
                ReportService::submit($r, $request->post, $this->userId());
                $fresh = ReportService::find($id);
                flash('success', $fresh['status'] === 'completed'
                    ? 'Report submitted. As a Director your report completes the reporting chain.'
                    : 'Report submitted to ' . trim($fresh['m_first_name'] . ' ' . $fresh['m_last_name']) . ' for approval.');
                return redirect("/reporting/reports/$id");
            }
            ReportService::saveDraft($r, $request->post, $this->userId());
            flash('success', 'Draft saved. It has not been sent yet.');
            return redirect("/reporting/reports/$id/edit");
        });
    }

    public function show(Request $request, int $id): Response
    {
        $r = $this->report($id);
        ReportService::opened($r, $this->userId());
        $r = ReportService::find($id);
        $template = ReportService::template((int) $r['template_id']);
        $versions = ReportService::versions($id);
        $no = $request->int('version');
        $version = (int) $r['current_version'] > 0 ? ReportService::version($id, $no && $no <= (int) $r['current_version'] ? $no : null) : null;
        $prev = $version && (int) $version['version_no'] > 1 ? ReportService::version($id, (int) $version['version_no'] - 1) : null;
        if (!ReportAccess::canEdit($r) || $version) {
            AuditLogger::log('report.viewed', 'report', $id, 'Viewed ' . $r['report_no'] . ($version ? ' version ' . $version['version_no'] : ''));
        }
        return view('reporting/show', [
            'title' => $r['template_title'], 'crumb' => 'Reporting · ' . $r['report_no'] . ' · ' . ReportPeriod::label($r['period'], $r['period_start']),
            'r' => $r, 'tpl' => $template, 'version' => $version, 'previous' => $prev, 'versions' => $versions,
            'live' => $version ? null : ['data' => ReportService::data($r), 'calc' => ReportService::calculated($r, $template)],
            'reviews' => ReportService::reviews($id), 'attachments' => ReportService::attachments($id),
            'children' => ReportService::children($id), 'parents' => array_values(array_filter(ReportService::parents($id), fn ($p) => ReportAccess::canView($p))),
            'actions' => \App\Core\DB::all('SELECT a.*, e.first_name, e.last_name, e.employee_code FROM management_actions a JOIN employees e ON e.id = a.assigned_to_employee_id WHERE a.report_id = ? ORDER BY a.created_at DESC', [$id]),
            'canReview' => ReportAccess::canReview($r), 'canEdit' => ReportAccess::canEdit($r), 'canRaise' => ReportAccess::canRaiseActions($r),
            'canEscalate' => ReportAccess::canReview($r) && ($d = ReportAccess::decider($r)) !== null && ReportAccess::managerOf($d) !== null,
            'assignees' => ReportAccess::canRaiseActions($r) ? ReportService::actionAssignees($r) : [],
            'audit' => \App\Core\DB::all("SELECT a.action, a.summary, a.created_at, u.username FROM audit_logs a LEFT JOIN users u ON u.id = a.user_id
                                            WHERE a.entity_type = 'report' AND a.entity_id = ? AND a.action <> 'report.viewed' ORDER BY a.created_at, a.id", [$id]),
        ]);
    }

    public function review(Request $request, int $id): Response
    {
        $r = $this->report($id);
        $decision = $request->str('decision');
        return $this->attempt($request, "/reporting/reports/$id#review", function () use ($r, $request, $decision, $id) {
            if ($decision === 'escalated') {
                ReportService::escalate($r, $request->str('comment'), $this->userId());
                flash('success', 'Report escalated to your line manager.');
            } else {
                $rating = $request->int('rating');
                ReportService::decide($r, $decision, $request->str('comment'), $rating, $this->userId());
                flash('success', $decision === 'approved' ? 'Report approved.' : 'Report returned to ' . employee_name($r) . ' for correction.');
            }
            return redirect(Auth::employeeId() && ReportAccess::isManager(Auth::employeeId()) ? '/reporting/team?period=' . $r['period'] : "/reporting/reports/$id");
        });
    }

    public function upload(Request $request, int $id): Response
    {
        $r = $this->report($id);
        if (!ReportAccess::canEdit($r)) {
            throw new HttpException(403);
        }
        return $this->attempt($request, "/reporting/reports/$id/edit#attachments", function () use ($r, $request, $id) {
            ReportService::addAttachment($r, $request->file('file'), $this->userId());
            flash('success', 'Attachment added. It is sent with the report when you submit.');
            return redirect("/reporting/reports/$id/edit#attachments");
        });
    }

    public function removeAttachment(Request $request, int $id, int $attachmentId): Response
    {
        $r = $this->report($id);
        if (!ReportAccess::canEdit($r)) {
            throw new HttpException(403);
        }
        return $this->attempt($request, "/reporting/reports/$id/edit#attachments", function () use ($r, $attachmentId, $id) {
            ReportService::removeAttachment($r, $attachmentId);
            flash('success', 'Attachment removed.');
            return redirect("/reporting/reports/$id/edit#attachments");
        });
    }

    /** Attachment download: same access as the report itself. */
    public function attachment(Request $request, int $id): Response
    {
        $a = \App\Core\DB::one('SELECT * FROM report_attachments WHERE id = ?', [$id]) ?? throw new HttpException(404);
        $r = $this->report((int) $a['report_id']);
        if (!FileStore::exists($a['storage_key'])) {
            throw new HttpException(404);
        }
        AuditLogger::log('report.attachment_opened', 'report', (int) $r['id'], "Opened attachment \"{$a['original_filename']}\" of " . $r['report_no']);
        return Response::file(FileStore::path($a['storage_key']), $a['original_filename'], $a['mime_type'], !$request->bool('download') && $a['mime_type'] !== 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    }

    public function raiseAction(Request $request, int $id): Response
    {
        $r = $this->report($id);
        return $this->attempt($request, "/reporting/reports/$id#actions", function () use ($r, $request, $id) {
            ReportService::raiseAction($r, $request->post, $this->userId());
            flash('success', 'Management action added.');
            return redirect("/reporting/reports/$id#actions");
        });
    }

    /** Management actions: assigned to me, raised by me, and (for managers) those of my team. */
    public function actions(Request $request): Response
    {
        $me = Auth::employeeId();
        $status = $request->str('status', 'outstanding');
        $where = match ($status) {
            'done' => "a.status IN ('done','cancelled')",
            'all' => '1=1',
            default => "a.status IN ('open','in_progress')",
        };
        $params = [];
        if (!ReportAccess::seesCompany()) {
            $ids = $me === null ? [0] : [$me, ...ReportAccess::subordinates($me)];
            $where .= ' AND (a.assigned_to_employee_id IN (' . \App\Core\DB::placeholders($ids) . ') OR a.raised_by_employee_id = ?)';
            $params = [...$ids, $me ?? 0];
        }
        $rows = \App\Core\DB::all("SELECT a.*, e.first_name, e.last_name, e.employee_code, r.report_no, rb.first_name AS rb_first_name, rb.last_name AS rb_last_name
                                     FROM management_actions a JOIN employees e ON e.id = a.assigned_to_employee_id LEFT JOIN reports r ON r.id = a.report_id
                                     LEFT JOIN employees rb ON rb.id = a.raised_by_employee_id WHERE $where
                                    ORDER BY a.status IN ('done','cancelled'), FIELD(a.priority,'critical','high','medium','low'), a.due_date IS NULL, a.due_date, a.id DESC LIMIT 300", $params);
        return view('reporting/actions', ['title' => 'Management Actions', 'crumb' => 'Reporting · follow-up actions from reports', 'rows' => $rows, 'status' => $status, 'me' => $me]);
    }

    public function updateAction(Request $request, int $id): Response
    {
        $a = ReportService::action($id) ?? throw new HttpException(404);
        if (!ReportService::canUpdateAction($a)) {
            throw new HttpException(404);
        }
        return $this->attempt($request, '/reporting/actions', function () use ($a, $request) {
            ReportService::updateAction($a, $request->str('status'), $request->str('resolution_note'), $this->userId());
            flash('success', 'Action updated.');
            return redirect('/reporting/actions');
        });
    }
}
