<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\DB;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Services\AuditLogger;
use App\Services\ReportAccess;
use App\Services\ReportCatalog;
use App\Services\ReportExport;
use App\Services\ReportService;

/**
 * PDF and Excel exports. One report: anyone who may view it. Team packs: the team's manager (or a
 * Director / HR admin for any manager). Department and company packs: Directors and HR admins only.
 */
final class ReportingExportController extends Controller
{
    public function reportPdf(Request $request, int $id): Response
    {
        return $this->report($id, 'pdf');
    }

    public function reportXlsx(Request $request, int $id): Response
    {
        return $this->report($id, 'xlsx');
    }

    private function report(int $id, string $format): Response
    {
        $r = ReportService::find($id);
        if (!$r || !ReportAccess::canView($r) || !in_array($format, ['pdf', 'xlsx'], true)) {
            throw new HttpException(404);
        }
        [$content, $name] = $format === 'pdf' ? ReportExport::reportPdf($r) : ReportExport::reportXlsx($r);
        AuditLogger::log('report.exported', 'report', $id, 'Exported ' . $r['report_no'] . ' to ' . strtoupper($format));
        return Response::download($content, $name, $format === 'pdf' ? 'application/pdf' : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    }

    public function pack(Request $request): Response
    {
        $format = $request->str('format') === 'xlsx' ? 'xlsx' : 'pdf';
        $period = $request->str('period', 'weekly');
        $date = $request->str('date', uk_today());
        if (!isset(ReportCatalog::PERIODS[$period]) || !Validator::isDate($date)) {
            throw new HttpException(400);
        }
        $scope = $request->str('scope', 'team');
        $me = Auth::employeeId();
        switch ($scope) {
            case 'team':
                $managerId = ReportAccess::seesCompany() && $request->int('manager_id') ? $request->int('manager_id') : $me;
                if ($managerId === null || !ReportAccess::isManager($managerId) || (!ReportAccess::seesCompany() && $managerId !== $me)) {
                    throw new HttpException(403);
                }
                $ids = [$managerId, ...ReportAccess::subordinates($managerId)];
                $label = 'Team of ' . employee_name(ReportAccess::person($managerId));
                break;
            case 'department':
                $dept = $request->int('department_id');
                if (!ReportAccess::seesCompany() || !$dept) {
                    throw new HttpException(403);
                }
                $label = (string) (DB::value('SELECT name FROM departments WHERE id = ?', [$dept]) ?? throw new HttpException(404)) . ' department';
                $ids = array_values(array_filter(array_keys(ReportAccess::people()), fn ($id) => (int) ReportAccess::person($id)['department_id'] === $dept));
                break;
            case 'company':
                if (!ReportAccess::seesCompany()) {
                    throw new HttpException(403);
                }
                $label = 'Company (Directors)';
                $ids = array_keys(ReportAccess::people());
                break;
            default:
                throw new HttpException(404);
        }
        try {
            [$content, $name] = ReportExport::pack($format, $label, $ids, $period, $date);
        } catch (\Throwable $e) {
            error_log(sprintf('[%s] Report export failed: %s in %s:%d', date('c'), $e->getMessage(), $e->getFile(), $e->getLine()));
            flash('danger', 'The export could not be created. Please try again; if it keeps failing, contact the HR system administrator.');
            return redirect('/reporting');
        }
        AuditLogger::log('report.exported', 'report', null, sprintf('Exported the %s %s report pack (%s) to %s', $period, $scope, $label, strtoupper($format)));
        return Response::download($content, $name, $format === 'pdf' ? 'application/pdf' : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    }
}
